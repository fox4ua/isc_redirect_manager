<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared helpers for ISC Redirect Manager kernel tests.
 */
abstract class RedirectKernelTestBase extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'filter',
    'text',
    'field',
    'node',
    'taxonomy',
    'path_alias',
    'language',
    'options',
    'isc_redirect_manager',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'node', 'taxonomy', 'path_alias', 'language', 'isc_redirect_manager']);
    $this->installSchema('isc_redirect_manager', [
      'isc_redirect_manager_log',
      'isc_redirect_manager_stats',
      'isc_redirect_manager_stats_daily',
    ]);

    if (!NodeType::load('article')) {
      NodeType::create([
        'type' => 'article',
        'name' => 'Article',
      ])->save();
    }

    if (!Vocabulary::load('tags')) {
      Vocabulary::create([
        'vid' => 'tags',
        'name' => 'Tags',
      ])->save();
    }
  }

  protected function createNode(string $type = 'article', string $title = 'Test node'): Node {
    $node = Node::create([
      'type' => $type,
      'title' => $title,
    ]);
    $node->save();
    return $node;
  }

  protected function createTerm(string $vid = 'tags', string $name = 'Test term'): Term {
    $term = Term::create([
      'vid' => $vid,
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

  protected function createNodeAlias(Node $node, string $alias): void {
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => $alias,
      'langcode' => 'en',
    ])->save();
  }

  protected function pushNodeRequest(Node $node): void {
    $request = Request::create('/node/' . $node->id());
    $request->attributes->set('_route', 'entity.node.canonical');
    $request->attributes->set('node', $node);
    $this->container->get('request_stack')->push($request);
  }

  protected function pushTermRequest(Term $term): void {
    $request = Request::create('/taxonomy/term/' . $term->id());
    $request->attributes->set('_route', 'entity.taxonomy_term.canonical');
    $request->attributes->set('taxonomy_term', $term);
    $this->container->get('request_stack')->push($request);
  }

  protected function popRequest(): void {
    $this->container->get('request_stack')->pop();
  }

  protected function createRule(array $values = []) {
    $storage = $this->container->get('entity_type.manager')->getStorage('isc_redirect_rule');
    $defaults = [
      'id' => 'rule_' . substr(hash('sha256', serialize($values) . microtime(TRUE)), 0, 12),
      'label' => 'Test rule',
      'enabled' => TRUE,
      'entity_type' => 'node',
      'bundle' => 'article',
      'match_mode' => 'entity_bundle',
      'target_entity_id' => '',
      'field_name' => '',
      'condition_type' => '',
      'vocabulary' => '',
      'match_value' => '',
      'match_label' => '',
      'destination' => '<front>',
      'language_mode' => 'neutral',
      'target_langcode' => '',
      'status_code' => 302,
      'weight' => 0,
    ];

    $rule = $storage->create($values + $defaults);
    $rule->save();
    return $rule;
  }

}
