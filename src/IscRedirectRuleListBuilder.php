<?php

namespace Drupal\isc_redirect_manager;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;

class IscRedirectRuleListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Title'),
      'enabled' => $this->t('Enabled'),
      'bundle' => $this->t('Content type'),
      'field_name' => $this->t('Field'),
      'condition_type' => $this->t('Conditions'),
      'match_label' => $this->t('Logic / values'),
      'destination' => $this->t('Destination'),
      'status_code' => $this->t('Code'),
      'weight' => $this->t('Weight'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    return [
      'label' => Link::createFromRoute(
        $entity->label(),
        'entity.isc_redirect_rule.edit_form',
        ['isc_redirect_rule' => $entity->id()]
      ),
      'enabled' => $entity->status() ? $this->t('Yes') : $this->t('No'),
      'bundle' => (string) $entity->get('bundle'),
      'field_name' => (string) $entity->get('field_name'),
      'condition_type' => $this->buildConditionSummary($entity),
      'match_label' => $this->buildLogicSummary($entity),
      'destination' => (string) $entity->get('destination'),
      'status_code' => (string) ((int) ($entity->get('status_code') ?: 302)),
      'weight' => (string) ((int) ($entity->get('weight') ?: 0)),
    ] + parent::buildRow($entity);
  }

  protected function buildConditionSummary(EntityInterface $entity): string {
    $conditions = $entity->get('conditions') ?: [];
    if (!is_array($conditions) || $conditions === []) {
      return (string) $entity->get('condition_type');
    }

    $parts = [];
    foreach ($conditions as $condition) {
      $parts[] = (string) ($condition['field_name'] ?? '');
    }

    return implode(', ', array_filter($parts));
  }

  protected function buildLogicSummary(EntityInterface $entity): string {
    $conditions = $entity->get('conditions') ?: [];
    if (!is_array($conditions) || $conditions === []) {
      return (string) ($entity->get('match_label') ?: $entity->get('match_value'));
    }

    $operator = (string) ($entity->get('condition_operator') ?: 'AND');
    $parts = [];
    foreach ($conditions as $condition) {
      $parts[] = (string) ($condition['match_label'] ?? $condition['match_value'] ?? '');
    }

    return $operator . ': ' . implode(' | ', array_filter($parts));
  }

  public function load(): array {
    $entities = parent::load();

    uasort($entities, static function ($a, $b) {
      $weight_a = (int) ($a->get('weight') ?? 0);
      $weight_b = (int) ($b->get('weight') ?? 0);

      if ($weight_a === $weight_b) {
        return strnatcasecmp($a->label(), $b->label());
      }

      return $weight_a <=> $weight_b;
    });

    return $entities;
  }

}
