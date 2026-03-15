<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\isc_redirect_manager\Service\RedirectFailureLogger;
use Drupal\isc_redirect_manager\Service\RedirectRuleDiagnosticsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative overview for redirect rules.
 *
 * This screen combines filtering, grouped listing, quick toggles and drag/drop
 * reordering so editors can manage rules without jumping between multiple
 * pages.
 */
class RedirectRuleAdminForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityFieldManagerInterface $entityFieldManager;
  protected RedirectFailureLogger $failureLogger;
  protected RedirectRuleDiagnosticsService $diagnosticsService;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, RedirectFailureLogger $failureLogger, RedirectRuleDiagnosticsService $diagnosticsService) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->failureLogger = $failureLogger;
    $this->diagnosticsService = $diagnosticsService;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('isc_redirect_manager.failure_logger'),
      $container->get('isc_redirect_manager.rule_diagnostics'),
    );
  }

  public function getFormId(): string {
    return 'isc_redirect_manager_admin_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $route_name = (string) $request->attributes->get('_route');
    $forced_entity_type = match ($route_name) {
      'isc_redirect_manager.node_rules' => 'node',
      'isc_redirect_manager.taxonomy_rules' => 'taxonomy_term',
      default => '',
    };
    $selected_bundle = (string) ($request->query->get('bundle') ?? '');
    $selected_entity_type = $forced_entity_type !== '' ? $forced_entity_type : (string) ($request->query->get('entity_type') ?? '');
    $selected_enabled = (string) ($request->query->get('enabled') ?? '');
    $search = trim((string) ($request->query->get('q') ?? ''));
    $stats = $this->failureLogger->getRuleStats();
    $can_manage = $this->currentUser()->hasPermission('manage isc redirect rules') || $this->currentUser()->hasPermission('administer isc redirect rules');
    $can_delete = $this->currentUser()->hasPermission('delete isc redirect rules') || $this->currentUser()->hasPermission('administer isc redirect rules');

    $form['#method'] = 'get';
    $form['#attached']['library'][] = 'isc_redirect_manager/admin';
    $form['help'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning'], 'style' => 'background:#fff7e6; border:1px solid #f0d79a; color:#5b4300; border-radius:16px; padding:1rem 1.25rem; margin-bottom:1.25rem;'],
      'text' => [
        '#markup' => '<p style="margin:0; color:#5b4300; font-weight:500;">' . $this->t('Правила перевіряються за зростанням ваги. Перше активне правило, що співпало, спрацьовує. Точні дублікати активних умов блокуються під час збереження і підсвічуються у списку.') . '</p>',
      ],
    ];

    $form['filters'] = [
      '#type' => 'container',
      '#tree' => FALSE,
      '#attributes' => [
        'style' => 'margin:0 0 1.25rem; padding:1.5rem; background:#fff; border:1px solid #dfe3eb; border-radius:18px; box-shadow:0 6px 18px rgba(31,42,55,.06);',
      ],
    ];
    $form['filters']['row1'] = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem 1.25rem; align-items:end; margin-bottom:1rem; width:100%;',
      ],
    ];
    $form['filters']['row2'] = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'display:flex; gap:1rem; align-items:center; flex-wrap:wrap; width:100%; margin-bottom:1rem;',
      ],
    ];
    if ($forced_entity_type === '') {
      $form['filters']['row1']['entity_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Тип сутності'),
        '#options' => ['' => $this->t('- Усі -'), 'node' => $this->t('Матеріали'), 'taxonomy_term' => $this->t('Терміни таксономії')],
        '#default_value' => $selected_entity_type,
        '#name' => 'entity_type',
      ];
    }
    else {
      $form['filters']['row1']['entity_type'] = [
        '#type' => 'value',
        '#value' => $selected_entity_type,
      ];
    }
    $bundle_title = $selected_entity_type === 'taxonomy_term' ? $this->t('Словник') : $this->t('Тип матеріалів');
    $bundle_filter_options = ['' => $this->t('- Усі -')] + $this->getBundleOptions($selected_entity_type);
    $form['filters']['row1']['bundle'] = [
      '#type' => 'select',
      '#title' => $bundle_title,
      '#options' => $bundle_filter_options,
      '#default_value' => $selected_bundle,
      '#name' => 'bundle',
      '#wrapper_attributes' => ['style' => 'width:100%;'],
    ];
    $form['filters']['row1']['enabled'] = [
      '#type' => 'select',
      '#title' => $this->t('Статус'),
      '#options' => ['' => $this->t('- Усе -'), '1' => $this->t('Увімкнено'), '0' => $this->t('Вимкнено')],
      '#default_value' => $selected_enabled,
      '#name' => 'enabled',
      '#wrapper_attributes' => ['style' => 'width:100%;'],
    ];
    $form['filters']['row1']['q'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Пошук'),
      '#default_value' => $search,
      '#name' => 'q',
      '#placeholder' => $this->t('Назва, поле, значення, місце призначення'),
      '#size' => 30,
      '#wrapper_attributes' => ['style' => 'width:100%;'],
      '#attributes' => ['style' => 'width:100%;'],
    ];
    $form['filters']['row2']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Застосувати фільтри'),
      '#submit' => ['::redirectWithFilters'],
      '#limit_validation_errors' => [],
      '#wrapper_attributes' => ['style' => 'width:auto;'],
    ];
    $reset_route = match ($route_name) {
      'isc_redirect_manager.node_rules' => 'isc_redirect_manager.node_rules',
      'isc_redirect_manager.taxonomy_rules' => 'isc_redirect_manager.taxonomy_rules',
      default => 'entity.isc_redirect_rule.collection',
    };
    $form['filters']['row2']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Скинути'),
      '#url' => Url::fromRoute($reset_route),
      '#attributes' => ['class' => ['button']],
      '#wrapper_attributes' => ['style' => 'width:auto;'],
    ];

    $rules = $this->loadRules($selected_entity_type, $selected_bundle, $selected_enabled, $search);
    $conflicts = $this->detectConflicts($rules);
    $grouped = [];
    foreach ($rules as $rule) {
      $group_key = $rule->getTargetEntityType() . ':' . $rule->getBundle();
      $grouped[$group_key][] = $rule;
    }

    $invalid_rules = [];
    $diagnostics = $this->diagnosticsService->getDiagnostics();
    foreach ($diagnostics as $diagnostic) {
      $invalid_rules[$diagnostic['rule_id']] = $diagnostic['issues'];
    }

    $summary_markup = '<p>' . $this->t('Знайдено правил: @count. Конфліктів: @conflicts.', [
      '@count' => count($rules),
      '@conflicts' => count($conflicts),
    ]) . '</p>';
    if ($invalid_rules !== []) {
      $summary_markup .= '<p><strong>' . $this->t('Увага: @count правил мають биті посилання на bundle / field / term / entity.', ['@count' => count($invalid_rules)]) . '</strong></p>';
      $items = '';
      foreach ($diagnostics as $diagnostic) {
        $items .= '<li><strong>' . Html::escape($diagnostic['label']) . '</strong>: ' . Html::escape(implode('; ', $diagnostic['issues'])) . ' — ' . Html::escape($diagnostic['destination']) . '</li>';
      }
      $form['diagnostics'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['isc-redirect-rule-diagnostics']],
        'markup' => ['#markup' => '<div><strong>' . $this->t('Діагностика проблемних правил') . '</strong></div><ul>' . $items . '</ul>'],
      ];
    }
    $form['summary'] = [
      '#markup' => $summary_markup,
    ];

    $form['groups'] = ['#type' => 'container'];
    foreach ($grouped as $group_key => $items) {
      [$group_entity_type, $bundle] = explode(':', $group_key, 2);
      $bundle_label = $this->getBundleLabel($group_entity_type, $bundle);
      $group_title = $bundle_label === $bundle
        ? $this->t('@label (@count)', ['@label' => $bundle_label, '@count' => count($items)])
        : $this->t('@label (@machine) (@count)', [
            '@label' => $bundle_label,
            '@machine' => $bundle,
            '@count' => count($items),
          ]);
      $wrapper_key = 'group_' . preg_replace('/[^a-z0-9_]+/i', '_', $group_entity_type . '_' . $bundle);
      $form['groups'][$wrapper_key] = [
        '#type' => 'details',
        '#title' => $group_title,
        '#open' => TRUE,
      ];

      $form['groups'][$wrapper_key]['table'] = [
        '#type' => 'table',
        '#header' => [
          ['data' => $this->t('Назва'), 'style' => 'width: 18%;'],
          ['data' => $this->t('Увімкнено'), 'style' => 'width: 10%;'],
          ['data' => $this->t('Поле'), 'style' => 'width: 16%;'],
          ['data' => $this->t('Значення'), 'style' => 'width: 18%;'],
          ['data' => $this->t('Місце призначення'), 'style' => 'width: 18%;'],
          ['data' => $this->t('Спрацювань'), 'style' => 'width: 7%;'],
          ['data' => $this->t('Вага'), 'style' => 'width: 5%;'],
          ['data' => $this->t('Операції'), 'style' => 'width: 8%;'],
        ],
        '#empty' => $this->t('Правил не знайдено.'),
        '#attributes' => ['style' => 'table-layout: fixed; width: 100%;'],
      ];
      if ($can_manage) {
        $form['groups'][$wrapper_key]['table']['#tabledrag'] = [[
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'isc-redirect-weight-' . $wrapper_key,
        ]];
      }

      foreach ($items as $rule) {
        $this->buildRuleRow($form['groups'][$wrapper_key]['table'], $rule, $bundle_label, $bundle, $wrapper_key, isset($conflicts[$rule->id()]), (int) ($stats[$rule->id()]['hits'] ?? 0), $can_manage, $can_delete, $invalid_rules[$rule->id()] ?? []);
      }
    }

    if ($can_manage) {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Зберегти порядок'),
        '#button_type' => 'primary',
      ];
    }

    return $form;
  }

  /**
   * Redirects filter submissions to the collection route using GET query args.
   */
  public function redirectWithFilters(array &$form, FormStateInterface $form_state): void {
    $query = [];
    $input = $form_state->getUserInput();
    foreach (['entity_type', 'bundle', 'enabled', 'q'] as $key) {
      $value = $input[$key] ?? $form_state->getValue($key);
      if (is_string($value)) {
        $value = trim($value);
      }
      if ($value !== NULL && $value !== '') {
        $query[$key] = $value;
      }
    }

    $route_name = (string) $this->getRequest()->attributes->get('_route');
    $target_route = match ($route_name) {
      'isc_redirect_manager.node_rules' => 'isc_redirect_manager.node_rules',
      'isc_redirect_manager.taxonomy_rules' => 'isc_redirect_manager.taxonomy_rules',
      default => 'entity.isc_redirect_rule.collection',
    };

    $form_state->setRedirect($target_route, [], ['query' => $query]);
  }

  /**
   * Persists new weights from the drag-and-drop table.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('manage isc redirect rules') && !$this->currentUser()->hasPermission('administer isc redirect rules')) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('isc_redirect_rule');
    $rules = $storage->loadMultiple();
    $groups = $form_state->getValue('groups') ?? [];

    foreach ($rules as $rule) {
      foreach ($groups as $group) {
        if (isset($group['table'][$rule->id()]['weight'])) {
          $rule->set('weight', (int) $group['table'][$rule->id()]['weight']);
          $rule->save();
          break;
        }
      }
    }

    $this->messenger()->addStatus($this->t('Порядок правил оновлено.'));
  }

  /**
   * Adds one table row for a redirect rule.
   */
  protected function buildRuleRow(array &$table, IscRedirectRule $rule, string $bundle_label, string $bundle_machine_name, string $wrapper_key, bool $is_conflict, int $hits, bool $can_manage, bool $can_delete, array $integrity_issues = []): void {
    $row_key = $rule->id();
    if ($can_manage) {
      $table[$row_key]['#attributes']['class'] = ['draggable'];
    }
    if ($is_conflict) {
      $table[$row_key]['#attributes']['class'][] = 'warning';
    }

    $subtitle = $bundle_label === $bundle_machine_name
      ? '<small>(' . Html::escape($bundle_machine_name) . ')</small>'
      : '<small>' . Html::escape($bundle_label) . '<br>(' . Html::escape($bundle_machine_name) . ')</small>';
    $label_markup = '<strong>' . Html::escape($rule->label()) . '</strong><br>' . $subtitle;
    if ($is_conflict) {
      $label_markup .= '<br><small><strong>' . $this->t('Конфлікт: дубльована активна умова') . '</strong></small>';
    }
    if ($integrity_issues !== []) {
      $label_markup .= '<br><small><strong>' . Html::escape(implode('; ', $integrity_issues)) . '</strong></small>';
    }

    $table[$row_key]['label'] = ['#markup' => $label_markup];
    if ($can_manage) {
      $toggle_route = $rule->isEnabled() ? 'isc_redirect_manager.rule_disable' : 'isc_redirect_manager.rule_enable';
      $query = [];
      foreach (['entity_type', 'bundle', 'enabled', 'q'] as $key) {
        $value = trim((string) $this->getRequest()->query->get($key, ''));
        if ($value !== '') {
          $query[$key] = $value;
        }
      }
      $query['destination_route'] = (string) $this->getRequest()->attributes->get('_route');
      $table[$row_key]['enabled'] = [
        '#type' => 'link',
        '#title' => $rule->isEnabled() ? $this->t('Увімк.') : $this->t('Вимк.'),
        '#url' => Url::fromRoute($toggle_route, ['isc_redirect_rule' => $rule->id()], ['query' => $query]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }
    else {
      $table[$row_key]['enabled'] = ['#plain_text' => $rule->isEnabled() ? (string) $this->t('Увімкнено') : (string) $this->t('Вимкнено')];
    }

    $field_markup = $this->buildFieldMarkup($rule);
    $table[$row_key]['field'] = ['#markup' => $field_markup];
    $table[$row_key]['value'] = ['#markup' => $this->buildValueMarkup($rule)];
    $table[$row_key]['destination'] = ['#plain_text' => $rule->getDestination()];
    $stats_query = ['rule_id' => $rule->id()];
    foreach (['entity_type', 'bundle', 'enabled', 'q'] as $key) {
      $value = trim((string) $this->getRequest()->query->get($key, ''));
      if ($value !== '') {
        $stats_query[$key] = $value;
      }
    }
    $table[$row_key]['hits'] = [
      '#type' => 'link',
      '#title' => (string) $hits,
      '#url' => Url::fromRoute('isc_redirect_manager.stats', [], ['query' => $stats_query]),
    ];
    if ($can_manage) {
      $table[$row_key]['weight'] = [
        '#type' => 'weight',
        '#default_value' => $rule->getWeight(),
        '#attributes' => ['class' => ['isc-redirect-weight-' . $wrapper_key]],
      ];
    }
    else {
      $table[$row_key]['weight'] = ['#plain_text' => (string) $rule->getWeight()];
    }

    $links = [];
    if ($can_manage) {
      $links['edit'] = [
        'title' => $this->t('Редагувати'),
        'url' => Url::fromRoute('entity.isc_redirect_rule.edit_form', ['isc_redirect_rule' => $rule->id()]),
      ];
    }
    if ($can_delete) {
      $links['delete'] = [
        'title' => $this->t('Видалити'),
        'url' => Url::fromRoute('entity.isc_redirect_rule.delete_form', ['isc_redirect_rule' => $rule->id()]),
      ];
    }
    $table[$row_key]['operations'] = ['#type' => 'operations', '#links' => $links];
  }


  /**
   * Builds the field column markup.
   */
  protected function buildFieldMarkup(IscRedirectRule $rule): string {
    $field_name = trim($rule->getFieldName());
    if ($field_name === '') {
      return (string) $this->t('Не залежить');
    }

    $label = $field_name;
    $definitions = $this->entityFieldManager->getFieldDefinitions($rule->getTargetEntityType(), $rule->getBundle());
    if (isset($definitions[$field_name])) {
      $label = (string) $definitions[$field_name]->getLabel();
    }

    return '<div>' . Html::escape($label) . '<br><small>(' . Html::escape($field_name) . ')</small></div>';
  }


  /**
   * Builds the value column markup.
   */
  protected function buildValueMarkup(IscRedirectRule $rule): string {
    $resolved = $this->resolveRuleValueParts($rule);
    $label = trim($resolved['label']);
    $value = trim($resolved['machine']);

    if ($label === '') {
      $label = $value;
    }
    if ($label === '') {
      return (string) $this->t('Не залежить');
    }
    if ($value === '' || $value === $label) {
      return '<div>' . Html::escape($label) . '</div>';
    }

    return '<div>' . Html::escape($label) . '<br><small>(' . Html::escape($value) . ')</small></div>';
  }

  /**
   * Resolves a human readable value label and machine value for a rule.
   *
   * @return array{label:string,machine:string}
   *   The resolved label and machine value.
   */
  protected function resolveRuleValueParts(IscRedirectRule $rule): array {
    $match_mode = $rule->getMatchMode();
    $match_value = trim($rule->getMatchValue());
    $match_label = trim($rule->getMatchLabel());

    if ($match_mode === 'entity_bundle') {
      if ($rule->getTargetEntityType() === 'taxonomy_term') {
        return ['label' => (string) $this->t('Всі терміни'), 'machine' => ''];
      }
      return ['label' => (string) $this->t('Усі матеріали цього типу'), 'machine' => ''];
    }

    if ($match_mode === 'entity_id') {
      if ($match_label === '') {
        $storage = $this->entityTypeManager->getStorage($rule->getTargetEntityType());
        $entity = $storage->load($rule->getTargetEntityId());
        if ($entity) {
          $match_label = $entity->label();
        }
      }
      return ['label' => $match_label !== '' ? $match_label : $match_value, 'machine' => $match_value];
    }

    if ($rule->getTargetEntityType() === 'node') {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', $rule->getBundle());
      $field_name = $rule->getFieldName();
      if (isset($definitions[$field_name])) {
        $definition = $definitions[$field_name];
        $type = $definition->getType();
        if (in_array($type, ['list_string', 'list_integer'], TRUE)) {
          $allowed = [];
          $storage = FieldStorageConfig::loadByName('node', $field_name);
          if ($storage) {
            $allowed = $storage->getSetting('allowed_values') ?? [];
          }
          $label = $allowed[$match_value] ?? ($match_label !== '' ? $match_label : $match_value);
          return ['label' => (string) $label, 'machine' => $match_value];
        }
        if ($type === 'boolean') {
          $label = match ($match_value) {
            '1' => (string) $this->t('Так'),
            '0' => (string) $this->t('Ні'),
            default => $match_label,
          };
          return ['label' => $label, 'machine' => $match_value];
        }
      }
    }

    return ['label' => $match_label !== '' ? $match_label : $match_value, 'machine' => $match_value];
  }

  /**
   * Loads and filters rules for the overview screen.
   *
   * @return \Drupal\isc_redirect_manager\Entity\IscRedirectRule[]
   */
  protected function loadRules(string $entity_type, string $bundle, string $enabled, string $search): array {
    $rules = $this->entityTypeManager->getStorage('isc_redirect_rule')->loadMultiple();
    uasort($rules, static function (IscRedirectRule $a, IscRedirectRule $b): int {
      $a_group = $a->getTargetEntityType() . ':' . $a->getBundle();
      $b_group = $b->getTargetEntityType() . ':' . $b->getBundle();
      if ($a_group === $b_group) {
        if ($a->getWeight() === $b->getWeight()) {
          return strnatcasecmp($a->label(), $b->label());
        }
        return $a->getWeight() <=> $b->getWeight();
      }
      return strnatcasecmp($a_group, $b_group);
    });

    return array_filter($rules, static function (IscRedirectRule $rule) use ($entity_type, $bundle, $enabled, $search): bool {
      if ($entity_type !== '' && $rule->getTargetEntityType() !== $entity_type) {
        return FALSE;
      }
      if ($bundle !== '' && $rule->getBundle() !== $bundle) {
        return FALSE;
      }
      if ($enabled !== '' && (int) $rule->isEnabled() !== (int) $enabled) {
        return FALSE;
      }
      if ($search !== '') {
        $haystack = mb_strtolower(implode(' ', [
          $rule->label(),
$rule->getTargetEntityType(),
          $rule->getBundle(),
          $rule->getFieldName(),
          $rule->getMatchLabel(),
          $rule->getDestination(),
        ]));
        if (!str_contains($haystack, mb_strtolower($search))) {
          return FALSE;
        }
      }
      return TRUE;
    });
  }

  /**
   * Marks duplicate active conditions so the UI can highlight them.
   */
  protected function detectConflicts(array $rules): array {
    $map = [];
    $conflicts = [];
    foreach ($rules as $rule) {
      if (!$rule->isEnabled()) {
        continue;
      }
      $key = implode('|', [
        $rule->getTargetEntityType(),
        $rule->getBundle(),
        $rule->getMatchMode(),
        $rule->getTargetEntityId(),
        $rule->getFieldName(),
        $rule->getConditionType(),
        $rule->getVocabulary(),
        $rule->getMatchValue(),
      ]);
      $map[$key][] = $rule->id();
    }
    foreach ($map as $ids) {
      if (count($ids) > 1) {
        foreach ($ids as $id) {
          $conflicts[$id] = TRUE;
        }
      }
    }
    return $conflicts;
  }

  /**
   * Returns available bundles/vocabularies sorted by label.
   */
  protected function getBundleOptions(string $entity_type = ''): array {
    if ($entity_type === 'node') {
      return $this->getNodeBundleOptions();
    }

    if ($entity_type === 'taxonomy_term') {
      return $this->getVocabularyOptions();
    }

    return [
      (string) $this->t('Матеріали') => $this->prefixBundleOptions('node', $this->getNodeBundleOptions()),
      (string) $this->t('Словники') => $this->prefixBundleOptions('taxonomy_term', $this->getVocabularyOptions()),
    ];
  }

  protected function getNodeBundleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $bundle => $entity) {
      $options[$bundle] = $entity->label();
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  protected function getVocabularyOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple() as $bundle => $entity) {
      $options[$bundle] = $entity->label();
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  protected function prefixBundleOptions(string $entity_type, array $options): array {
    $prefixed = [];
    foreach ($options as $bundle => $label) {
      $prefixed[$entity_type . ':' . $bundle] = $label;
    }
    return $prefixed;
  }

  protected function getBundleLabel(string $entity_type, string $bundle): string {
    $options = $entity_type === 'taxonomy_term' ? $this->getVocabularyOptions() : $this->getNodeBundleOptions();
    return $options[$bundle] ?? $bundle;
  }
}
