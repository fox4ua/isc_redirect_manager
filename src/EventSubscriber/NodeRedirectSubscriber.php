<?php

namespace Drupal\isc_redirect_manager\EventSubscriber;

use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class NodeRedirectSubscriber implements EventSubscriberInterface {

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

    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface) {
      return;
    }

    $response = $this->matcher->match($node);
    if ($response !== NULL) {
      $event->setResponse($response);
    }
  }

}
