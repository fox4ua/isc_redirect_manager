<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Functional;

/**
 * Verifies the stats filter form redirects with applied query parameters.
 *
 * @group isc_redirect_manager
 */
final class RedirectStatsFilterFormTest extends RedirectBrowserTestBase {

  public function testStatsFilterFormAppliesBundleFilter(): void {
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'news', 'name' => 'News']);

    $account = $this->drupalCreateUser([
      'view isc redirect stats',
      'view isc redirect rules',
      'manage isc redirect rules',
    ]);
    $this->drupalLogin($account);

    $article_rule = $this->createRedirectRule([
      'id' => 'article_rule',
      'label' => 'Article Rule',
      'bundle' => 'article',
      'destination' => '/article-target',
    ]);
    $news_rule = $this->createRedirectRule([
      'id' => 'news_rule',
      'label' => 'News Rule',
      'bundle' => 'news',
      'destination' => '/news-target',
    ]);

    $this->insertRuleStat($article_rule->id(), 7, 100, 1001, '/article-target');
    $this->insertRuleStat($news_rule->id(), 2, 90, 1002, '/news-target');

    $this->drupalGet('/admin/config/search/isc-redirects/stats');
    $this->submitForm([
      'bundle' => 'article',
      'q' => '',
    ], 'Застосувати фільтри');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/admin/config/search/isc-redirects/stats?bundle=article');
    $this->assertSession()->pageTextContains('Article Rule');
    $this->assertSession()->pageTextNotContains('News Rule');
  }

}
