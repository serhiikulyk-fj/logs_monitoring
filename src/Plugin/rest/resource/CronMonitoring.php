<?php

// phpcs:ignore PHPCompatibility.Keywords.ForbiddenNamesAsDeclared.resourceFound
namespace Drupal\logs_monitoring\Plugin\rest\resource;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides a resource that indicates whether cron still runs to completion.
 *
 * Drupal writes the "system.cron_last" state only after every cron handler and
 * every queue has been processed, so a cron run that dies half way through
 * (fatal error, PHP timeout, killed worker) never advances it. Comparing that
 * timestamp against a configurable threshold therefore detects both "cron was
 * never triggered" and "cron is triggered but silently fails".
 *
 * @RestResource(
 *   id = "logs_monitoring_cron",
 *   label = @Translation("Cron monitoring"),
 *   uri_paths = {
 *     "canonical" = "/admin/reports/cron-monitoring"
 *   }
 * )
 */
class CronMonitoring extends ResourceBase {

  /**
   * Threshold in seconds used when none is configured.
   */
  const DEFAULT_CRON_MAX_AGE = 3600;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The state storage.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration,
                                    $plugin_id,
                                    $plugin_definition,
                              array $serializer_formats,
                              LoggerInterface $logger,
                              ConfigFactoryInterface $config_factory,
                              StateInterface $state,
                              TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('datetime.time')
    );
  }

  /**
   * Responds to GET requests.
   *
   * Reports how long ago cron last completed and returns 503 once that exceeds
   * the configured threshold, so an uptime monitor can alert on it.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response describing the cron health.
   */
  public function get(): JsonResponse {
    $threshold = (int) $this->configFactory
      ->get('logs_monitoring.settings')
      ->get('cron_max_age') ?: self::DEFAULT_CRON_MAX_AGE;

    $last = (int) $this->state->get('system.cron_last', 0);

    // A missing timestamp means cron has never completed on this site, in
    // which case there is no meaningful age to report.
    $age = $last > 0 ? $this->time->getRequestTime() - $last : NULL;
    $healthy = $age !== NULL && $age < $threshold;

    if ($last <= 0) {
      $status = 'never';
    }
    else {
      $status = $healthy ? 'ok' : 'stale';
    }

    $response = new JsonResponse(
      [
        'check' => 'cron',
        'status' => $status,
        'last_run' => $last,
        'age_seconds' => $age,
        'threshold' => $threshold,
      ],
      $healthy ? 200 : 503
    );

    // A health check that is served from a CDN or reverse proxy is worthless,
    // so make sure nothing in front of Drupal keeps a copy.
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

    return $response;
  }

}
