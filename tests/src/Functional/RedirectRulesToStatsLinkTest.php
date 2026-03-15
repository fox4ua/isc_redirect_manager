<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Functional;

/**
 * Verifies transition from rules page to stats page keeps filter context.
 *
 * @group isc_redirect_manager
 */
final class RedirectRulesToStatsLinkTest extends RedirectBrowserTestBase {

  public function testHitsLinkUsesStatsFilterQueryKeys(): void {
    $account = $this->drupalCreateUser([
      'view isc redirect rules',
      'manage isc redirect rules',
      'view isc redirect stats',
    ]);
    $this->drupalLogin($account);

    $rule = $this->createRedirectRule([
      'id' => 'rule_a',
      'label' => 'Rule Alpha',
      'bundle' => 'page',
      'destination' => '/target-alpha',
    ]);
    $this->insertRuleStat($rule->id(), 5, 100, 11, '/target-alpha');

    $this->drupalGet('/admin/config/search/isc-redirects/nodes', [
      'query' => ['q' => 'Alpha'],
    ]);

    $links = $this->xpath("//a[normalize-space(text())='5']");
    $this->assertNotEmpty($links, 'Hits link is present for the rule row.');

    $href = (string) $links[0]->getAttribute('href');
    $this->assertStringContainsString('/admin/config/search/isc-redirects/stats?', $href);
    $this->assertStringContainsString('rule_id=rule_a', $href);
    $this->assertStringContainsString('bundle=page', $href);
    $this->assertStringContainsString('q=Alpha', $href);
    $this->assertStringNotContainsString('entity_type=', $href);
    $this->assertStringNotContainsString('enabled=', $href);
  }

}
