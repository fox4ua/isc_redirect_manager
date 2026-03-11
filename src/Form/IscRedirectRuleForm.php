<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

    $field_name = $form_state->getValue('field_name');
    if ($field_name === NULL) {
      $field_name = (string) ($rule->get('field_name') ?: '');
    }

    $condition_type = '';
    if ($bundle !== '' && $field_name !== '') {
      $condition_type = $this->getFieldConditionType($bundle, $field_name);
    }
    if ($condition_type === '') {
      $condition_type = (string) ($rule->get('condition_type') ?: '');
    }

    $vocabulary = $form_state->getValue('vocabulary');
    if ($vocabulary === NULL) {
      $vocabulary = (string) ($rule->get('vocabulary') ?: '');
    }

    $match_value = $form_state->getValue('match_value');
    if ($match_value === NULL) {
      $match_value = (string) ($rule->get('match_value') ?: '');
    }

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

    $form['dependent'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'isc-redirect-dependent',
      ],
    ];

    $field_options = $bundle ? $this->getSupportedFields($bundle) : [];
    if ($field_name && !isset($field_options[$field_name])) {
      $field_name = '';
      $condition_type = '';
      $vocabulary = '';
      $match_value = '';
    }

    $form['dependent']['field_name'] = [
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

    $form['dependent']['condition_type'] = [
      '#type' => 'hidden',
      '#value' => $condition_type,
    ];

    if ($condition_type === 'taxonomy_term') {
      $vocabulary_options = $this->getVocabularyOptionsForField($bundle, $field_name);
      if ($vocabulary && !isset($vocabulary_options[$vocabulary])) {
        $vocabulary = '';
        $match_value = '';
      }

      $form['dependent']['vocabulary'] = [
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
      $form['dependent']['vocabulary'] = [
        '#type' => 'hidden',
        '#value' => '',
      ];
      $vocabulary = '';
    }

    if ($condition_type === 'taxonomy_term') {
      $term_options = $vocabulary ? $this->getTermOptions($vocabulary) : [];
      $form['dependent']['match_value'] = [
        '#type' => 'select',
        '#title' => $this->t('Термін'),
        '#options' => $term_options,
        '#empty_option' => $this->t('- Оберіть термін -'),
        '#default_value' => $match_value,
        '#required' => TRUE,
        '#disabled' => empty($term_options),
      ];
    }
    elseif ($condition_type === 'list_string' || $condition_type === 'list_integer') {
      $allowed_values = $this->getAllowedValues($field_name);
      $form['dependent']['match_value'] = [
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
      $form['dependent']['match_value'] = [
        '#type' => 'select',
        '#title' => $this->t('Значення'),
        '#options' => [
          '1' => $this->t('Так'),
          '0' => $this->t('Ні'),
        ],
        '#empty_option' => $this->t('- Оберіть значення -'),
        '#default_value' => $match_value,
        '#required' => TRUE,
      ];
    }
    else {
      $form['dependent']['match_value'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Значення'),
        '#disabled' => TRUE,
      ];
    }

    $form['destination'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect destination'),
      '#default_value' => (string) ($rule->get('destination') ?: ''),
      '#required' => TRUE,
    ];

    $form['status_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Redirect type'),
      '#options' => [
        302 => '302 Temporary redirect',
        301 => '301 Permanent redirect',
      ],
      '#default_value' => (int) ($rule->get('status_code') ?: 302),
    ];

    $form['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Вага'),
      '#default_value' => (int) ($rule->get('weight') ?: 0),
    ];

    return parent::form($form, $form_state);
  }

  public function ajaxRefreshDependent(array &$form, FormStateInterface $form_state) {
    return $form['dependent'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $bundle = trim((string) $form_state->getValue('bundle'));
    $field_name = trim((string) $form_state->getValue('field_name'));
    $condition_type = ($bundle && $field_name) ? $this->getFieldConditionType($bundle, $field_name) : '';
    $vocabulary = trim((string) $form_state->getValue('vocabulary'));
    $match_value = $form_state->getValue('match_value');
    $destination = trim((string) $form_state->getValue('destination'));

    if ($bundle === '') {
      $form_state->setErrorByName('bundle', $this->t('Оберіть тип матеріалу.'));
    }
    if ($field_name === '') {
      $form_state->setErrorByName('field_name', $this->t('Оберіть поле.'));
    }
    if ($condition_type === 'taxonomy_term' && $vocabulary === '') {
      $form_state->setErrorByName('vocabulary', $this->t('Оберіть словник.'));
    }
    if ($match_value === '' || $match_value === NULL) {
      $form_state->setErrorByName('match_value', $this->t('Оберіть значення.'));
    }
    if ($destination === '') {
      $form_state->setErrorByName('destination', $this->t('Вкажіть destination.'));
    }
  }

  public function save(array $form, FormStateInterface $form_state) {
    $rule = $this->entity;

    $bundle = (string) $form_state->getValue('bundle');
    $field_name = (string) $form_state->getValue('field_name');
    $condition_type = $this->getFieldConditionType($bundle, $field_name);
    $vocabulary = $condition_type === 'taxonomy_term' ? (string) $form_state->getValue('vocabulary') : '';
    $match_value = (string) $form_state->getValue('match_value');
    $match_label = $this->resolveMatchLabel($field_name, $condition_type, $match_value);

    $rule->set('label', $form_state->getValue('label'));
    $rule->set('enabled', (bool) $form_state->getValue('enabled'));
    $rule->set('bundle', $bundle);
    $rule->set('field_name', $field_name);
    $rule->set('condition_type', $condition_type);
    $rule->set('vocabulary', $vocabulary);
    $rule->set('match_value', $match_value);
    $rule->set('match_label', $match_label);
    $rule->set('destination', trim((string) $form_state->getValue('destination')));
    $rule->set('status_code', (int) $form_state->getValue('status_code'));
    $rule->set('weight', (int) $form_state->getValue('weight'));

    $status = $rule->save();

    $this->messenger()->addStatus(
      $status === SAVED_NEW
        ? $this->t('Redirect rule has been created.')
        : $this->t('Redirect rule has been updated.')
    );

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
      if (in_array($field_name, $excluded_fields, TRUE)) {
        continue;
      }
      if (strpos($field_name, 'field_') !== 0) {
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

    if ($type === 'entity_reference' && (($definition->getSettings()['target_type'] ?? '') === 'taxonomy_term')) {
      return 'taxonomy_term';
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
