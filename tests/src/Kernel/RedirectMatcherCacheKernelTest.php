<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Kernel;

/**
 * Verifies compiled matcher cache invalidation after rule changes.
 *
 * @group isc_redirect_manager
 */
final class RedirectMatcherCacheKernelTest extends RedirectKernelTestBase {

  public function testCompiledCacheReflectsUpdatedRuleDestination(): void {
    $node = $this->createNode('article', 'Cached destination');
    $this->pushNodeRequest($node);

    $rule = $this->createRule([
      'id' => 'cache_rule',
      'label' => 'Cache rule',
      'destination' => '/first-target',
      'language_mode' => 'neutral',
    ]);

    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $response = $matcher->match($node);
    $this->assertSame('/first-target', $response?->getTargetUrl());

    $rule->set('destination', '/second-target');
    $rule->save();

    $response = $matcher->match($node);
    $this->assertSame('/second-target', $response?->getTargetUrl());

    $this->popRequest();
  }
}
