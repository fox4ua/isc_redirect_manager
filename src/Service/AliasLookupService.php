<?php

namespace Drupal\isc_redirect_manager\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Centralizes alias lookups and canonical-path comparisons.
 */
class AliasLookupService {

  public function __construct(
    protected Connection $database,
    protected PathValidatorInterface $pathValidator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AliasManagerInterface $aliasManager,
  ) {}

  /**
   * Finds public, accessible alias suggestions.
   *
   * @return array<int, array{value:string,label:string,canonical:string}>
   */
  public function searchAliases(string $q, int $limit = 10): array {
    $results = [];
    $query = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['alias', 'path'])
      ->range(0, max(20, $limit * 3))
      ->orderBy('pa.alias', 'ASC');

    $or = $query->orConditionGroup()
      ->condition('pa.alias', '%' . $this->database->escapeLike($q) . '%', 'LIKE')
      ->condition('pa.path', '%' . $this->database->escapeLike($q) . '%', 'LIKE');
    $query->condition($or);

    foreach ($query->execute()->fetchAll() as $row) {
      if (count($results) >= $limit) {
        break;
      }

      $alias = (string) $row->alias;
      $internal = (string) $row->path;
      if ($alias === '') {
        continue;
      }

      if (preg_match('#^/node/(\d+)$#', $internal, $match)) {
        $node = $this->entityTypeManager->getStorage('node')->load((int) $match[1]);
        if (!$node || !$node->isPublished() || !$node->access('view')) {
          continue;
        }
        $results[] = [
          'value' => $alias,
          'label' => $node->label() . ' (' . $alias . ')',
          'canonical' => $this->canonicalPath($alias),
        ];
        continue;
      }

      if (preg_match('#^/taxonomy/term/(\d+)$#', $internal, $match)) {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) $match[1]);
        if (!$term || !$term->access('view')) {
          continue;
        }
        $results[] = [
          'value' => $alias,
          'label' => $term->label() . ' (' . $alias . ')',
          'canonical' => $this->canonicalPath($alias),
        ];
        continue;
      }

      $url = $this->pathValidator->getUrlIfValid($alias);
      if ($url && (!method_exists($url, 'access') || $url->access())) {
        $results[] = [
          'value' => $alias,
          'label' => $alias . ' [' . $internal . ']',
          'canonical' => $this->canonicalPath($alias),
        ];
      }
    }

    return $results;
  }

  /**
   * Converts aliases to a stable internal path for comparisons.
   */
  public function canonicalPath(string $path): string {
    $path = '/' . ltrim($path, '/');
    $url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($path);
    if ($url && method_exists($url, 'isRouted') && $url->isRouted()) {
      return '/' . ltrim($url->getInternalPath(), '/');
    }
    return rtrim($path, '/') ?: '/';
  }

  /**
   * Returns a preferred display path for an internal route.
   */
  public function displayPath(string $internal): string {
    $alias = $this->aliasManager->getAliasByPath($internal);
    return $alias !== $internal ? $alias : $internal;
  }

}
