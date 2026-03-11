<?php

namespace Drupal\isc_redirect_manager\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RedirectRuleMatcher {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public function match(NodeInterface $node) {
    $rules = $this->entityTypeManager->getStorage('isc_redirect_rule')->loadMultiple();

    uasort($rules, static function ($a, $b) {
      $weight_a = (int) ($a->get('weight') ?? 0);
      $weight_b = (int) ($b->get('weight') ?? 0);

      if ($weight_a === $weight_b) {
        return strnatcasecmp($a->label(), $b->label());
      }

      return $weight_a <=> $weight_b;
    });

    foreach ($rules as $rule) {
      if (!$rule->status()) {
        continue;
      }
      if ((string) ($rule->get('bundle') ?: '') !== $node->bundle()) {
        continue;
      }

      $field_name = (string) ($rule->get('field_name') ?: '');
      $condition_type = (string) ($rule->get('condition_type') ?: '');
      $vocabulary = (string) ($rule->get('vocabulary') ?: '');
      $match_value = (string) ($rule->get('match_value') ?: '');

      if ($field_name === '' || $condition_type === '' || $match_value === '') {
        continue;
      }
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }

      if (!$this->fieldMatches($node, $field_name, $condition_type, $vocabulary, $match_value)) {
        continue;
      }

      $destination = trim((string) ($rule->get('destination') ?: ''));
      if ($destination === '') {
        continue;
      }

      $status_code = (int) ($rule->get('status_code') ?: 302);
      if (!in_array($status_code, [301, 302], TRUE)) {
        $status_code = 302;
      }

      return new RedirectResponse($destination, $status_code);
    }

    return NULL;
  }

  protected function fieldMatches(NodeInterface $node, $field_name, $condition_type, $vocabulary, $match_value) {
    $field = $node->get($field_name);

    if ($condition_type === 'taxonomy_term') {
      foreach ($field->referencedEntities() as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }
        if ($vocabulary !== '' && $term->bundle() !== $vocabulary) {
          continue;
        }
        if ((string) $term->id() === $match_value) {
          return TRUE;
        }
      }
      return FALSE;
    }

    if (in_array($condition_type, ['list_string', 'list_integer', 'boolean'], TRUE)) {
      $current_value = isset($field->value) ? (string) $field->value : '';
      return $current_value === $match_value;
    }

    return FALSE;
  }

}
