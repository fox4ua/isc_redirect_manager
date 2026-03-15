<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Functional;

/**
 * Verifies clearing statistics through the confirm form.
 *
 * @group isc_redirect_manager
 */
final class RedirectStatsClearTest extends RedirectBrowserTestBase {

  public function testClearStatsConfirmationRemovesStoredStats(): void {
    $account = $this->drupalCreateUser([
      'view isc redirect stats',
      'administer isc redirect rules',
    ]);
    $this->drupalLogin($account);

    $rule = $this->createRedirectRule([
      'id' => 'clearable_rule',
      'label' => 'Clearable rule',
    ]);
    $this->insertRuleStat($rule->id(), 4, 123, 7, '/target');

    $this->drupalGet('/admin/config/search/isc-redirects/stats');
    $this->assertSession()->pageTextContains('Clearable rule');

    $this->drupalGet('/admin/config/search/isc-redirects/stats/clear');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Очистити всю статистику редиректів?');

    $this->submitForm([], 'Очистити статистику');
    $this->assertSession()->pageTextContains('Статистику очищено.');
    $this->assertSession()->pageTextNotContains('Clearable rule');
  }

}
