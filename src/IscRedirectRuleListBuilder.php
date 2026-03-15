<?php

namespace Drupal\isc_redirect_manager;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;

class IscRedirectRuleListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Назва'),
      'enabled' => $this->t('Увімкнено'),
      'entity_type' => $this->t('Тип сутності'),
      'bundle' => $this->t('Набір'),
      'match_mode' => $this->t('Режим співпадіння'),
      'field_name' => $this->t('Поле'),
      'condition_type' => $this->t('Умова'),
      'match_label' => $this->t('Значення'),
      'destination' => $this->t('Місце призначення'),
      'language_mode' => $this->t('Мовний режим'),
      'status_code' => $this->t('Код'),
      'weight' => $this->t('Вага'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\isc_redirect_manager\Entity\IscRedirectRule $entity */
    return [
      'label' => Link::createFromRoute($entity->label(), 'entity.isc_redirect_rule.edit_form', ['isc_redirect_rule' => $entity->id()]),
      'enabled' => $entity->isEnabled() ? $this->t('Так') : $this->t('Ні'),
      'entity_type' => $entity->getTargetEntityType(),
      'bundle' => $entity->getBundle(),
      'match_mode' => $entity->getMatchMode(),
      'field_name' => $entity->getFieldName(),
      'condition_type' => $entity->getConditionType(),
      'match_label' => $entity->getMatchLabel(),
      'destination' => $entity->getDestination(),
      'language_mode' => $entity->getLanguageMode(),
      'status_code' => (string) $entity->getStatusCode(),
      'weight' => (string) $entity->getWeight(),
    ] + parent::buildRow($entity);
  }

  public function load(): array {
    $entities = parent::load();
    uasort($entities, static function (IscRedirectRule $a, IscRedirectRule $b): int {
      if ($a->getWeight() === $b->getWeight()) {
        return strnatcasecmp($a->label(), $b->label());
      }
      return $a->getWeight() <=> $b->getWeight();
    });
    return $entities;
  }

}
