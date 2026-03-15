<?php

namespace Drupal\isc_redirect_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects the module entry route to the default tab.
 */
class RedirectModuleController extends ControllerBase {

  /**
   * Redirects to the node rules tab.
   */
  public function redirectToDefaultTab(): RedirectResponse {
    return $this->redirect('isc_redirect_manager.node_rules');
  }

}
