<?php

namespace Drupal\isc_redirect_manager\Service;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;

/**
 * Допоміжні методи для адміністративного інтерфейсу модуля.
 */
final class RedirectAdminUiHelper {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function getRootTitle(): string {
    return (string) $this->t('Правила переадресації');
  }

  public function getSettingsTitle(): string {
    return (string) $this->t('Налаштування');
  }

  public function getAddRuleTitle(): string {
    return (string) $this->t('Додати правило');
  }

  public function getSectionTitle(string $entity_type): string {
    return $entity_type === 'taxonomy_term'
      ? (string) $this->t('Словники')
      : (string) $this->t('Матеріали');
  }

  public function normalizeEntityType(?string $entity_type): string {
    return $entity_type === 'taxonomy_term' ? 'taxonomy_term' : 'node';
  }

  public function getBundleLabel(string $entity_type, string $bundle): string {
    if ($bundle === '') {
      return '';
    }

    $entity = $this->loadBundleEntity($entity_type, $bundle);
    return $entity ? (string) $entity->label() : $bundle;
  }

  public function loadBundleEntity(string $entity_type, string $bundle): ?ConfigEntityInterface {
    if ($bundle === '') {
      return NULL;
    }

    $storage_id = $entity_type === 'taxonomy_term' ? 'taxonomy_vocabulary' : 'node_type';
    $entity = $this->entityTypeManager->getStorage($storage_id)->load($bundle);

    return $entity instanceof ConfigEntityInterface ? $entity : NULL;
  }

  public function getRuleContextFromRoute(
    mixed $rule,
    ?string $entity_type,
    ?string $bundle,
    ?string $query_entity_type = NULL,
    ?string $query_bundle = NULL,
  ): array {
    if ($rule instanceof IscRedirectRule) {
      return [
        'entity_type' => $rule->getTargetEntityType(),
        'bundle' => $rule->getBundle(),
        'rule_label' => (string) $rule->label(),
      ];
    }

    $resolved_entity_type = $this->normalizeEntityType($entity_type ?? $query_entity_type);
    $resolved_bundle = (string) ($bundle ?? $query_bundle ?? '');

    return [
      'entity_type' => $resolved_entity_type,
      'bundle' => $resolved_bundle,
      'rule_label' => '',
    ];
  }

}
