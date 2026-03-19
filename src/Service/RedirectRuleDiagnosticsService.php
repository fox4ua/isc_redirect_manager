<?php

namespace Drupal\isc_redirect_manager\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides admin-facing diagnostics for broken rules.
 */
class RedirectRuleDiagnosticsService {
  use StringTranslationTrait;

  public function __construct(protected RedirectRuleMatcher $matcher) {}

  public function getDiagnostics(): array {
    $mapped = [];
    foreach ($this->matcher->getCompiledDiagnostics() as $item) {
      $mapped[$item['rule_id']] = [
        'rule_id' => $item['rule_id'],
        'label' => $item['label'],
        'bundle' => $item['bundle'],
        'entity_type' => $item['entity_type'],
        'destination' => $item['destination'],
        'issues' => array_map(fn (string $issue): string => $this->mapIssue($issue), $item['issues']),
      ];
    }
    return $mapped;
  }



  /**
   * Returns diagnostics keyed by rule ID with only issue messages.
   *
   * @param array $rules
   *   Rules keyed by ID or numeric index.
   *
   * @return array<int|string, array<int, string>>
   *   Issue messages keyed by rule ID.
   */
  public function getIssues(array $rules): array {
    $diagnostics = $this->getDiagnostics();
    $issues = [];
    foreach ($rules as $rule) {
      if (!is_object($rule) || !method_exists($rule, 'id')) {
        continue;
      }
      $rule_id = $rule->id();
      if (isset($diagnostics[$rule_id])) {
        $issues[$rule_id] = $diagnostics[$rule_id]['issues'];
      }
    }
    return $issues;
  }

  protected function mapIssue(string $issue): string {
    return match ($issue) {
      'missing_bundle' => (string) $this->t('Відсутній набір або словник'),
      'missing_field' => (string) $this->t('Відсутнє поле'),
      'missing_term' => (string) $this->t('Відсутній термін'),
      'missing_target_entity' => (string) $this->t('Відсутня цільова сутність'),
      default => $issue,
    };
  }
}
