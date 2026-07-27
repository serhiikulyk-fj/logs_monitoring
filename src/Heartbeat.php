<?php

namespace Drupal\logs_monitoring;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Stores and reads the "Drush last completed successfully" heartbeat.
 */
class Heartbeat {

  /**
   * The state key holding the heartbeat payload.
   */
  const STATE_KEY = 'logs_monitoring.drush_last_ok';

  /**
   * Threshold in seconds used when none is configured.
   */
  const DEFAULT_MAX_AGE = 3600;

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
   * Constructs a Heartbeat object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state storage.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(StateInterface $state, TimeInterface $time) {
    $this->state = $state;
    $this->time = $time;
  }

  /**
   * Records a successful heartbeat.
   *
   * @param array $meta
   *   Additional diagnostic values to store alongside the timestamp.
   *
   * @return array
   *   The payload that was written.
   */
  public function record(array $meta = []): array {
    $timestamp = $this->time->getRequestTime();
    $payload = [
      'ts' => $timestamp,
      // Stored purely so the value is readable when inspected by hand.
      'iso' => gmdate('c', $timestamp),
    ] + $meta;

    $this->state->set(self::STATE_KEY, $payload);

    return $payload;
  }

  /**
   * Reads the last recorded heartbeat.
   *
   * @param bool $reset
   *   Whether to drop the static state cache first.
   *
   * @return array|null
   *   The heartbeat payload, always containing an integer "ts" key, or NULL
   *   when no heartbeat has ever been recorded.
   */
  public function read(bool $reset = FALSE): ?array {
    if ($reset) {
      $this->state->resetCache();
    }

    $value = $this->state->get(self::STATE_KEY);

    if ($value === NULL || $value === '') {
      return NULL;
    }

    // Tolerate a bare timestamp so a heartbeat written by hand with
    // "drush state:set" instead of the command is still understood.
    if (!is_array($value)) {
      $timestamp = (int) $value;
      return $timestamp > 0 ? ['ts' => $timestamp] : NULL;
    }

    if (empty($value['ts'])) {
      return NULL;
    }

    $value['ts'] = (int) $value['ts'];

    return $value;
  }

  /**
   * Deletes the recorded heartbeat.
   *
   * Useful when testing the monitoring endpoint, since a missing heartbeat is
   * reported differently from a stale one.
   */
  public function delete(): void {
    $this->state->delete(self::STATE_KEY);
  }

}
