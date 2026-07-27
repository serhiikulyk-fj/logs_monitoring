<?php

namespace Drupal\logs_monitoring\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\logs_monitoring\Heartbeat;
use Drupal\logs_monitoring\Plugin\rest\resource\CronMonitoring;

/**
 * Settings Form for logs monitoring.
 */
class LogsMonitoringSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'logs_monitoring_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'logs_monitoring.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $settings = $this->config('logs_monitoring.settings');
    $config = $settings->get('log_configs');

    // Get the number of log configs from configuration (on form load).
    // Otherwise, from in the form state(ajax request).
    if (!$form_state->get('num_lines') && count($config)) {
      $form_state->set('num_lines', count($config));
    }
    $num_lines = $form_state->get('num_lines');
    if ($num_lines === NULL) {
      $form_state->set('num_lines', 1);
      $num_lines = $form_state->get('num_lines');
    }

    // Get a list of fields that were removed.
    $removed_fields = $form_state->get('removed_fields');
    // If no fields have been removed yet we use an empty array.
    if ($removed_fields === NULL) {
      $form_state->set('removed_fields', []);
      $removed_fields = $form_state->get('removed_fields');
    }

    $form['#tree'] = TRUE;
    $form['config_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Logs monitoring configs'),
      '#prefix' => '<div id="configs-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $num_lines; $i++) {
      // Check if field was removed.
      if (in_array($i, $removed_fields)) {
        continue;
      }

      $form['config_fieldset'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Log config') . ' ' . ($i + 1),
        'path_to_logs' => [
          '#type' => 'textfield',
          '#title' => $this->t('Path to the log'),
          '#default_value' => $config[$i]['path_to_logs'] ?? '',
          '#description' => $this->t('Absolute path to the log file.'),
        ],
        'search_words' => [
          '#type' => 'textarea',
          '#title' => $this->t('Error words to search'),
          '#description' => $this->t('Each word/phrase should be from a new line.'),
          '#default_value' => $config[$i]['search_words'] ?? '',
          '#rows' => 5,
        ],
        'exclude_words' => [
          '#type' => 'textarea',
          '#title' => $this->t('Words to exclude'),
          '#description' => $this->t('Lines containing these words will be excluded. Each word/phrase should be from a new line.'),
          '#default_value' => $config[$i]['exclude_words'] ?? '',
          '#rows' => 5,
        ],
        'lines_count' => [
          '#type' => 'number',
          '#title' => $this->t('Count of lines to read'),
          '#description' => $this->t('The count of lines from the end in which the search will be performed.'),
          '#default_value' => $config[$i]['lines_count'] ?? 200,
          '#min' => 1,
        ],
        'actions' => [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => $i,
          '#submit' => ['::removeCallback'],
          '#ajax' => [
            'callback' => '::addmoreCallback',
            'wrapper' => 'configs-fieldset-wrapper',
          ],
        ],
      ];
    }

    $form['cron_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cron monitoring'),
      'cron_max_age' => [
        '#type' => 'number',
        '#title' => $this->t('Cron max age (seconds)'),
        '#description' => $this->t('The cron monitoring endpoint reports an error when cron has not completed within this many seconds. 3600 = 1 hour, 86400 = 1 day.'),
        '#default_value' => $settings->get('cron_max_age') ?: CronMonitoring::DEFAULT_CRON_MAX_AGE,
        '#min' => 1,
        '#required' => TRUE,
      ],
    ];

    $form['drush_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Drush monitoring'),
      '#description' => $this->t('The heartbeat is recorded by the <code>logs-monitoring:heartbeat</code> Drush command, which has to be scheduled in the system crontab. See the module README for the crontab line.'),
      'drush_max_age' => [
        '#type' => 'number',
        '#title' => $this->t('Drush heartbeat max age (seconds)'),
        '#description' => $this->t('The Drush monitoring endpoint reports an error when the heartbeat has not been recorded within this many seconds. Keep it comfortably above the interval the command is scheduled at, so a single missed run does not raise an alert. 3600 = 1 hour, 86400 = 1 day.'),
        '#default_value' => $settings->get('drush_max_age') ?: Heartbeat::DEFAULT_MAX_AGE,
        '#min' => 1,
        '#required' => TRUE,
      ],
    ];

    $form['actions']['add_config'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add one more'),
      '#submit' => ['::addOne'],
      '#ajax' => [
        'callback' => '::addmoreCallback',
        'wrapper' => 'configs-fieldset-wrapper',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Callback for both ajax buttons.
   *
   * Selects and returns the config's fieldset.
   */
  public function addmoreCallback(array &$form, FormStateInterface $form_state) {
    return $form['config_fieldset'];
  }

  /**
   * Submit handler for the "add-one-more" button.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $num_field = $form_state->get('num_lines');
    $form_state->set('num_lines', $num_field + 1);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "remove" button.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $indexToRemove = $trigger['#name'];

    // Remove the fieldset from $form.
    unset($form['config_fieldset'][$indexToRemove]);

    // Keep track of removed fields, so we can add new fields at the bottom.
    $removed_fields = $form_state->get('removed_fields');
    $removed_fields[] = $indexToRemove;
    $form_state->set('removed_fields', $removed_fields);

    // Rebuild form_state.
    $form_state->setRebuild();
  }

  /**
   * All config values should be filled.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (isset($trigger['#button_type']) && $trigger['#button_type'] === 'primary') {
      foreach ($form_state->getValue('config_fieldset') as $key => $config) {
        foreach ($config as $config_key => $config_value) {
          if ($config_key !== 'exclude_words' && empty($config_value)) {
            $form_state->setError($form['config_fieldset'][$key][$config_key], $this->t("Config value can't be empty"));
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('logs_monitoring.settings');

    $log_configs = $form_state->getValue('config_fieldset');
    foreach ($log_configs as &$log_config) {
      unset($log_config['actions']);
    }

    $config
      ->set('log_configs', $log_configs)
      ->set('cron_max_age', (int) $form_state->getValue(['cron_fieldset', 'cron_max_age']))
      ->set('drush_max_age', (int) $form_state->getValue(['drush_fieldset', 'drush_max_age']))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
