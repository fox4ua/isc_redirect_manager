<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Kernel;

/**
 * Covers compiled diagnostics for broken rules.
 *
 * @group isc_redirect_manager
 */
final class RedirectRuleDiagnosticsKernelTest extends RedirectKernelTestBase {

  public function testBrokenBundleRuleIsExcludedFromRuntimeAndReported(): void {
    $this->createRule([
      'id' => 'broken_bundle_rule',
      'label' => 'Broken bundle rule',
      'bundle' => 'missing_bundle',
      'destination' => '/target',
    ]);

    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $diagnostics = $matcher->getCompiledDiagnostics();

    $this->assertArrayHasKey('broken_bundle_rule', $diagnostics);
    $this->assertContains('missing_bundle', $diagnostics['broken_bundle_rule']['issues']);
  }
}
