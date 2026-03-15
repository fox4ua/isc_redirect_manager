<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Functional;

/**
 * Verifies stats page filtering by the current rule context.
 *
 * @group isc_redirect_manager
 */
final class RedirectStatsRuleContextTest extends RedirectBrowserTestBase {

  public function testStatsPageShowsOnlyCurrentRuleWhenRuleIdIsProvided(): void {
    $account = $this->drupalCreateUser([
      'view isc redirect stats',
      'view isc redirect rules',
      'manage isc redirect rules',
    ]);
    $this->drupalLogin($account);

    $rule_a = $this->createRedirectRule([
      'id' => 'rule_a',
      'label' => 'Rule A',
      'bundle' => 'page',
      'destination' => '/node',
    ]);
    $rule_b = $this->createRedirectRule([
      'id' => 'rule_b',
      'label' => 'Rule B',
      'bundle' => 'page',
      'destination' => '/taxonomy',
    ]);

    $this->insertRuleStat($rule_a->id(), 5, 100, 11, '/node');
    $this->insertRuleStat($rule_b->id(), 3, 200, 22, '/taxonomy');

    $this->drupalGet('/admin/config/search/isc-redirects/stats', [
      'query' => ['rule_id' => $rule_a->id()],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Поточне правило: Rule A');
    $this->assertSession()->pageTextContains('Rule A');
    $this->assertSession()->pageTextNotContains('Rule B');
    $this->assertSession()->linkExists('Вся статистика');
  }

}
