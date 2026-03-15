<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Kernel;

use Drupal\isc_redirect_manager\Service\RedirectFailureLogger;

/**
 * Covers redirect statistics and diagnostics storage.
 *
 * @group isc_redirect_manager
 */
final class RedirectFailureLoggerKernelTest extends RedirectKernelTestBase {

  public function testIncrementRuleHitUpdatesAggregateAndDailyStats(): void {
    /** @var \Drupal\isc_redirect_manager\Service\RedirectFailureLogger $logger */
    $logger = $this->container->get('isc_redirect_manager.failure_logger');

    $logger->incrementRuleHit('rule_alpha', 10, '/front');
    $logger->incrementRuleHit('rule_alpha', 11, '/front');
    $logger->incrementRuleHit('rule_beta', 20, '/archive');

    $stats = $logger->getRuleStats();
    $this->assertSame(2, (int) $stats['rule_alpha']['hits']);
    $this->assertSame(11, (int) $stats['rule_alpha']['last_nid']);
    $this->assertSame('/front', $stats['rule_alpha']['last_destination']);
    $this->assertSame(1, (int) $stats['rule_beta']['hits']);

    $daily = $logger->getDailyStats('rule_alpha', 1);
    $this->assertCount(1, $daily);
    $this->assertSame(2, $daily[0]['hits']);

    $all_daily = $logger->getDailyStats(NULL, 1);
    $this->assertCount(1, $all_daily);
    $this->assertSame(3, $all_daily[0]['hits']);
  }

  public function testClearStatsRemovesAggregateAndDailyRows(): void {
    /** @var \Drupal\isc_redirect_manager\Service\RedirectFailureLogger $logger */
    $logger = $this->container->get('isc_redirect_manager.failure_logger');
    $logger->incrementRuleHit('rule_alpha', 10, '/front');
    $logger->incrementRuleHit('rule_alpha', 11, '/front');

    $logger->clearStats();

    $this->assertSame([], $logger->getRuleStats());
    $daily = $logger->getDailyStats('rule_alpha', 1);
    $this->assertCount(1, $daily);
    $this->assertSame(0, $daily[0]['hits']);
  }

  public function testFailureThrottleSuppressesDuplicateRowsWithinWindow(): void {
    $config = $this->config('isc_redirect_manager.settings');
    $this->config('isc_redirect_manager.settings')
      ->set('failure_log_throttle_window', 3600)
      ->save();

    /** @var \Drupal\isc_redirect_manager\Service\RedirectFailureLogger $logger */
    $logger = $this->container->get('isc_redirect_manager.failure_logger');
    $entry = [
      'timestamp' => 1000,
      'event_type' => 'invalid_destination',
      'rule_id' => 'rule_alpha',
      'rule_label' => 'Rule alpha',
      'nid' => 55,
      'langcode' => 'en',
      'base_destination' => '/missing',
      'built_destination' => '/missing',
      'reason' => 'Missing route',
    ];

    $logger->logFailure($entry);
    $logger->logFailure($entry + ['timestamp' => 1200]);

    $log = $logger->getLog();
    $this->assertCount(1, $log);
  }

}
