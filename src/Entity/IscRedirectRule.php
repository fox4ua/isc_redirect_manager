<?php

namespace Drupal\isc_redirect_manager\Entity;

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
 *     "bundle",
 *     "field_name",
 *     "condition_type",
 *     "vocabulary",
 *     "match_value",
 *     "match_label",
 *     "destination",
 *     "status_code",
 *     "weight"
 *   }
 * )
 */
class IscRedirectRule extends ConfigEntityBase {

  protected $id = '';
  protected $label = '';
  protected $enabled = TRUE;
  protected $bundle = '';
  protected $field_name = '';
  protected $condition_type = '';
  protected $vocabulary = '';
  protected $match_value = '';
  protected $match_label = '';
  protected $destination = '';
  protected $status_code = 302;
  protected $weight = 0;

}
