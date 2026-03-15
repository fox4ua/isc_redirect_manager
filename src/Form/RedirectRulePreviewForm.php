<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

class RedirectRulePreviewForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected RedirectRuleMatcher $matcher;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, RedirectRuleMatcher $matcher) {
    $this->entityTypeManager = $entityTypeManager;
    $this->matcher = $matcher;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('isc_redirect_manager.matcher'));
  }

  public function getFormId(): string {
    return 'isc_redirect_manager_preview_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?IscRedirectRule $isc_redirect_rule = NULL): array {
    $form_state->set('rule_id', $isc_redirect_rule?->id());
    $form['rule'] = [
      '#markup' => '<p><strong>' . $this->t('Rule:') . '</strong> ' . ($isc_redirect_rule?->label() ?? '') . '</p>',
    ];
    $target_type = $isc_redirect_rule?->getTargetEntityType() ?? 'node';
    $form['entity'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $target_type === 'taxonomy_term' ? $this->t('Term for preview') : $this->t('Entity for preview'),
      '#target_type' => $target_type,
      '#selection_settings' => ['target_bundles' => $isc_redirect_rule ? [$isc_redirect_rule->getBundle()] : NULL],
      '#required' => TRUE,
    ];
    if ($result = $form_state->get('preview_result')) {
      $form['result'] = [
        '#type' => 'details',
        '#title' => $this->t('Preview result'),
        '#open' => TRUE,
      ];
      $form['result']['markup'] = ['#markup' => $result];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Run preview'), '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $rule = $this->entityTypeManager->getStorage('isc_redirect_rule')->load($form_state->get('rule_id'));
    $entity_type = $rule ? $rule->getTargetEntityType() : 'node';
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($form_state->getValue('entity'));

    if (!$rule || !$entity) {
      $form_state->set('preview_result', '<p>' . $this->t('Rule or node not found.') . '</p>');
      $form_state->setRebuild();
      return;
    }

    $info = $this->matcher->previewRule($rule, $entity);
    $html = '<p><strong>' . $this->t('Matched:') . '</strong> ' . ($info['matched'] ? $this->t('Yes') : $this->t('No')) . '</p>';
    $html .= '<p><strong>' . $this->t('Built destination:') . '</strong> ' . htmlspecialchars((string) ($info['built_destination'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '<p><strong>' . $this->t('Final destination:') . '</strong> ' . htmlspecialchars((string) ($info['final_destination'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '<p><strong>' . $this->t('Fallback:') . '</strong> ' . htmlspecialchars((string) ($info['fallback_destination'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';

    $form_state->set('preview_result', $html);
    $form_state->setRebuild();
  }

}
