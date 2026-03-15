<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class RedirectSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'isc_redirect_manager_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['isc_redirect_manager.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('isc_redirect_manager.settings');

    $form['debug_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Увімкнути розширене журналювання'),
      '#description' => $this->t('Записувати додаткові діагностичні повідомлення для перевірок збігів, пропусків і fallback-сценаріїв редиректу.'),
      '#default_value' => (bool) $config->get('debug_logging'),
    ];

    $form['failure_log_throttle_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Вікно тротлінгу журналу помилок (секунди)'),
      '#description' => $this->t('Однакові записи про помилки в межах цього часового вікна зберігаються лише один раз. Встановіть 0, щоб вимкнути тротлінг.'),
      '#default_value' => (int) ($config->get('failure_log_throttle_window') ?? 300),
      '#min' => 0,
      '#max' => 86400,
      '#required' => TRUE,
    ];

    $form['max_failure_log_entries'] = [
      '#type' => 'number',
      '#title' => $this->t('Максимальна кількість записів журналу помилок'),
      '#description' => $this->t('Скільки рядків зберігати в таблиці журналу помилок редиректу.'),
      '#default_value' => (int) $config->get('max_failure_log_entries') ?: 200,
      '#min' => 10,
      '#max' => 5000,
      '#required' => TRUE,
    ];

    $form['max_stat_entries'] = [
      '#type' => 'number',
      '#title' => $this->t('Максимальна кількість статистичних записів'),
      '#description' => $this->t('Скільки лічильників спрацювань правил зберігати в таблиці статистики редиректів.'),
      '#default_value' => (int) $config->get('max_stat_entries') ?: 500,
      '#min' => 10,
      '#max' => 10000,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory()->getEditable('isc_redirect_manager.settings')
      ->set('debug_logging', (bool) $form_state->getValue('debug_logging'))
      ->set('failure_log_throttle_window', (int) $form_state->getValue('failure_log_throttle_window'))
      ->set('max_failure_log_entries', (int) $form_state->getValue('max_failure_log_entries'))
      ->set('max_stat_entries', (int) $form_state->getValue('max_stat_entries'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
