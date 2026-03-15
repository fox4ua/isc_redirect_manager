<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Functional;

/**
 * Verifies that main administration pages are reachable.
 *
 * @group isc_redirect_manager
 */
final class RedirectAdminPagesTest extends RedirectBrowserTestBase {

  public function testAdminPagesLoadForAuthorizedUser(): void {
    $account = $this->drupalCreateUser([
      'view isc redirect rules',
      'manage isc redirect rules',
      'view isc redirect logs',
      'view isc redirect stats',
      'administer isc redirect settings',
      'administer isc redirect rules',
    ]);
    $this->drupalLogin($account);

    $pages = [
      '/admin/config/search/isc-redirects/nodes' => 'Правила для матеріалів',
      '/admin/config/search/isc-redirects/taxonomy' => 'Правила для таксономії',
      '/admin/config/search/isc-redirects/stats' => 'Статистика правил переадресації',
      '/admin/config/search/isc-redirects/logs' => 'Журнал помилок переадресації',
      '/admin/config/search/isc-redirects/settings' => 'Налаштування переадресації',
    ];

    foreach ($pages as $path => $text) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains($text);
    }
  }

}
