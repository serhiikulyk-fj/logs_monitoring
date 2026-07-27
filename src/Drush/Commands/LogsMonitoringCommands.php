<?php

namespace Drupal\logs_monitoring\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\logs_monitoring\Heartbeat;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\CommandFailedException;

/**
 * Drush commands for the Logs monitoring module.
 */
class LogsMonitoringCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a LogsMonitoringCommands object.
   *
   * @param \Drupal\logs_monitoring\Heartbeat $heartbeat
   *   The heartbeat storage.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected Heartbeat $heartbeat,
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Verifies that Drush works from the CLI and records a heartbeat.
   *
   * Reaching the end of this command proves rather a lot: the Drush binary
   * executed, Drupal bootstrapped fully from the CLI context, the database
   * credentials in settings.php work for the user running the command, the
   * entity system is intact and a write reached persistent storage. The
   * heartbeat is written last, so any failure above leaves the previous
   * timestamp untouched and monitoring goes stale.
   *
   * Run it from the system crontab (directly or through the bundled
   * scripts/drush-healthcheck.sh wrapper) and point an uptime monitor at
   * /admin/reports/drush-monitoring to alert when the heartbeat gets old.
   */
  #[CLI\Command(name: 'logs-monitoring:heartbeat', aliases: ['lm:hb'])]
  #[CLI\Option(name: 'no-state', description: 'Run the checks but do not write the heartbeat. Turns the command into a read-only probe.')]
  #[CLI\Option(name: 'no-entity', description: 'Skip the entity query check. Use on sites where the entity system is intentionally unavailable.')]
  #[CLI\Usage(name: 'drush logs-monitoring:heartbeat', description: 'Run every check and record the heartbeat.')]
  #[CLI\Usage(name: 'drush --uri=example.com logs-monitoring:heartbeat -q', description: 'Record the heartbeat for one site of a multisite, quietly.')]
  #[CLI\Usage(name: 'drush logs-monitoring:heartbeat --no-state', description: 'Check without touching state, e.g. from a read-only replica.')]
  #[CLI\Usage(name: 'drush state:get logs_monitoring.drush_last_ok --format=yaml', description: 'Inspect the last recorded heartbeat.')]
  public function heartbeat(array $options = ['no-state' => FALSE, 'no-entity' => FALSE]): void {
    $started = microtime(TRUE);

    // A real query, rather than merely a successful bootstrap, is what proves
    // the database credentials work for the CLI user too. It is also the only
    // database check that is meaningful when state lives outside the database
    // (a Redis-backed keyvalue store, for instance).
    try {
      $result = $this->database->query('SELECT 1')->fetchField();
    }
    catch (\Throwable $e) {
      throw new CommandFailedException(dt('Database query failed: @message', ['@message' => $e->getMessage()]), previous: $e);
    }
    if ((string) $result !== '1') {
      throw new CommandFailedException(dt('Database query returned an unexpected result: @result', ['@result' => var_export($result, TRUE)]));
    }

    // Exercising an entity query catches a site that bootstraps but whose
    // schema, field definitions or plugin caches are broken.
    if (!$options['no-entity']) {
      try {
        $this->entityTypeManager
          ->getStorage('user')
          ->getQuery()
          ->accessCheck(FALSE)
          ->range(0, 1)
          ->execute();
      }
      catch (\Throwable $e) {
        throw new CommandFailedException(dt('Entity query failed: @message', ['@message' => $e->getMessage()]), previous: $e);
      }
    }

    $duration = (int) round((microtime(TRUE) - $started) * 1000);

    if ($options['no-state']) {
      $this->logger()->success(dt('Checks passed in @ms ms. Heartbeat not written (--no-state).', ['@ms' => $duration]));
      return;
    }

    // The write itself is the last check: it has to reach persistent storage.
    try {
      $payload = $this->heartbeat->record([
        'duration_ms' => $duration,
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'drush_version' => Drush::getVersion(),
        'hostname' => php_uname('n'),
        'os_user' => get_current_user(),
      ]);
    }
    catch (\Throwable $e) {
      throw new CommandFailedException(dt('Heartbeat write failed: @message', ['@message' => $e->getMessage()]), previous: $e);
    }

    // Read it back past the static cache so a silently discarded write (a
    // read-only database, a misconfigured cache backend) is caught here
    // instead of surfacing hours later as an unexplained stale heartbeat.
    $stored = $this->heartbeat->read(TRUE);
    if (!$stored || $stored['ts'] !== $payload['ts']) {
      throw new CommandFailedException(dt('Heartbeat was written but could not be read back. State storage is not persisting writes.'));
    }

    $this->logger()->success(dt('Heartbeat recorded at @iso (@ms ms).', [
      '@iso' => $payload['iso'],
      '@ms' => $duration,
    ]));
  }

}
