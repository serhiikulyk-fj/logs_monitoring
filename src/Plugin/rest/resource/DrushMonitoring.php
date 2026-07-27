<?php

// phpcs:ignore PHPCompatibility.Keywords.ForbiddenNamesAsDeclared.resourceFound
namespace Drupal\logs_monitoring\Plugin\rest\resource;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\logs_monitoring\Heartbeat;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides a resource that indicates whether Drush still works from the CLI.
 *
 * A web request cannot test Drush itself: shelling out from the web server
 * would run under the wrong PHP SAPI, the wrong user and the wrong
 * environment, which is precisely where CLI-only breakage hides. So the check
 * is inverted. The "logs-monitoring:heartbeat" Drush command, run from the
 * system crontab, performs the checks and records a timestamp; this endpoint
 * only reports how old that timestamp is.
 *
 * That makes the heartbeat a dead man's switch. Anything that stops scheduled
 * Drush from completing - a broken vendor directory, a CLI PHP upgrade, a
 * settings.php the cron user cannot read, database credentials that only work
 * over the web server's socket, a removed crontab entry - leaves the timestamp
 * behind and this endpoint starts answering 503.
 *
 * @RestResource(
 *   id = "logs_monitoring_drush",
 *   label = @Translation("Drush monitoring"),
 *   uri_paths = {
 *     "canonical" = "/admin/reports/drush-monitoring"
 *   }
 * )
 */
class DrushMonitoring extends ResourceBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The heartbeat storage.
   *
   * @var \Drupal\logs_monitoring\Heartbeat
   */
  protected $heartbeat;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration,
                                    $plugin_id,
                                    $plugin_definition,
                              array $serializer_formats,
                              LoggerInterface $logger,
                              ConfigFactoryInterface $config_factory,
                              Heartbeat $heartbeat,
                              TimeInterface $time,
                              AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->configFactory = $config_factory;
    $this->heartbeat = $heartbeat;
    $this->time = $time;
    $this->currentUser = $current_user;
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
      $container->get('logs_monitoring.heartbeat'),
      $container->get('datetime.time'),
      $container->get('current_user')
    );
  }

  /**
   * Responds to GET requests.
   *
   * Reports how long ago Drush last completed the health check and returns 503
   * once that exceeds the configured threshold, so an uptime monitor can alert
   * on it.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response describing the Drush health.
   */
  public function get(): JsonResponse {
    $threshold = (int) $this->configFactory
      ->get('logs_monitoring.settings')
      ->get('drush_max_age') ?: Heartbeat::DEFAULT_MAX_AGE;

    $heartbeat = $this->heartbeat->read();
    $last = $heartbeat['ts'] ?? 0;

    // A missing heartbeat means the check has never completed on this site, in
    // which case there is no meaningful age to report.
    $age = $last > 0 ? $this->time->getRequestTime() - $last : NULL;
    $healthy = $age !== NULL && $age < $threshold;

    if ($last <= 0) {
      $status = 'never';
    }
    else {
      $status = $healthy ? 'ok' : 'stale';
    }

    $data = [
      'check' => 'drush',
      'status' => $status,
      'last_run' => $last,
      'age_seconds' => $age,
      'threshold' => $threshold,
    ];

    // The recorded diagnostics name the CLI PHP version, the host and the
    // system user, which is exactly what is needed to debug a stale heartbeat
    // and exactly what should not be handed to anonymous traffic. Uptime
    // monitors only need the status code, so keep the detail for operators.
    if ($heartbeat && $this->currentUser->hasPermission('administer site configuration')) {
      unset($heartbeat['ts']);
      $data['meta'] = $heartbeat;
    }

    $response = new JsonResponse($data, $healthy ? 200 : 503);

    // A health check that is served from a CDN or reverse proxy is worthless,
    // so make sure nothing in front of Drupal keeps a copy.
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

    return $response;
  }

}
