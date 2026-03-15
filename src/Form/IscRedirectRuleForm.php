<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IscRedirectRuleForm extends EntityForm {

  protected $bundleInfo;
  protected $fieldManager;
  protected $redirectEntityTypeManager;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->fieldManager = $container->get('entity_field.manager');
    $instance->redirectEntityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  public function form(array $form, FormStateInterface $form_state) {
    $rule = $this->entity;

    $bundle = $form_state->getValue('bundle');
    if ($bundle === NULL) {
      $bundle = (string) ($rule->get('bundle') ?: '');
    }

    $stored_conditions = $rule->get('conditions') ?: [];
    if (!is_array($stored_conditions) || $stored_conditions === []) {
      $stored_conditions = [[
        'field_name' => (string) ($rule->get('field_name') ?: ''),
        'condition_type' => (string) ($rule->get('condition_type') ?: ''),
        'vocabulary' => (string) ($rule->get('vocabulary') ?: ''),
        'match_value' => (string) ($rule->get('match_value') ?: ''),
      ]];
    }

    if ($form_state->get('condition_count') === NULL) {
      $form_state->set('condition_count', max(1, count($stored_conditions)));
    }
    $condition_count = (int) $form_state->get('condition_count');

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Назва правила'),
      '#default_value' => $rule->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $rule->id(),
      '#machine_name' => [
        'exists' => '\Drupal\isc_redirect_manager\Entity\IscRedirectRule::load',
      ],
      '#disabled' => !$rule->isNew(),
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Увімкнено'),
      '#default_value' => (bool) $rule->status(),
    ];

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Тип матеріалу'),
      '#options' => $this->getNodeBundleOptions(),
      '#empty_option' => $this->t('- Оберіть тип матеріалу -'),
      '#default_value' => $bundle,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::ajaxRefreshDependent',
        'wrapper' => 'isc-redirect-dependent',
      ],
    ];

    $form['condition_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Логіка умов'),
      '#options' => ['AND' => 'AND', 'OR' => 'OR'],
      '#default_value' => (string) ($form_state->getValue('condition_operator') ?: ($rule->get('condition_operator') ?: 'AND')),
    ];

    $form['dependent'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'isc-redirect-dependent'],
      '#tree' => TRUE,
    ];

    $form['dependent']['conditions'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    for ($delta = 0; $delta < $condition_count; $delta++) {
      $condition = $stored_conditions[$delta] ?? [];
      $field_name = $form_state->getValue(['dependent', 'conditions', $delta, 'field_name']);
      if ($field_name === NULL) {
        $field_name = (string) ($condition['field_name'] ?? '');
      }

      $condition_type = '';
      if ($bundle !== '' && $field_name !== '') {
        $condition_type = $this->getFieldConditionType($bundle, $field_name);
      }
      if ($condition_type === '') {
        $condition_type = (string) ($condition['condition_type'] ?? '');
      }

      $vocabulary = $form_state->getValue(['dependent', 'conditions', $delta, 'vocabulary']);
      if ($vocabulary === NULL) {
        $vocabulary = (string) ($condition['vocabulary'] ?? '');
      }

      $match_value = $form_state->getValue(['dependent', 'conditions', $delta, 'match_value']);
      if ($match_value === NULL) {
        $match_value = (string) ($condition['match_value'] ?? '');
      }

      $form['dependent']['conditions'][$delta] = [
        '#type' => 'details',
        '#title' => $this->t('Умова @n', ['@n' => $delta + 1]),
        '#open' => TRUE,
      ];

      $field_options = $bundle ? $this->getSupportedFields($bundle) : [];
      if ($field_name && !isset($field_options[$field_name])) {
        $field_name = '';
      }

      $form['dependent']['conditions'][$delta]['field_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Поле'),
        '#options' => $field_options,
        '#empty_option' => $this->t('- Оберіть поле -'),
        '#default_value' => $field_name,
        '#required' => TRUE,
        '#disabled' => empty($field_options),
        '#ajax' => [
          'callback' => '::ajaxRefreshDependent',
          'wrapper' => 'isc-redirect-dependent',
        ],
      ];

      $form['dependent']['conditions'][$delta]['condition_type'] = [
        '#type' => 'hidden',
        '#value' => $condition_type,
      ];

      if ($condition_type === 'taxonomy_term') {
        $vocabulary_options = $this->getVocabularyOptionsForField($bundle, $field_name);
        if ($vocabulary && !isset($vocabulary_options[$vocabulary])) {
          $vocabulary = '';
          $match_value = '';
        }

        $form['dependent']['conditions'][$delta]['vocabulary'] = [
          '#type' => 'select',
          '#title' => $this->t('Словник'),
          '#options' => $vocabulary_options,
          '#empty_option' => $this->t('- Оберіть словник -'),
          '#default_value' => $vocabulary,
          '#required' => TRUE,
          '#disabled' => empty($vocabulary_options),
          '#ajax' => [
            'callback' => '::ajaxRefreshDependent',
            'wrapper' => 'isc-redirect-dependent',
          ],
        ];
      }
      else {
        $form['dependent']['conditions'][$delta]['vocabulary'] = ['#type' => 'hidden', '#value' => ''];
        $vocabulary = '';
      }

      if ($condition_type === 'taxonomy_term') {
        $term_options = $vocabulary ? $this->getTermOptions($vocabulary) : [];
        $form['dependent']['conditions'][$delta]['match_value'] = [
          '#type' => 'select',
          '#title' => $this->t('Термін'),
          '#options' => $term_options,
          '#empty_option' => $this->t('- Оберіть термін -'),
          '#default_value' => $match_value,
          '#required' => TRUE,
          '#disabled' => empty($term_options),
        ];
      }
      elseif (in_array($condition_type, ['list_string', 'list_integer'], TRUE)) {
        $allowed_values = $this->getAllowedValues($field_name);
        $form['dependent']['conditions'][$delta]['match_value'] = [
          '#type' => 'select',
          '#title' => $this->t('Значення'),
          '#options' => $allowed_values,
          '#empty_option' => $this->t('- Оберіть значення -'),
          '#default_value' => $match_value,
          '#required' => TRUE,
          '#disabled' => empty($allowed_values),
        ];
      }
      elseif ($condition_type === 'boolean') {
        $form['dependent']['conditions'][$delta]['match_value'] = [
          '#type' => 'select',
          '#title' => $this->t('Значення'),
          '#options' => ['1' => $this->t('Так'), '0' => $this->t('Ні')],
          '#empty_option' => $this->t('- Оберіть значення -'),
          '#default_value' => $match_value,
          '#required' => TRUE,
        ];
      }
      else {
        $form['dependent']['conditions'][$delta]['match_value'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Значення'),
          '#disabled' => TRUE,
        ];
      }
    }

    $form['dependent']['actions'] = ['#type' => 'actions'];
    $form['dependent']['actions']['add_condition'] = [
      '#type' => 'submit',
      '#value' => $this->t('Додати умову'),
      '#submit' => ['::addConditionSubmit'],
      '#ajax' => ['callback' => '::ajaxRefreshDependent', 'wrapper' => 'isc-redirect-dependent'],
      '#limit_validation_errors' => [],
    ];
    if ($condition_count > 1) {
      $form['dependent']['actions']['remove_condition'] = [
        '#type' => 'submit',
        '#value' => $this->t('Видалити останню умову'),
        '#submit' => ['::removeConditionSubmit'],
        '#ajax' => ['callback' => '::ajaxRefreshDependent', 'wrapper' => 'isc-redirect-dependent'],
        '#limit_validation_errors' => [],
      ];
    }

    $default_destination = (string) ($rule->get('destination') ?: '');
    $destination_translations = $rule->get('destination_translations') ?: [];
    $translation_rows = [];
    foreach ($destination_translations as $row) {
      if (!empty($row['langcode']) && isset($row['destination'])) {
        $translation_rows[] = $row['langcode'] . '|' . $row['destination'];
      }
    }

    $form['destination'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect destination (default)'),
      '#default_value' => $default_destination,
      '#required' => TRUE,
      '#description' => $this->t('Напр. /ua/news або https://example.com/path'),
    ];

    $form['destination_i18n'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Мультимовні destination'),
      '#default_value' => implode("\n", $translation_rows),
      '#description' => $this->t('Один рядок на мову у форматі langcode|destination, напр. uk|/ua/news'),
    ];

    $form['status_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Redirect type'),
      '#options' => [302 => '302 Temporary redirect', 301 => '301 Permanent redirect'],
      '#default_value' => (int) ($rule->get('status_code') ?: 302),
    ];

    $form['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Вага'),
      '#default_value' => (int) ($rule->get('weight') ?: 0),
    ];

    $form = parent::form($form, $form_state);

    $form['actions']['save_add_another'] = [
      '#type' => 'submit',
      '#name' => 'save_add_another',
      '#value' => $this->t('Save and add another'),
      '#weight' => 10,
    ];

    return $form;
  }

  public function ajaxRefreshDependent(array &$form, FormStateInterface $form_state) {
    return $form['dependent'];
  }

  public function addConditionSubmit(array &$form, FormStateInterface $form_state) {
    $count = (int) $form_state->get('condition_count');
    $form_state->set('condition_count', $count + 1);
    $form_state->setRebuild(TRUE);
  }

  public function removeConditionSubmit(array &$form, FormStateInterface $form_state) {
    $count = (int) $form_state->get('condition_count');
    $form_state->set('condition_count', max(1, $count - 1));
    $form_state->setRebuild(TRUE);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $bundle = trim((string) $form_state->getValue('bundle'));
    if ($bundle === '') {
      $form_state->setErrorByName('bundle', $this->t('Оберіть тип матеріалу.'));
    }

    $conditions = (array) $form_state->getValue(['dependent', 'conditions']);
    if ($conditions === []) {
      $form_state->setErrorByName('conditions', $this->t('Додайте хоча б одну умову.'));
    }

    foreach ($conditions as $delta => $condition) {
      $field_name = trim((string) ($condition['field_name'] ?? ''));
      $condition_type = ($bundle && $field_name) ? $this->getFieldConditionType($bundle, $field_name) : '';
      $vocabulary = trim((string) ($condition['vocabulary'] ?? ''));
      $match_value = $condition['match_value'] ?? '';

      if ($field_name === '') {
        $form_state->setErrorByName("dependent][conditions][$delta][field_name", $this->t('Оберіть поле.'));
      }
      if ($condition_type === 'taxonomy_term' && $vocabulary === '') {
        $form_state->setErrorByName("dependent][conditions][$delta][vocabulary", $this->t('Оберіть словник.'));
      }
      if ($match_value === '' || $match_value === NULL) {
        $form_state->setErrorByName("dependent][conditions][$delta][match_value", $this->t('Оберіть значення.'));
      }
    }

    $destination = trim((string) $form_state->getValue('destination'));
    if ($destination === '') {
      $form_state->setErrorByName('destination', $this->t('Вкажіть destination.'));
    }

    $lines = preg_split('/\R/', (string) $form_state->getValue('destination_i18n'));
    foreach ($lines as $index => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if (strpos($line, '|') === FALSE) {
        $form_state->setErrorByName('destination_i18n', $this->t('Невірний формат рядка @n. Використовуйте langcode|destination.', ['@n' => $index + 1]));
      }
    }
  }

  public function save(array $form, FormStateInterface $form_state) {
    $rule = $this->entity;
    $bundle = (string) $form_state->getValue('bundle');

    $raw_conditions = (array) $form_state->getValue(['dependent', 'conditions']);
    $conditions = [];
    foreach ($raw_conditions as $condition) {
      $field_name = trim((string) ($condition['field_name'] ?? ''));
      if ($field_name === '') {
        continue;
      }
      $condition_type = $this->getFieldConditionType($bundle, $field_name);
      $vocabulary = $condition_type === 'taxonomy_term' ? (string) ($condition['vocabulary'] ?? '') : '';
      $match_value = (string) ($condition['match_value'] ?? '');
      if ($condition_type === '' || $match_value === '') {
        continue;
      }

      $conditions[] = [
        'field_name' => $field_name,
        'condition_type' => $condition_type,
        'vocabulary' => $vocabulary,
        'match_value' => $match_value,
        'match_label' => $this->resolveMatchLabel($field_name, $condition_type, $match_value),
      ];
    }

    if ($conditions === []) {
      $conditions[] = [
        'field_name' => '',
        'condition_type' => '',
        'vocabulary' => '',
        'match_value' => '',
        'match_label' => '',
      ];
    }

    $destination_translations = [];
    $lines = preg_split('/\R/', (string) $form_state->getValue('destination_i18n'));
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || strpos($line, '|') === FALSE) {
        continue;
      }
      [$langcode, $destination] = array_map('trim', explode('|', $line, 2));
      if ($langcode === '' || $destination === '') {
        continue;
      }
      $destination_translations[] = ['langcode' => $langcode, 'destination' => $destination];
    }

    $rule->set('label', $form_state->getValue('label'));
    $rule->set('enabled', (bool) $form_state->getValue('enabled'));
    $rule->set('bundle', $bundle);
    $rule->set('condition_operator', (string) ($form_state->getValue('condition_operator') ?: 'AND'));
    $rule->set('conditions', $conditions);
    $rule->set('field_name', (string) $conditions[0]['field_name']);
    $rule->set('condition_type', (string) $conditions[0]['condition_type']);
    $rule->set('vocabulary', (string) $conditions[0]['vocabulary']);
    $rule->set('match_value', (string) $conditions[0]['match_value']);
    $rule->set('match_label', (string) $conditions[0]['match_label']);
    $rule->set('destination', trim((string) $form_state->getValue('destination')));
    $rule->set('destination_translations', $destination_translations);
    $rule->set('status_code', (int) $form_state->getValue('status_code'));
    $rule->set('weight', (int) $form_state->getValue('weight'));

    $status = $rule->save();

    $this->messenger()->addStatus(
      $status === SAVED_NEW
        ? $this->t('Redirect rule has been created.')
        : $this->t('Redirect rule has been updated.')
    );

    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#name'] ?? '') === 'save_add_another') {
      $form_state->setRedirect('entity.isc_redirect_rule.add_form');
      return;
    }

    $form_state->setRedirect('entity.isc_redirect_rule.collection');
  }

  protected function getNodeBundleOptions() {
    $options = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $machine_name => $bundle) {
      $options[$machine_name] = $bundle['label'];
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  protected function getSupportedFields($bundle) {
    $options = [];
    $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);

    $excluded_fields = [
      'nid', 'vid', 'uuid', 'langcode', 'type', 'revision_timestamp', 'revision_uid',
      'revision_log', 'uid', 'title', 'status', 'created', 'changed', 'promote',
      'sticky', 'default_langcode', 'revision_translation_affected', 'moderation_state',
      'path', 'menu_link',
    ];

    foreach ($definitions as $field_name => $definition) {
      if (in_array($field_name, $excluded_fields, TRUE) || strpos($field_name, 'field_') !== 0) {
        continue;
      }

      $type = $definition->getType();
      if ($type === 'entity_reference' && in_array(($definition->getSettings()['target_type'] ?? ''), ['taxonomy_term', 'taxonomy_vocabulary'], TRUE)) {
        $options[$field_name] = $definition->getLabel() . ' (' . $field_name . ')';
      }
      elseif (in_array($type, ['list_string', 'list_integer', 'boolean'], TRUE)) {
        $options[$field_name] = $definition->getLabel() . ' (' . $field_name . ')';
      }
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  protected function getFieldConditionType($bundle, $field_name) {
    if ($bundle === '' || $field_name === '') {
      return '';
    }

    $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);
    if (!isset($definitions[$field_name])) {
      return '';
    }

    $definition = $definitions[$field_name];
    $type = $definition->getType();
    if ($type === 'entity_reference') {
      $target_type = (string) ($definition->getSettings()['target_type'] ?? '');
      if (in_array($target_type, ['taxonomy_term', 'taxonomy_vocabulary'], TRUE)) {
        return 'taxonomy_term';
      }
    }
    if (in_array($type, ['list_string', 'list_integer', 'boolean'], TRUE)) {
      return $type;
    }

    return '';
  }

  protected function getVocabularyOptionsForField($bundle, $field_name) {
    $options = [];
    $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);

    if (!isset($definitions[$field_name])) {
      return $options;
    }

    $target_bundles = $definitions[$field_name]->getSetting('handler_settings')['target_bundles'] ?? [];
    foreach ($target_bundles as $vid) {
      $vocabulary = Vocabulary::load($vid);
      $options[$vid] = $vocabulary ? $vocabulary->label() . ' (' . $vid . ')' : $vid;
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  protected function getTermOptions($vocabulary) {
    $options = [];
    $terms = $this->redirectEntityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary, 0, NULL, TRUE);

    foreach ($terms as $term) {
      $options[(string) $term->id()] = $term->label() . ' (' . $term->id() . ')';
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  protected function getAllowedValues($field_name) {
    $storage = FieldStorageConfig::loadByName('node', $field_name);
    if (!$storage) {
      return [];
    }

    $allowed_values = $storage->getSettings()['allowed_values'] ?? [];
    $options = [];
    foreach ($allowed_values as $key => $label) {
      $options[(string) $key] = (string) $label . ' (' . $key . ')';
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  protected function resolveMatchLabel($field_name, $condition_type, $match_value) {
    if ($condition_type === 'taxonomy_term') {
      $term = $this->redirectEntityTypeManager->getStorage('taxonomy_term')->load($match_value);
      return $term ? $term->label() : $match_value;
    }

    if (in_array($condition_type, ['list_string', 'list_integer'], TRUE)) {
      $allowed = $this->getAllowedValues($field_name);
      return $allowed[$match_value] ?? $match_value;
    }

    if ($condition_type === 'boolean') {
      return $match_value === '1' ? (string) $this->t('Так') : (string) $this->t('Ні');
    }

    return $match_value;
  }

}
