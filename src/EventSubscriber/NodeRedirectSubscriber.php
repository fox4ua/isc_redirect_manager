<?php

namespace Drupal\isc_redirect_manager\EventSubscriber;

use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class NodeRedirectSubscriber implements EventSubscriberInterface {

  protected const REDIRECT_GUARD_KEY = '_isc_redirect_guard';

  protected $matcher;

  public function __construct(RedirectRuleMatcher $matcher) {
    $this->matcher = $matcher;
  }

  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  public function onRequest(RequestEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'entity.node.canonical') {
      return;
    }

    if ($request->attributes->get(self::REDIRECT_GUARD_KEY)) {
      return;
    }

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface) {
      return;
    }

    $response = $this->matcher->match($node);
    if ($response !== NULL) {
      $target_path = parse_url($response->getTargetUrl(), PHP_URL_PATH) ?: '';
      $current_path = $request->getPathInfo();
      if ($target_path === $current_path) {
        return;
      }
      $request->attributes->set(self::REDIRECT_GUARD_KEY, TRUE);
      $event->setResponse($response);
    }
  }

}
