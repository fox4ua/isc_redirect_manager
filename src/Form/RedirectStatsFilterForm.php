<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Filter form for redirect statistics page.
 */
class RedirectStatsFilterForm extends FormBase {

  protected RequestStack $iscRedirectRequestStack;
  protected EntityTypeManagerInterface $iscRedirectEntityTypeManager;

  public function __construct(RequestStack $requestStack, EntityTypeManagerInterface $entityTypeManager) {
    $this->iscRedirectRequestStack = $requestStack;
    $this->iscRedirectEntityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'isc_redirect_manager_redirect_stats_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->iscRedirectRequestStack->getCurrentRequest();
    $bundle = trim((string) $request->query->get('bundle', ''));
    $q = trim((string) $request->query->get('q', ''));
    $rule_id = trim((string) $request->query->get('rule_id', ''));

    /** @var \Drupal\isc_redirect_manager\Entity\IscRedirectRule|null $current_rule */
    $current_rule = NULL;
    if ($rule_id !== '') {
      $loaded = $this->iscRedirectEntityTypeManager->getStorage('isc_redirect_rule')->load($rule_id);
      if ($loaded instanceof IscRedirectRule) {
        $current_rule = $loaded;
      }
      else {
        $rule_id = '';
      }
    }

    $bundle_options = ['' => $this->t('- Усі -')];
    foreach ($this->iscRedirectEntityTypeManager->getStorage('node_type')->loadMultiple() as $machine_name => $type) {
      $bundle_options[$machine_name] = $type->label();
    }
    foreach ($this->iscRedirectEntityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $machine_name => $type) {
      $bundle_options[$machine_name] = $type->label();
    }
    $first = ['' => $bundle_options['']];
    unset($bundle_options['']);
    asort($bundle_options);
    $bundle_options = $first + $bundle_options;

    $form['#attached']['library'][] = 'isc_redirect_manager/admin';

    $form['card'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['isc-redirect-card'],
      ],
    ];

    if ($current_rule) {
      $form['card']['current_rule'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['isc-redirect-current-rule'],
        ],
      ];
      $form['card']['current_rule']['text'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['isc-redirect-current-rule-text']],
        'title' => [
          '#markup' => '<div class="isc-redirect-current-rule-title">' . $this->t('Поточне правило: @label', ['@label' => $current_rule->label()]) . '</div>',
        ],
        'destination' => [
          '#markup' => '<div class="isc-redirect-current-rule-destination">' . $this->t('Destination: @destination', ['@destination' => $current_rule->getDestination()]) . '</div>',
        ],
      ];
      $form['card']['current_rule']['all_stats'] = [
        '#type' => 'link',
        '#title' => $this->t('Вся статистика'),
        '#url' => Url::fromRoute('isc_redirect_manager.stats'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $form['card']['row1'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['isc-redirect-grid', 'isc-redirect-grid--stats'],
      ],
    ];

    $form['card']['row1']['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Тип матеріалів / словник'),
      '#options' => $bundle_options,
      '#default_value' => $bundle,
    ];

    $form['card']['row1']['q'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Пошук'),
      '#default_value' => $q,
      '#placeholder' => $this->t('Назва правила, bundle, node ID, destination'),
    ];

    if ($rule_id !== '') {
      $form['rule_id'] = [
        '#type' => 'hidden',
        '#value' => $rule_id,
      ];
    }

    $form['card']['row2'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['isc-redirect-actions'],
      ],
    ];

    $form['card']['row2']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Застосувати фільтри'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $reset_query = [];
    if ($rule_id !== '') {
      $reset_query['rule_id'] = $rule_id;
    }
    $form['card']['row2']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Скинути'),
      '#url' => Url::fromRoute('isc_redirect_manager.stats', [], ['query' => $reset_query]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];

    $bundle = trim((string) $form_state->getValue('bundle', ''));
    $q = trim((string) $form_state->getValue('q', ''));
    $rule_id = trim((string) $form_state->getValue('rule_id', ''));

    if ($rule_id !== '') {
      $query['rule_id'] = $rule_id;
    }
    if ($bundle !== '') {
      $query['bundle'] = $bundle;
    }
    if ($q !== '') {
      $query['q'] = $q;
    }

    $form_state->setRedirect('isc_redirect_manager.stats', [], ['query' => $query]);
  }

}
