<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Kernel;

use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;

/**
 * Covers duplicate enabled rule detection.
 *
 * @group isc_redirect_manager
 */
final class RedirectRuleConflictKernelTest extends RedirectKernelTestBase {

  public function testEnabledConflictIsDetectedForSameSignature(): void {
    $this->createRule([
      'id' => 'existing_bundle_rule',
      'label' => 'Existing bundle rule',
      'bundle' => 'article',
      'match_mode' => 'entity_bundle',
    ]);

    $storage = $this->container->get('entity_type.manager')->getStorage('isc_redirect_rule');
    $candidate = $storage->create([
      'id' => 'candidate_bundle_rule',
      'label' => 'Candidate bundle rule',
      'enabled' => TRUE,
      'entity_type' => 'node',
      'bundle' => 'article',
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
    ]);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectRuleMatcher $matcher */
    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $this->assertTrue($matcher->hasEnabledConflict($candidate));
  }

  public function testDifferentSignatureDoesNotConflict(): void {
    $this->createRule([
      'id' => 'existing_entity_rule',
      'label' => 'Existing entity rule',
      'bundle' => 'article',
      'match_mode' => 'entity_id',
      'target_entity_id' => '1',
    ]);

    $storage = $this->container->get('entity_type.manager')->getStorage('isc_redirect_rule');
    $candidate = $storage->create([
      'id' => 'candidate_other_entity_rule',
      'label' => 'Candidate other entity rule',
      'enabled' => TRUE,
      'entity_type' => 'node',
      'bundle' => 'article',
      'match_mode' => 'entity_id',
      'target_entity_id' => '2',
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
    ]);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectRuleMatcher $matcher */
    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $this->assertFalse($matcher->hasEnabledConflict($candidate));
  }

}
