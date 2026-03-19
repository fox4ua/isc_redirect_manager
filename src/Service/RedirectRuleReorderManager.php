<?php

namespace Drupal\isc_redirect_manager\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;

/**
 * Reorders redirect rules within one bundle.
 */
final class RedirectRuleReorderManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Applies new order and returns number of changed rules.
   */
  public function reorder(string $entity_type, string $bundle, array $rows): int {
    if ($rows === []) {
      return 0;
    }

    asort($rows, SORT_NUMERIC);

    $storage = $this->entityTypeManager->getStorage('isc_redirect_rule');
    $rules = $storage->loadMultiple(array_keys($rows));
    $changes = [];
    $position = 0;

    foreach (array_keys($rows) as $rule_id) {
      $rule = $rules[$rule_id] ?? NULL;
      if (!$rule instanceof IscRedirectRule) {
        continue;
      }
      if ($rule->getTargetEntityType() !== $entity_type || $rule->getBundle() !== $bundle) {
        continue;
      }

      $new_weight = $position;
      $position += 10;

      if ((int) $rule->getWeight() !== $new_weight) {
        $changes[(string) $rule_id] = $new_weight;
      }
    }

    if ($changes === []) {
      return 0;
    }

    foreach ($changes as $rule_id => $new_weight) {
      $rule = $rules[$rule_id] ?? NULL;
      if (!$rule instanceof IscRedirectRule) {
        continue;
      }

      $definition = $rule->getEntityType();
      $config_name = $definition->getProvider() . '.' . $definition->getConfigPrefix() . '.' . $rule->id();
      $this->configFactory
        ->getEditable($config_name)
        ->set('weight', $new_weight)
        ->save(TRUE);
    }

    $storage->resetCache(array_keys($changes));
    Cache::invalidateTags(IscRedirectRule::getRedirectRuleCacheTags($bundle, $entity_type));

    return count($changes);
  }

}
