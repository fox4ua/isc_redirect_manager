<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class RedirectSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'isc_redirect_manager_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['isc_redirect_manager.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('isc_redirect_manager.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Увімкнути обробку правил переадресації'),
      '#description' => $this->t('Якщо вимкнено, модуль не застосовує правила переадресації на сторінках сутностей.'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory()->getEditable('isc_redirect_manager.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
