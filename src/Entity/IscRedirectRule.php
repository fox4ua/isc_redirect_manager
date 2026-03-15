<?php

namespace Drupal\isc_redirect_manager\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines redirect rule config entity.
 *
 * @ConfigEntityType(
 *   id = "isc_redirect_rule",
 *   label = @Translation("ISC redirect rule"),
 *   label_collection = @Translation("ISC redirect rules"),
 *   handlers = {
 *     "list_builder" = "Drupal\isc_redirect_manager\IscRedirectRuleListBuilder",
 *     "form" = {
 *       "add" = "Drupal\isc_redirect_manager\Form\IscRedirectRuleForm",
 *       "edit" = "Drupal\isc_redirect_manager\Form\IscRedirectRuleForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   admin_permission = "administer isc redirect rules",
 *   config_prefix = "isc_redirect_rule",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "status" = "enabled"
 *   },
 *   links = {
 *     "collection" = "/admin/config/search/isc-redirects",
 *     "add-form" = "/admin/config/search/isc-redirects/add",
 *     "edit-form" = "/admin/config/search/isc-redirects/{isc_redirect_rule}",
 *     "delete-form" = "/admin/config/search/isc-redirects/{isc_redirect_rule}/delete"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "enabled",
 *     "entity_type",
 *     "bundle",
 *     "match_mode",
 *     "target_entity_id",
 *     "field_name",
 *     "condition_type",
 *     "vocabulary",
 *     "match_value",
 *     "match_label",
 *     "destination",
 *     "language_mode",
 *     "target_langcode",
 *     "status_code",
 *     "weight"
 *   }
 * )
 */
class IscRedirectRule extends ConfigEntityBase {

  protected $id = '';
  protected $label = '';
  protected $enabled = TRUE;
  protected $entity_type = 'node';
  protected $bundle = '';
  protected $match_mode = 'field_value';
  protected $target_entity_id = '';
  protected $field_name = '';
  protected $condition_type = '';
  protected $vocabulary = '';
  protected $match_value = '';
  protected $match_label = '';
  protected $destination = '';
  protected $language_mode = 'content';
  protected $target_langcode = '';
  protected $status_code = 302;
  protected $weight = 0;

  public function isEnabled(): bool {
    return (bool) $this->enabled;
  }

  public function getTargetEntityType(): string {
    return in_array((string) $this->entity_type, ['node', 'taxonomy_term'], TRUE) ? (string) $this->entity_type : 'node';
  }

  public function getBundle(): string {
    return (string) $this->bundle;
  }

  public function getMatchMode(): string {
    return in_array((string) $this->match_mode, ['field_value', 'entity_bundle', 'entity_id'], TRUE) ? (string) $this->match_mode : 'field_value';
  }

  public function getTargetEntityId(): string {
    return (string) $this->target_entity_id;
  }

  public function getFieldName(): string {
    return (string) $this->field_name;
  }

  public function getConditionType(): string {
    return (string) $this->condition_type;
  }

  public function getVocabulary(): string {
    return (string) $this->vocabulary;
  }

  public function getMatchValue(): string {
    return (string) $this->match_value;
  }

  public function getMatchLabel(): string {
    return (string) ($this->match_label ?: $this->match_value);
  }

  public function getDestination(): string {
    return (string) $this->destination;
  }

  public function getLanguageMode(): string {
    $mode = (string) $this->language_mode;
    return in_array($mode, ['content', 'fixed', 'neutral'], TRUE) ? $mode : 'content';
  }

  public function getTargetLangcode(): string {
    return (string) $this->target_langcode;
  }

  public function getStatusCode(): int {
    $code = (int) $this->status_code;
    return in_array($code, [301, 302], TRUE) ? $code : 302;
  }

  public function getWeight(): int {
    return (int) $this->weight;
  }


  public static function getRedirectRuleCacheTags(?string $bundle = NULL, ?string $entity_type = NULL): array {
    $tags = ['isc_redirect_manager_rules'];
    if ($bundle !== NULL && $bundle !== '') {
      $suffix = ($entity_type ? $entity_type . ':' : '') . $bundle;
      $tags[] = 'isc_redirect_manager_rules:' . $suffix;
    }
    return $tags;
  }

  public function postSave($storage, $update = TRUE): void {
    parent::postSave($storage, $update);

    $tags = static::getRedirectRuleCacheTags($this->getBundle(), $this->getTargetEntityType());
    if ($update && isset($this->original) && $this->original instanceof self) {
      $original_bundle = $this->original->getBundle();
      if ($original_bundle !== $this->getBundle()) {
        $tags = array_merge($tags, static::getRedirectRuleCacheTags($original_bundle, $this->original->getTargetEntityType()));
      }
    }

    Cache::invalidateTags(array_unique($tags));
  }

  public static function postDelete($storage, array $entities): void {
    parent::postDelete($storage, $entities);

    $tags = ['isc_redirect_manager_rules'];
    foreach ($entities as $entity) {
      if ($entity instanceof self) {
        $tags = array_merge($tags, static::getRedirectRuleCacheTags($entity->getBundle(), $entity->getTargetEntityType()));
      }
    }
    Cache::invalidateTags(array_unique($tags));
  }

}
