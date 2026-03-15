<?php

namespace Drupal\isc_redirect_manager\Service;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Stores redirect diagnostics and usage statistics in dedicated tables.
 */
class RedirectFailureLogger {
  protected static array $requestDebugMessages = [];

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
    protected Time $time,
  ) {}

  public function logFailure(array $entry): void {
    $entry['timestamp'] = (int) ($entry['timestamp'] ?? $this->time->getCurrentTime());
    $entry['event_type'] = (string) ($entry['event_type'] ?? 'fallback');
    $entry['rule_id'] = (string) ($entry['rule_id'] ?? '');
    $entry['rule_label'] = (string) ($entry['rule_label'] ?? '');
    $entry['nid'] = (int) ($entry['nid'] ?? 0);
    $entry['langcode'] = (string) ($entry['langcode'] ?? '');
    $entry['base_destination'] = (string) ($entry['base_destination'] ?? '');
    $entry['built_destination'] = (string) ($entry['built_destination'] ?? '');
    $entry['reason'] = (string) ($entry['reason'] ?? '');

    if (!$this->storeFailureOrIncrementRepeat($entry)) {
      return;
    }

    $this->logger->warning(
      'Redirect failure for rule "@rule" on entity @nid. Lang: @lang. Base: @base. Built: @built. Reason: @reason.',
      [
        '@rule' => $entry['rule_label'] !== '' ? $entry['rule_label'] : $entry['rule_id'],
        '@nid' => (string) $entry['nid'],
        '@lang' => $entry['langcode'],
        '@base' => $entry['base_destination'],
        '@built' => $entry['built_destination'],
        '@reason' => $entry['reason'],
      ],
    );
  }

  public function logDebug(string $message, array $context = []): void {
    if (!$this->isDebugEnabled()) {
      return;
    }
    $hash = hash('sha256', $message . '|' . serialize($context));
    if (isset(static::$requestDebugMessages[$hash])) {
      return;
    }
    static::$requestDebugMessages[$hash] = TRUE;
    $this->logger->debug($message, $context);
  }

  public function getLog(): array {
    return $this->database->select('isc_redirect_manager_log', 'l')
      ->fields('l')
      ->orderBy('timestamp', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, $this->getMaxFailureLogEntries())
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?: [];
  }

  public function incrementRuleHit(string $rule_id, int $nid, string $destination): void {
    $timestamp = $this->time->getCurrentTime();
    $updated = $this->database->update('isc_redirect_manager_stats')
      ->expression('hits', 'hits + 1')
      ->fields(['last_triggered' => $timestamp, 'last_nid' => $nid, 'last_destination' => $destination])
      ->condition('rule_id', $rule_id)
      ->execute();
    if (!$updated) {
      try {
        $this->database->insert('isc_redirect_manager_stats')->fields([
          'rule_id' => $rule_id,
          'hits' => 1,
          'last_triggered' => $timestamp,
          'last_nid' => $nid,
          'last_destination' => $destination,
        ])->execute();
      }
      catch (\Exception) {
        $this->database->update('isc_redirect_manager_stats')
          ->expression('hits', 'hits + 1')
          ->fields(['last_triggered' => $timestamp, 'last_nid' => $nid, 'last_destination' => $destination])
          ->condition('rule_id', $rule_id)
          ->execute();
      }
    }
    $this->incrementDailyRuleHit($rule_id, $timestamp);
  }

  public function getRuleStats(): array {
    return $this->database->select('isc_redirect_manager_stats', 's')
      ->fields('s')
      ->orderBy('hits', 'DESC')
      ->orderBy('last_triggered', 'DESC')
      ->range(0, $this->getMaxStatEntries())
      ->execute()
      ->fetchAllAssoc('rule_id', \PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Returns all aggregated stats rows without applying the UI cap.
   *
   * Used by the statistics admin page so filters and direct rule links
   * operate on the full aggregated dataset instead of a pre-truncated top N.
   */
  public function getAllRuleStats(): array {
    return $this->database->select('isc_redirect_manager_stats', 's')
      ->fields('s')
      ->orderBy('hits', 'DESC')
      ->orderBy('last_triggered', 'DESC')
      ->execute()
      ->fetchAllAssoc('rule_id', \PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Returns a single aggregated stats row for one rule.
   */
  public function getRuleStat(string $rule_id): ?array {
    if ($rule_id === '') {
      return NULL;
    }

    $row = $this->database->select('isc_redirect_manager_stats', 's')
      ->fields('s')
      ->condition('rule_id', $rule_id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  public function getDailyStats(?string $rule_id = NULL, int $days = 14): array {
    $days = max(1, min($days, 90));
    $today = $this->getDayStamp($this->time->getCurrentTime());
    $from = $today - (($days - 1) * 86400);
    $query = $this->database->select('isc_redirect_manager_stats_daily', 'd');
    $query->addField('d', 'stats_day');
    $query->addExpression('SUM(d.hits)', 'hits');
    $query->condition('d.stats_day', $from, '>=');
    if ($rule_id !== NULL && $rule_id !== '') {
      $query->condition('d.rule_id', $rule_id);
    }
    $query->groupBy('d.stats_day');
    $query->orderBy('d.stats_day', 'ASC');
    $raw = $query->execute()->fetchAllKeyed();
    $series = [];
    for ($i = 0; $i < $days; $i++) {
      $stamp = $from + ($i * 86400);
      $series[] = ['date' => gmdate('Y-m-d', $stamp), 'label' => gmdate('d.m', $stamp), 'hits' => (int) ($raw[$stamp] ?? 0)];
    }
    return $series;
  }

  public function clearStats(): void {
    $this->database->truncate('isc_redirect_manager_stats')->execute();
    $this->database->truncate('isc_redirect_manager_stats_daily')->execute();
  }

  public function cleanupLogs(): void {
    $max = $this->getMaxFailureLogEntries();
    if ($max <= 0) return;
    $boundary = $this->database->select('isc_redirect_manager_log', 'l')->fields('l', ['id', 'timestamp'])->orderBy('timestamp', 'DESC')->orderBy('id', 'DESC')->range($max - 1, 1)->execute()->fetchAssoc();
    if (!$boundary) return;
    $delete = $this->database->delete('isc_redirect_manager_log');
    $group = $delete->orConditionGroup()->condition('timestamp', (int) $boundary['timestamp'], '<');
    $same = $delete->andConditionGroup()->condition('timestamp', (int) $boundary['timestamp'])->condition('id', (int) $boundary['id'], '<');
    $group->condition($same);
    $delete->condition($group)->execute();
  }

  public function cleanupStats(): void {
    $max = $this->getMaxStatEntries();
    if ($max <= 0) return;
    $count = (int) $this->database->select('isc_redirect_manager_stats', 's')->countQuery()->execute()->fetchField();
    if ($count <= $max) return;
    $boundary = $this->database->select('isc_redirect_manager_stats', 's')->fields('s', ['hits', 'last_triggered', 'rule_id'])->orderBy('hits', 'DESC')->orderBy('last_triggered', 'DESC')->orderBy('rule_id', 'DESC')->range($max - 1, 1)->execute()->fetchAssoc();
    if (!$boundary) return;
    $delete = $this->database->delete('isc_redirect_manager_stats');
    $group = $delete->orConditionGroup()->condition('hits', (int) $boundary['hits'], '<');
    $group->condition($delete->andConditionGroup()->condition('hits', (int) $boundary['hits'])->condition('last_triggered', (int) $boundary['last_triggered'], '<'));
    $group->condition($delete->andConditionGroup()->condition('hits', (int) $boundary['hits'])->condition('last_triggered', (int) $boundary['last_triggered'])->condition('rule_id', (string) $boundary['rule_id'], '<'));
    $delete->condition($group)->execute();
  }

  protected function storeFailureOrIncrementRepeat(array $entry): bool {
    $window = $this->getFailureLogThrottleWindow();
    if ($window > 0) {
      $threshold = ((int) $entry['timestamp']) - $window;
      $existing_id = $this->database->select('isc_redirect_manager_log', 'l')
        ->fields('l', ['id'])
        ->condition('timestamp', $threshold, '>=')
        ->condition('event_type', $entry['event_type'])
        ->condition('rule_id', $entry['rule_id'])
        ->condition('nid', $entry['nid'])
        ->condition('langcode', $entry['langcode'])
        ->condition('base_destination', $entry['base_destination'])
        ->condition('built_destination', $entry['built_destination'])
        ->condition('reason', $entry['reason'])
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($existing_id) {
        $this->database->update('isc_redirect_manager_log')
          ->expression('repeat_count', 'repeat_count + 1')
          ->fields(['last_seen' => (int) $entry['timestamp']])
          ->condition('id', (int) $existing_id)
          ->execute();
        return FALSE;
      }
    }

    $this->database->insert('isc_redirect_manager_log')->fields([
      'timestamp' => $entry['timestamp'],
      'event_type' => $entry['event_type'],
      'rule_id' => $entry['rule_id'],
      'rule_label' => $entry['rule_label'],
      'nid' => $entry['nid'],
      'langcode' => $entry['langcode'],
      'base_destination' => $entry['base_destination'],
      'built_destination' => $entry['built_destination'],
      'reason' => $entry['reason'],
      'repeat_count' => 1,
      'last_seen' => $entry['timestamp'],
    ])->execute();
    return TRUE;
  }

  protected function incrementDailyRuleHit(string $rule_id, int $timestamp): void {
    $day = $this->getDayStamp($timestamp);
    $updated = $this->database->update('isc_redirect_manager_stats_daily')->expression('hits', 'hits + 1')->condition('rule_id', $rule_id)->condition('stats_day', $day)->execute();
    if (!$updated) {
      try {
        $this->database->insert('isc_redirect_manager_stats_daily')->fields(['rule_id' => $rule_id, 'stats_day' => $day, 'hits' => 1])->execute();
      } catch (\Exception) {
        $this->database->update('isc_redirect_manager_stats_daily')->expression('hits', 'hits + 1')->condition('rule_id', $rule_id)->condition('stats_day', $day)->execute();
      }
    }
  }

  protected function getDayStamp(int $timestamp): int {
    return (int) strtotime(gmdate('Y-m-d 00:00:00', $timestamp) . ' UTC');
  }
  protected function isDebugEnabled(): bool { return (bool) $this->configFactory->get('isc_redirect_manager.settings')->get('debug_logging'); }
  protected function getFailureLogThrottleWindow(): int { return max(0, (int) $this->configFactory->get('isc_redirect_manager.settings')->get('failure_log_throttle_window')); }
  protected function getMaxFailureLogEntries(): int { return max(1, (int) $this->configFactory->get('isc_redirect_manager.settings')->get('max_failure_log_entries')); }
  protected function getMaxStatEntries(): int { return max(1, (int) $this->configFactory->get('isc_redirect_manager.settings')->get('max_stat_entries')); }
}
