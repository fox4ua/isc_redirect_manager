<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IscRedirectRuleForm extends EntityForm {
  protected EntityTypeBundleInfoInterface $bundleInfo;
  protected EntityFieldManagerInterface $fieldManager;
  protected EntityTypeManagerInterface $redirectEntityTypeManager;
  protected LanguageManagerInterface $languageManager;
  protected RedirectRuleMatcher $matcher;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->fieldManager = $container->get('entity_field.manager');
    $instance->redirectEntityTypeManager = $container->get('entity_type.manager');
    $instance->languageManager = $container->get('language_manager');
    $instance->matcher = $container->get('isc_redirect_manager.matcher');
    return $instance;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\isc_redirect_manager\Entity\IscRedirectRule $rule */
    $rule = $this->entity;

    $resolved = $this->resolveStoredRuleContext($rule);
    $entity_type = (string) ($form_state->getValue('entity_type') ?? $resolved['entity_type']);
    $bundle = (string) ($form_state->getValue('bundle') ?? $resolved['bundle']);
    $match_mode = (string) ($form_state->getValue('match_mode') ?? $resolved['match_mode']);
    $field_name = (string) ($form_state->getValue('field_name') ?? $resolved['field_name']);
    $match_value = (string) ($form_state->getValue('match_value') ?? $resolved['match_value']);
    $target_entity_id = (string) ($form_state->getValue('target_entity_id') ?? $resolved['target_entity_id']);
    $language_mode = (string) ($form_state->getValue('language_mode') ?? $rule->getLanguageMode());
    $target_langcode = (string) ($form_state->getValue('target_langcode') ?? $rule->getTargetLangcode());
    $condition_type = $entity_type === 'node' && $bundle !== '' && $field_name !== ''
      ? $this->getFieldConditionType('node', $bundle, $field_name)
      : '';

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Назва правила'),
      '#default_value' => (string) ($form_state->getValue('label') ?? $rule->label()),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $rule->id(),
      '#machine_name' => ['exists' => '\Drupal\isc_redirect_manager\Entity\IscRedirectRule::load'],
      '#disabled' => !$rule->isNew(),
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Увімкнено'),
      '#default_value' => $rule->isEnabled(),
    ];
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Тип цільової сутності'),
      '#options' => ['node' => $this->t('Матеріал'), 'taxonomy_term' => $this->t('Термін таксономії')],
      '#default_value' => $entity_type,
      '#required' => TRUE,
      '#ajax' => ['callback' => '::ajaxRefreshDependent', 'wrapper' => 'isc-redirect-dependent'],
    ];

    $form['dependent'] = ['#type' => 'container', '#attributes' => ['id' => 'isc-redirect-dependent']];

    if ($entity_type === 'node') {
      $form['dependent']['bundle'] = [
        '#type' => 'select',
        '#title' => $this->t('Тип матеріалів'),
        '#options' => $this->getBundleOptions('node'),
        '#empty_option' => $this->t('- Оберіть тип матеріалу -'),
        '#default_value' => $bundle,
        '#required' => TRUE,
        '#ajax' => ['callback' => '::ajaxRefreshDependent', 'wrapper' => 'isc-redirect-dependent'],
      ];

      if ($bundle !== '') {
        $form['dependent']['match_mode'] = [
          '#type' => 'select',
          '#title' => $this->t('Основа правила'),
          '#options' => [
            'field_value' => $this->t('За значенням поля'),
            'entity_bundle' => $this->t('Усі матеріали цього типу'),
            'entity_id' => $this->t('Конкретний матеріал'),
          ],
          '#default_value' => $match_mode,
          '#required' => TRUE,
          '#ajax' => ['callback' => '::ajaxRefreshDependent', 'wrapper' => 'isc-redirect-dependent'],
        ];
      }

      if ($bundle !== '' && $match_mode === 'field_value') {
        $field_options = $this->getSupportedFields('node', $bundle);
        $form['dependent']['field_name'] = [
          '#type' => 'select',
          '#title' => $this->t('Поле'),
          '#options' => $field_options,
          '#empty_option' => $this->t('- Оберіть поле -'),
          '#default_value' => $field_name,
          '#required' => TRUE,
          '#ajax' => ['callback' => '::ajaxRefreshDependent', 'wrapper' => 'isc-redirect-dependent'],
        ];

        if ($field_name !== '' && $condition_type !== '') {
          if ($condition_type === 'taxonomy_term') {
            $selected_vocabulary = $this->getSingleVocabularyForField('node', $bundle, $field_name);
            $form['dependent']['vocabulary'] = ['#type' => 'hidden', '#value' => $selected_vocabulary];
            $form['dependent']['match_value'] = [
              '#type' => 'select',
              '#title' => $this->t('Значення'),
              '#options' => $selected_vocabulary ? $this->getTermOptions($selected_vocabulary, FALSE) : [],
              '#empty_option' => $this->t('- Оберіть значення -'),
              '#default_value' => $match_value,
              '#required' => TRUE,
            ];
          }
          else {
            $form['dependent']['vocabulary'] = ['#type' => 'hidden', '#value' => ''];
            $form['dependent']['match_value'] = $this->buildNodeMatchValueElement($field_name, $condition_type, $match_value);
          }
        }
      }
      elseif ($bundle !== '' && $match_mode === 'entity_id') {
        $default_node = $target_entity_id !== '' ? $this->redirectEntityTypeManager->getStorage('node')->load($target_entity_id) : NULL;
        $form['dependent']['target_entity_id'] = [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Конкретний матеріал'),
          '#target_type' => 'node',
          '#selection_settings' => ['target_bundles' => [$bundle]],
          '#default_value' => $default_node,
          '#required' => TRUE,
        ];
      }
    }
    else {
      $form['dependent']['bundle'] = [
        '#type' => 'select',
        '#title' => $this->t('Словник'),
        '#options' => $this->getBundleOptions('taxonomy_term'),
        '#empty_option' => $this->t('- Оберіть словник -'),
        '#default_value' => $bundle,
        '#required' => TRUE,
        '#ajax' => ['callback' => '::ajaxRefreshDependent', 'wrapper' => 'isc-redirect-dependent'],
      ];
      $form['dependent']['field_name'] = ['#type' => 'hidden', '#value' => ''];
      $form['dependent']['vocabulary'] = ['#type' => 'hidden', '#value' => $bundle];
      $form['dependent']['match_mode'] = ['#type' => 'hidden', '#value' => $match_value === '__all__' ? 'entity_bundle' : 'entity_id'];
      if ($bundle !== '') {
        $form['dependent']['match_value'] = [
          '#type' => 'select',
          '#title' => $this->t('Термін'),
          '#options' => $this->getTermOptions($bundle, TRUE),
          '#default_value' => $match_value !== '' ? $match_value : '__all__',
          '#required' => TRUE,
        ];
      }

    }

    $form['destination'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect destination'),
      '#default_value' => (string) ($form_state->getValue('destination') ?? $rule->getDestination()),
      '#autocomplete_route_name' => 'isc_redirect_manager.destination_autocomplete',
      '#required' => TRUE,
      '#description' => $this->t('Enter a base internal path without a language prefix, for example /archive/news. The selected language mode will add the prefix automatically when needed.'),
    ];
    $form['language_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language mode'),
      '#options' => [
        'content' => $this->t('Use the language of the current entity translation'),
        'fixed' => $this->t('Always redirect to a specific language'),
        'neutral' => $this->t('Do not add a language prefix'),
      ],
      '#default_value' => $language_mode,
      '#required' => TRUE,
      '#ajax' => ['callback' => '::ajaxRefreshLanguage', 'wrapper' => 'isc-redirect-language'],
    ];
    $form['language'] = ['#type' => 'container', '#attributes' => ['id' => 'isc-redirect-language']];
    $form['language']['target_langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Target language'),
      '#options' => $this->getLanguageOptions(),
      '#empty_option' => $this->t('- Select language -'),
      '#default_value' => $target_langcode,
      '#states' => ['visible' => [':input[name="language_mode"]' => ['value' => 'fixed']]],
    ];
    $form['status_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Тип редиректу'),
      '#options' => [302 => '302 Temporary redirect', 301 => '301 Permanent redirect'],
      '#default_value' => $rule->getStatusCode(),
    ];
    $form['weight'] = ['#type' => 'weight', '#title' => $this->t('Вага'), '#default_value' => $rule->getWeight()];

    return parent::form($form, $form_state);
  }

  public function ajaxRefreshDependent(array &$form, FormStateInterface $form_state): array { return $form['dependent']; }
  public function ajaxRefreshLanguage(array &$form, FormStateInterface $form_state): array { return $form['language']; }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $entity_type = (string) $form_state->getValue('entity_type');
    $bundle = (string) $form_state->getValue('bundle');
    $field_name = (string) $form_state->getValue('field_name');
    $match_mode = (string) ($form_state->getValue('match_mode') ?? ($entity_type === 'taxonomy_term' ? 'entity_bundle' : 'field_value'));
    $match_value = (string) ($form_state->getValue('match_value') ?? '');
    $target_entity_value = $form_state->getValue('target_entity_id');
    $destination = trim((string) $form_state->getValue('destination'));
    $language_mode = (string) $form_state->getValue('language_mode');
    $target_langcode = (string) $form_state->getValue('target_langcode');

    if ($bundle === '') {
      $form_state->setErrorByName('bundle', $this->t('Оберіть набір.'));
    }

    if ($entity_type === 'node') {
      if ($match_mode === 'field_value') {
        $condition_type = $this->getFieldConditionType('node', $bundle, $field_name);
        if ($field_name === '' || $condition_type === '') {
          $form_state->setErrorByName('field_name', $this->t('Оберіть підтримуване поле.'));
        }
        if ($match_value === '') {
          $form_state->setErrorByName('match_value', $this->t('Оберіть значення.'));
        }
        $vocabulary = $condition_type === 'taxonomy_term' ? $this->getSingleVocabularyForField('node', $bundle, $field_name) : '';
        $this->validateConflicts($form_state, 'node', $bundle, 'field_value', $field_name, $condition_type, $vocabulary, $match_value);
      }
      elseif ($match_mode === 'entity_bundle') {
        $this->validateConflicts($form_state, 'node', $bundle, 'entity_bundle', '', 'entity_bundle', '', '__all__');
      }
      elseif ($match_mode === 'entity_id') {
        $target_id = $this->normalizeEntityAutocompleteValue($target_entity_value);
        if ($target_id === '') {
          $form_state->setErrorByName('target_entity_id', $this->t('Оберіть матеріал.'));
        }
        else {
          $node = $this->redirectEntityTypeManager->getStorage('node')->load($target_id);
          if (!$node || $node->bundle() !== $bundle) {
            $form_state->setErrorByName('target_entity_id', $this->t('Обраний матеріал має належати до вибраного типу.'));
          }
        }
        $this->validateConflicts($form_state, 'node', $bundle, 'entity_id', '', 'entity_id', '', $target_id);
      }
    }
    else {
      if ($bundle !== '' && $match_value === '') {
        $form_state->setErrorByName('match_value', $this->t('Select a term or choose all terms.'));
      }
      elseif ($bundle !== '' && $match_value !== '__all__') {
        $term = $this->redirectEntityTypeManager->getStorage('taxonomy_term')->load($match_value);
        if (!$term || $term->bundle() !== $bundle) {
          $form_state->setErrorByName('match_value', $this->t('Обраний термін має належати до вибраного словника.'));
        }
      }
      $taxonomy_mode = $match_value === '__all__' ? 'entity_bundle' : 'entity_id';
      $taxonomy_condition = $taxonomy_mode === 'entity_bundle' ? 'entity_bundle' : 'entity_id';
      $this->validateConflicts($form_state, 'taxonomy_term', $bundle, $taxonomy_mode, '', $taxonomy_condition, $bundle, $match_value);
    }

    if ($destination === '' || UrlHelper::isExternal($destination) || !$this->matcher->isConfigurationDestinationValid($destination, $language_mode, $target_langcode)) {
      $form_state->setErrorByName('destination', $this->t('Вкажіть коректний внутрішній шлях призначення.'));
    }
  }

  public function save(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\isc_redirect_manager\Entity\IscRedirectRule $rule */
    $rule = $this->entity;
    $entity_type = (string) $form_state->getValue('entity_type');
    $bundle = (string) $form_state->getValue('bundle');
    $match_mode = (string) ($form_state->getValue('match_mode') ?? ($entity_type === 'taxonomy_term' ? 'entity_bundle' : 'field_value'));
    $field_name = '';
    $condition_type = '';
    $vocabulary = '';
    $target_entity_id = '';
    $match_value = '';

    if ($entity_type === 'node') {
      if ($match_mode === 'field_value') {
        $field_name = (string) $form_state->getValue('field_name');
        $condition_type = $this->getFieldConditionType('node', $bundle, $field_name);
        $vocabulary = $condition_type === 'taxonomy_term' ? $this->getSingleVocabularyForField('node', $bundle, $field_name) : '';
        $match_value = (string) ($form_state->getValue('match_value') ?? '');
      }
      elseif ($match_mode === 'entity_bundle') {
        $condition_type = 'entity_bundle';
        $match_value = '__all__';
      }
      else {
        $condition_type = 'entity_id';
        $target_entity_id = $this->normalizeEntityAutocompleteValue($form_state->getValue('target_entity_id'));
        $match_value = $target_entity_id;
      }
    }
    else {
      $field_name = '';
      $vocabulary = $bundle;
      $match_value = (string) ($form_state->getValue('match_value') ?? '__all__');
      if ($match_value === '__all__') {
        $match_mode = 'entity_bundle';
        $condition_type = 'entity_bundle';
      }
      else {
        $match_mode = 'entity_id';
        $condition_type = 'entity_id';
        $target_entity_id = $match_value;
      }
    }

    $rule->set('label', $form_state->getValue('label'));
    $rule->set('enabled', (bool) $form_state->getValue('enabled'));
    $rule->set('entity_type', $entity_type);
    $rule->set('bundle', $bundle);
    $rule->set('match_mode', $match_mode);
    $rule->set('target_entity_id', $target_entity_id);
    $rule->set('field_name', $field_name);
    $rule->set('condition_type', $condition_type);
    $rule->set('vocabulary', $vocabulary);
    $rule->set('match_value', $match_value);
    $rule->set('match_label', $this->resolveMatchLabel($entity_type, $bundle, $field_name, $condition_type, $match_value, $match_mode));
    $rule->set('destination', trim((string) $form_state->getValue('destination')));
    $rule->set('language_mode', (string) $form_state->getValue('language_mode'));
    $rule->set('target_langcode', (string) $form_state->getValue('target_langcode'));
    $rule->set('status_code', (int) $form_state->getValue('status_code'));
    $rule->set('weight', (int) $form_state->getValue('weight'));

    $status = $rule->save();
    $this->messenger()->addStatus($status === SAVED_NEW ? $this->t('Правило редиректу створено.') : $this->t('Правило редиректу оновлено.'));
    $form_state->setRedirect('entity.isc_redirect_rule.collection');
  }


  /**
   * Resolves stored rule data into a UI-safe context.
   */
  protected function resolveStoredRuleContext(IscRedirectRule $rule): array {
    $entity_type = $rule->getTargetEntityType();
    $bundle = $rule->getBundle();
    $field_name = $rule->getFieldName();
    $match_mode = $rule->getMatchMode();
    $match_value = $this->getUiDefaultMatchValue($rule);
    $target_entity_id = $rule->getTargetEntityId();

    // Recover legacy taxonomy rules that may have been saved before entity_type
    // and match_mode were exported correctly.
    if ($bundle !== '' && $entity_type === 'node' && !$this->bundleExists('node', $bundle) && $this->bundleExists('taxonomy_term', $bundle)) {
      $entity_type = 'taxonomy_term';
    }

    if ($entity_type === 'taxonomy_term') {
      $field_name = '';
      if ($match_mode === 'field_value') {
        $match_mode = $match_value === '__all__' ? 'entity_bundle' : 'entity_id';
      }
      if ($match_mode === 'entity_id' && $target_entity_id === '' && $match_value !== '' && $match_value !== '__all__') {
        $target_entity_id = $match_value;
      }
      if ($match_mode === 'entity_bundle' && $match_value === '') {
        $match_value = '__all__';
      }
    }
    else {
      if ($match_mode === '') {
        $match_mode = 'field_value';
      }
    }

    return [
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'match_mode' => $match_mode,
      'field_name' => $field_name,
      'match_value' => $match_value,
      'target_entity_id' => $target_entity_id,
    ];
  }

  protected function bundleExists(string $entity_type, string $bundle): bool {
    if ($bundle === '') {
      return FALSE;
    }
    return isset($this->bundleInfo->getBundleInfo($entity_type)[$bundle]);
  }

  protected function validateConflicts(FormStateInterface $form_state, string $entity_type, string $bundle, string $match_mode, string $field_name, string $condition_type, string $vocabulary, string $match_value): void {
    $rules = $this->redirectEntityTypeManager->getStorage('isc_redirect_rule')->loadByProperties([
      'enabled' => TRUE,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'match_mode' => $match_mode,
      'field_name' => $field_name,
      'condition_type' => $condition_type,
      'vocabulary' => $vocabulary,
      'match_value' => $match_value,
    ]);
    foreach ($rules as $existing_rule) {
      if ($existing_rule->id() === $this->entity->id()) {
        continue;
      }
      $target = $match_mode === 'field_value' ? 'match_value' : ($match_mode === 'entity_id' ? 'target_entity_id' : 'bundle');
      $form_state->setErrorByName($target, $this->t('Активне правило з такою самою умовою вже існує: %label.', ['%label' => $existing_rule->label()]));
      return;
    }
  }

  protected function getBundleOptions(string $entity_type): array {
    $type = $entity_type === 'taxonomy_term' ? 'taxonomy_term' : 'node';
    $options = [];
    foreach ($this->bundleInfo->getBundleInfo($type) as $id => $info) {
      $options[$id] = $info['label'] . ' (' . $id . ')';
    }
    asort($options);
    return $options;
  }

  protected function getSupportedFields(string $entity_type, string $bundle): array {
    $options = [];
    foreach ($this->fieldManager->getFieldDefinitions($entity_type, $bundle) as $field_name => $definition) {
      if (!str_starts_with($field_name, 'field_')) {
        continue;
      }
      $type = $definition->getType();
      if ($type === 'entity_reference' && (($definition->getSettings()['target_type'] ?? '') === 'taxonomy_term')) {
        $options[$field_name] = $definition->getLabel() . ' (' . $field_name . ')';
      }
      elseif (in_array($type, ['list_string', 'list_integer', 'boolean'], TRUE)) {
        $options[$field_name] = $definition->getLabel() . ' (' . $field_name . ')';
      }
    }
    asort($options);
    return $options;
  }

  protected function getFieldConditionType(string $entity_type, string $bundle, string $field_name): string {
    if (!$bundle || !$field_name) {
      return '';
    }
    $definitions = $this->fieldManager->getFieldDefinitions($entity_type, $bundle);
    if (!isset($definitions[$field_name])) {
      return '';
    }
    $type = $definitions[$field_name]->getType();
    if ($type === 'entity_reference' && (($definitions[$field_name]->getSettings()['target_type'] ?? '') === 'taxonomy_term')) {
      return 'taxonomy_term';
    }
    return in_array($type, ['list_string', 'list_integer', 'boolean'], TRUE) ? $type : '';
  }

  protected function getVocabularyOptionsForField(string $entity_type, string $bundle, string $field_name): array {
    $options = [];
    $definitions = $this->fieldManager->getFieldDefinitions($entity_type, $bundle);
    if (!isset($definitions[$field_name])) {
      return $options;
    }
    $target_bundles = $definitions[$field_name]->getSetting('handler_settings')['target_bundles'] ?? [];
    foreach ($target_bundles as $vid) {
      $vocabulary = $this->redirectEntityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
      $options[$vid] = $vocabulary ? $vocabulary->label() . ' (' . $vid . ')' : $vid;
    }
    asort($options);
    return $options;
  }

  protected function getSingleVocabularyForField(string $entity_type, string $bundle, string $field_name): string {
    $options = $this->getVocabularyOptionsForField($entity_type, $bundle, $field_name);
    return count($options) === 1 ? (string) array_key_first($options) : '';
  }

  protected function getTermOptions(string $vocabulary, bool $include_all): array {
    $options = $include_all ? ['__all__' => (string) $this->t('Всі терміни')] : [];
    foreach ($this->redirectEntityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary, 0, NULL, TRUE) as $term) {
      $options[(string) $term->id()] = $term->label() . ' (' . $term->id() . ')';
    }
    return $options;
  }

  protected function buildNodeMatchValueElement(string $field_name, string $condition_type, string $match_value): array {
    if (in_array($condition_type, ['list_string', 'list_integer'], TRUE)) {
      $storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', $field_name);
      $allowed = $storage ? ($storage->getSettings()['allowed_values'] ?? []) : [];
      $options = [];
      foreach ($allowed as $key => $label) {
        $options[(string) $key] = (string) $label . ' (' . $key . ')';
      }
      return ['#type' => 'select', '#title' => $this->t('Значення'), '#options' => $options, '#empty_option' => $this->t('- Оберіть значення -'), '#default_value' => $match_value, '#required' => TRUE];
    }
    if ($condition_type === 'boolean') {
      return ['#type' => 'select', '#title' => $this->t('Значення'), '#options' => ['1' => $this->t('Так'), '0' => $this->t('Ні')], '#empty_option' => $this->t('- Оберіть значення -'), '#default_value' => $match_value, '#required' => TRUE];
    }
    return ['#type' => 'textfield', '#title' => $this->t('Значення'), '#disabled' => TRUE, '#value' => ''];
  }

  protected function resolveMatchLabel(string $entity_type, string $bundle, string $field_name, string $condition_type, string $match_value, string $match_mode): string {
    if ($entity_type === 'taxonomy_term') {
      if ($match_mode === 'entity_bundle') {
        return (string) $this->t('Всі терміни');
      }
      $term = $this->redirectEntityTypeManager->getStorage('taxonomy_term')->load($match_value);
      return $term ? $term->label() . ' (' . $term->id() . ')' : $match_value;
    }
    if ($match_mode === 'entity_bundle') {
      return (string) $this->t('Усі матеріали цього типу');
    }
    if ($match_mode === 'entity_id') {
      $node = $this->redirectEntityTypeManager->getStorage('node')->load($match_value);
      return $node ? $node->label() . ' (' . $node->id() . ')' : $match_value;
    }
    if ($condition_type === 'taxonomy_term') {
      $term = $this->redirectEntityTypeManager->getStorage('taxonomy_term')->load($match_value);
      return $term ? $term->label() : $match_value;
    }
    return $match_value;
  }

  protected function getUiDefaultMatchValue(IscRedirectRule $rule): string {
    if ($rule->getTargetEntityType() === 'taxonomy_term' && $rule->getMatchMode() === 'entity_bundle') {
      return '__all__';
    }
    return $rule->getMatchValue();
  }

  protected function getLanguageOptions(): array {
    $options = [];
    foreach ($this->languageManager->getLanguages() as $code => $lang) {
      $options[$code] = $lang->getName() . ' (' . $code . ')';
    }
    asort($options);
    return $options;
  }

  protected function normalizeEntityAutocompleteValue(mixed $value): string {
    if (is_array($value) && isset($value['target_id'])) {
      return (string) $value['target_id'];
    }
    if (is_object($value) && method_exists($value, 'id')) {
      return (string) $value->id();
    }
    return is_scalar($value) ? trim((string) $value) : '';
  }
}
