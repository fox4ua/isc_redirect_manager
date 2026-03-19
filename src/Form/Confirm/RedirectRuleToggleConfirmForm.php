<?php

namespace Drupal\isc_redirect_manager\Form\Confirm;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Форма підтвердження увімкнення або вимкнення правила.
 */
class RedirectRuleToggleConfirmForm extends ConfirmFormBase {

  protected RedirectRuleMatcher $matcher;
  protected ?IscRedirectRule $rule = NULL;

  public function __construct(RedirectRuleMatcher $matcher) {
    $this->matcher = $matcher;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('isc_redirect_manager.matcher'));
  }

  public function getFormId(): string {
    return 'isc_redirect_manager_rule_toggle_confirm_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?IscRedirectRule $isc_redirect_rule = NULL): array {
    $this->rule = $isc_redirect_rule;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return $this->rule && $this->rule->isEnabled()
      ? (string) $this->t('Вимкнути правило переадресації "@label"?', ['@label' => $this->rule->label()])
      : (string) $this->t('Увімкнути правило переадресації "@label"?', ['@label' => $this->rule?->label() ?? '']);
  }

  public function getDescription(): string {
    if ($this->rule && !$this->rule->isEnabled()) {
      return (string) $this->t('Правило буде увімкнено лише якщо воно не конфліктує з іншим активним правилом.');
    }
    return (string) $this->t('Стан правила буде змінено одразу після підтвердження.');
  }

  public function getConfirmText(): string {
    return $this->rule && $this->rule->isEnabled()
      ? (string) $this->t('Вимкнути')
      : (string) $this->t('Увімкнути');
  }

  public function getCancelUrl(): Url {
    if ($this->rule instanceof IscRedirectRule) {
      return Url::fromRoute('isc_redirect_manager.bundle_rules', [
        'entity_type' => $this->rule->getTargetEntityType(),
        'bundle' => $this->rule->getBundle(),
      ]);
    }

    return Url::fromRoute('entity.isc_redirect_rule.collection');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->rule) {
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    if ($this->rule->isEnabled()) {
      $this->rule->set('enabled', FALSE);
      $this->rule->save();
      $this->messenger()->addStatus($this->t('Правило вимкнено.'));
    }
    else {
      if ($this->matcher->hasEnabledConflict($this->rule)) {
        $this->messenger()->addError($this->t('Це правило конфліктує з іншим активним правилом і не може бути увімкнене.'));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return;
      }
      $this->rule->set('enabled', TRUE);
      $this->rule->save();
      $this->messenger()->addStatus($this->t('Правило увімкнено.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
