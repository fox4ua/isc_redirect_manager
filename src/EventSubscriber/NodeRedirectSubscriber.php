<?php
namespace Drupal\isc_redirect_manager\EventSubscriber;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\isc_redirect_manager\Service\RedirectRuleMatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
class NodeRedirectSubscriber implements EventSubscriberInterface {
  public function __construct(protected RedirectRuleMatcher $matcher, protected AdminContext $adminContext) {}
  public static function getSubscribedEvents(): array { return [KernelEvents::REQUEST => ['onRequest', 30]]; }
  public function onRequest(RequestEvent $event): void { if(!$event->isMainRequest()) return; $r=$event->getRequest(); if($r->getMethod()!=='GET'||$r->isXmlHttpRequest()) return; $route=(string)$r->attributes->get('_route'); if(!in_array($route,['entity.node.canonical','entity.taxonomy_term.canonical'],TRUE)) return; $ro=$r->attributes->get('_route_object'); if($ro && $this->adminContext->isAdminRoute($ro)) return; $entity=$route==='entity.taxonomy_term.canonical' ? $r->attributes->get('taxonomy_term') : $r->attributes->get('node'); if(!$entity instanceof ContentEntityInterface) return; $response=$this->matcher->match($entity); if($response) $event->setResponse($response); }
}
