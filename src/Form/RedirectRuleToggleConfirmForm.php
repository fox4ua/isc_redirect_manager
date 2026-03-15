<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm form for enabling or disabling a rule.
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
      ? (string) $this->t('Disable redirect rule "@label"?', ['@label' => $this->rule->label()])
      : (string) $this->t('Enable redirect rule "@label"?', ['@label' => $this->rule?->label() ?? '']);
  }

  public function getDescription(): string {
    if ($this->rule && !$this->rule->isEnabled()) {
      return (string) $this->t('The rule will be enabled only if it does not conflict with another active rule.');
    }
    return (string) $this->t('The rule state will be changed immediately after confirmation.');
  }

  public function getConfirmText(): string {
    return $this->rule && $this->rule->isEnabled()
      ? (string) $this->t('Disable')
      : (string) $this->t('Enable');
  }

  public function getCancelUrl(): Url {
    $query = [];
    $request = $this->getRequest();
    foreach (['entity_type', 'bundle', 'enabled', 'q'] as $key) {
      $value = trim((string) $request->query->get($key, ''));
      if ($value !== '') {
        $query[$key] = $value;
      }
    }

    $route_name = (string) $request->query->get('destination_route', 'entity.isc_redirect_rule.collection');
    $allowed = ['entity.isc_redirect_rule.collection', 'isc_redirect_manager.node_rules', 'isc_redirect_manager.taxonomy_rules'];
    if (!in_array($route_name, $allowed, TRUE)) {
      $route_name = 'entity.isc_redirect_rule.collection';
    }

    return Url::fromRoute($route_name, [], ['query' => $query]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->rule) {
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    if ($this->rule->isEnabled()) {
      $this->rule->set('enabled', FALSE);
      $this->rule->save();
      $this->messenger()->addStatus($this->t('Rule disabled.'));
    }
    else {
      if ($this->matcher->hasEnabledConflict($this->rule)) {
        $this->messenger()->addError($this->t('This rule conflicts with another active rule and cannot be enabled.'));
        $form_state->setRedirectUrl($this->getCancelUrl());
        return;
      }
      $this->rule->set('enabled', TRUE);
      $this->rule->save();
      $this->messenger()->addStatus($this->t('Rule enabled.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
