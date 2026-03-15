<?php

namespace Drupal\isc_redirect_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\isc_redirect_manager\Service\AliasLookupService;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides autocomplete suggestions for redirect destinations.
 */
class DestinationAutocompleteController extends ControllerBase {

  protected EntityTypeManagerInterface $redirectEntityTypeManager;
  protected AliasManagerInterface $aliasManager;
  protected PathValidatorInterface $pathValidator;
  protected AliasLookupService $aliasLookup;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AliasManagerInterface $aliasManager,
    PathValidatorInterface $pathValidator,
    AliasLookupService $aliasLookup,
  ) {
    $this->redirectEntityTypeManager = $entityTypeManager;
    $this->aliasManager = $aliasManager;
    $this->pathValidator = $pathValidator;
    $this->aliasLookup = $aliasLookup;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('path_alias.manager'),
      $container->get('path.validator'),
      $container->get('isc_redirect_manager.alias_lookup'),
    );
  }

  public function autocomplete(Request $request): JsonResponse {
    $q = trim((string) $request->query->get('q', ''));
    if ($q === '') {
      return new JsonResponse([]);
    }

    $candidates = [];
    $seen = [];
    $limit = 10;

    $collect = function (string $value, string $label, string $canonical, string $type) use (&$candidates, &$seen, $q): void {
      if (isset($seen[$canonical])) {
        return;
      }
      $seen[$canonical] = TRUE;

      $score = $this->scoreCandidate($q, $value, $label, $type);
      $candidates[] = [
        'value' => $value,
        'label' => $label,
        'canonical' => $canonical,
        'score' => $score,
      ];
    };

    if (stripos('<front>', $q) !== FALSE || stripos('front page', $q) !== FALSE) {
      $collect('<front>', 'Front page (<front>)', '<front>', 'front');
    }

    $this->addAliasMatches($q, $collect);
    $this->addViewMatches($q, $collect);
    $this->addDirectInternalPathMatches($q, $collect);
    $this->addNodeMatches($q, $collect);
    $this->addTermMatches($q, $collect);

    usort($candidates, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strnatcasecmp($a['value'], $b['value']));

    $results = [];
    foreach (array_slice($candidates, 0, $limit) as $item) {
      $results[] = [
        'value' => $item['value'],
        'label' => $item['label'],
      ];
    }

    return new JsonResponse($results);
  }

  protected function addDirectInternalPathMatches(string $q, callable $collect): void {
    $q = trim($q);
    if (preg_match('#^/node/(\d+)$#', $q, $matches)) {
      $node = $this->redirectEntityTypeManager->getStorage('node')->load((int) $matches[1]);
      if ($node && $node->isPublished() && $node->access('view')) {
        $internal = '/node/' . $node->id();
        $display = $this->aliasLookup->displayPath($internal);
        $collect($display, $node->label() . ' (' . $display . ')', $this->aliasLookup->canonicalPath($display), 'internal');
      }
    }

    if (preg_match('#^/taxonomy/term/(\d+)$#', $q, $matches)) {
      $term = $this->redirectEntityTypeManager->getStorage('taxonomy_term')->load((int) $matches[1]);
      if ($term && $term->access('view')) {
        $internal = '/taxonomy/term/' . $term->id();
        $display = $this->aliasLookup->displayPath($internal);
        $collect($display, $term->label() . ' (' . $display . ')', $this->aliasLookup->canonicalPath($display), 'internal');
      }
    }
  }

  protected function addAliasMatches(string $q, callable $collect): void {
    foreach ($this->aliasLookup->searchAliases($q, 25) as $item) {
      $collect($item['value'], $item['label'], $item['canonical'], 'alias');
    }
  }

  protected function addViewMatches(string $q, callable $collect): void {
    $needle = mb_strtolower($q);
    foreach ($this->redirectEntityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view->status()) {
        continue;
      }

      $view_label = (string) $view->label();
      foreach (($view->get('display') ?: []) as $display_id => $display) {
        if ((string) ($display['display_plugin'] ?? '') !== 'page') {
          continue;
        }

        $display_title = (string) ($display['display_title'] ?? $display_id);
        $path = (string) ($display['display_options']['path'] ?? '');
        if ($path === '') {
          continue;
        }

        $normalized = '/' . ltrim($path, '/');
        if (!$this->matchesAutocompleteNeedle($needle, [$view_label, $display_title, $normalized])) {
          continue;
        }

        $url = $this->pathValidator->getUrlIfValid($normalized);
        if (!$url || (method_exists($url, 'access') && !$url->access())) {
          continue;
        }

        $collect($normalized, $view_label . ' — ' . $display_title . ' (' . $normalized . ')', $this->aliasLookup->canonicalPath($normalized), 'view');
      }
    }
  }

  protected function addNodeMatches(string $q, callable $collect): void {
    $storage = $this->redirectEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('status', 1);
    $or = $query->orConditionGroup()->condition('title', $q, 'CONTAINS');
    if (ctype_digit($q)) {
      $or->condition('nid', (int) $q);
    }

    $ids = $query->condition($or)->range(0, 10)->execute();
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node->access('view')) {
        continue;
      }
      $internal = '/node/' . $node->id();
      $display = $this->aliasLookup->displayPath($internal);
      if (!$this->matchesAutocompleteNeedle($q, [$node->label(), $display, $internal, (string) $node->id()])) {
        continue;
      }
      $collect($display, $node->label() . ' (' . $display . ')', $this->aliasLookup->canonicalPath($display), 'node');
    }
  }

  protected function addTermMatches(string $q, callable $collect): void {
    $storage = $this->redirectEntityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()->accessCheck(TRUE);
    $or = $query->orConditionGroup()->condition('name', $q, 'CONTAINS');
    if (ctype_digit($q)) {
      $or->condition('tid', (int) $q);
    }

    $ids = $query->condition($or)->range(0, 10)->execute();
    foreach ($storage->loadMultiple($ids) as $term) {
      if (!$term->access('view')) {
        continue;
      }
      $internal = '/taxonomy/term/' . $term->id();
      $display = $this->aliasLookup->displayPath($internal);
      if (!$this->matchesAutocompleteNeedle($q, [$term->label(), $display, $internal, (string) $term->id()])) {
        continue;
      }
      $collect($display, $term->label() . ' (' . $display . ')', $this->aliasLookup->canonicalPath($display), 'term');
    }
  }

  protected function matchesAutocompleteNeedle(string $needle, array $candidates): bool {
    $needle = mb_strtolower(trim($needle));
    if ($needle === '') {
      return FALSE;
    }

    foreach ($candidates as $candidate) {
      if (str_contains(mb_strtolower((string) $candidate), $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  protected function scoreCandidate(string $query, string $value, string $label, string $type): int {
    $query = mb_strtolower(trim($query));
    $value_l = mb_strtolower($value);
    $label_l = mb_strtolower($label);
    $score = 0;

    if ($value_l === $query) {
      $score += 1000;
    }
    elseif (str_starts_with($value_l, $query)) {
      $score += 700;
    }
    elseif (str_contains($value_l, $query)) {
      $score += 350;
    }

    if (str_contains($label_l, $query)) {
      $score += 80;
    }

    $score += match ($type) {
      'front' => 250,
      'view' => 180,
      'alias' => 120,
      'internal' => 90,
      'node' => 60,
      'term' => 50,
      default => 0,
    };

    $segments = substr_count(trim($value, '/'), '/');
    $score -= $segments * 30;
    $score -= mb_strlen($value);

    return $score;
  }

}
