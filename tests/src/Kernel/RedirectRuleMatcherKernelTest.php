<?php

declare(strict_types=1);

namespace Drupal\Tests\isc_redirect_manager\Kernel;

use Drupal\isc_redirect_manager\Service\RedirectFailureLogger;
use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Covers the redirect matcher service.
 *
 * @group isc_redirect_manager
 */
final class RedirectRuleMatcherKernelTest extends RedirectKernelTestBase {

  public function testNodeBundleRuleReturnsRedirectResponse(): void {
    $node = $this->createNode('article', 'Bundle match');
    $this->pushNodeRequest($node);

    $rule = $this->createRule([
      'id' => 'bundle_match_rule',
      'label' => 'Bundle match rule',
      'destination' => '<front>',
      'language_mode' => 'neutral',
    ]);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectRuleMatcher $matcher */
    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $response = $matcher->match($node);

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/', $response->getTargetUrl());
    $this->assertSame(302, $response->getStatusCode());

    /** @var \Drupal\isc_redirect_manager\Service\RedirectFailureLogger $logger */
    $logger = $this->container->get('isc_redirect_manager.failure_logger');
    $stats = $logger->getRuleStats();
    $this->assertSame(1, (int) $stats[$rule->id()]['hits']);

    $this->popRequest();
  }

  public function testTaxonomyEntityIdRuleReturnsRedirectResponse(): void {
    $term = $this->createTerm('tags', 'Physics');
    $this->pushTermRequest($term);

    $this->createRule([
      'id' => 'taxonomy_entity_rule',
      'label' => 'Taxonomy entity rule',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'tags',
      'match_mode' => 'entity_id',
      'target_entity_id' => (string) $term->id(),
      'destination' => '<front>',
      'language_mode' => 'neutral',
    ]);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectRuleMatcher $matcher */
    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $response = $matcher->match($term);

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/', $response->getTargetUrl());

    $this->popRequest();
  }

  public function testInvalidDestinationIsLoggedAndSkipped(): void {
    $node = $this->createNode('article', 'Broken destination');
    $this->pushNodeRequest($node);

    $rule = $this->createRule([
      'id' => 'invalid_destination_rule',
      'label' => 'Invalid destination rule',
      'destination' => '/this-page-does-not-exist-anywhere',
      'language_mode' => 'neutral',
    ]);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectRuleMatcher $matcher */
    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $response = $matcher->match($node);

    $this->assertNull($response);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectFailureLogger $logger */
    $logger = $this->container->get('isc_redirect_manager.failure_logger');
    $log = $logger->getLog();
    $entry = reset($log);

    $this->assertSame('invalid_destination', $entry['event_type']);
    $this->assertSame($rule->id(), $entry['rule_id']);
    $this->assertSame((int) $node->id(), (int) $entry['nid']);

    $this->popRequest();
  }

  public function testRedirectLoopIsLoggedAndBlocked(): void {
    $node = $this->createNode('article', 'Loop target');
    $this->pushNodeRequest($node);

    $rule = $this->createRule([
      'id' => 'loop_rule',
      'label' => 'Loop rule',
      'destination' => '/node/' . $node->id(),
      'language_mode' => 'neutral',
    ]);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectRuleMatcher $matcher */
    $matcher = $this->container->get('isc_redirect_manager.matcher');
    $response = $matcher->match($node);

    $this->assertNull($response);

    /** @var \Drupal\isc_redirect_manager\Service\RedirectFailureLogger $logger */
    $logger = $this->container->get('isc_redirect_manager.failure_logger');
    $log = $logger->getLog();
    $entry = reset($log);

    $this->assertSame('redirect_loop', $entry['event_type']);
    $this->assertSame($rule->id(), $entry['rule_id']);

    $this->popRequest();
  }

}
