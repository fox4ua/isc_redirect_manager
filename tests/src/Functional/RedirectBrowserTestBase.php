<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Shared helpers for browser tests.
 */
abstract class RedirectBrowserTestBase extends BrowserTestBase {

  protected static $modules = [
    'node',
    'taxonomy',
    'path_alias',
    'options',
    'isc_redirect_manager',
  ];

  protected $defaultTheme = 'stark';

  protected function createRedirectRule(array $values = []) {
    $storage = $this->container->get('entity_type.manager')->getStorage('isc_redirect_rule');
    $defaults = [
      'id' => 'rule_' . substr(hash('sha256', serialize($values) . microtime(TRUE)), 0, 12),
      'label' => 'Browser test rule',
      'enabled' => TRUE,
      'entity_type' => 'node',
      'bundle' => 'page',
      'match_mode' => 'entity_bundle',
      'target_entity_id' => '',
      'field_name' => '',
      'condition_type' => '',
      'vocabulary' => '',
      'match_value' => '',
      'match_label' => '',
      'destination' => '<front>',
      'language_mode' => 'neutral',
      'target_langcode' => '',
      'status_code' => 302,
      'weight' => 0,
    ];

    $rule = $storage->create($values + $defaults);
    $rule->save();
    return $rule;
  }

  protected function insertRuleStat(string $rule_id, int $hits, int $last_triggered, int $last_nid, string $last_destination): void {
    $this->container->get('database')->merge('isc_redirect_manager_stats')
      ->key(['rule_id' => $rule_id])
      ->fields([
        'hits' => $hits,
        'last_triggered' => $last_triggered,
        'last_nid' => $last_nid,
        'last_destination' => $last_destination,
      ])
      ->execute();
  }

}
