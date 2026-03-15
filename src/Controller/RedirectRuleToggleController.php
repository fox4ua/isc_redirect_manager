<?php

namespace Drupal\isc_redirect_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;

class RedirectRuleToggleController extends ControllerBase {

  public function enable(IscRedirectRule $isc_redirect_rule) {
    $isc_redirect_rule->set('enabled', TRUE);
    $isc_redirect_rule->save();
    $this->messenger()->addStatus($this->t('Rule enabled.'));
    return $this->redirect('entity.isc_redirect_rule.collection');
  }

  public function disable(IscRedirectRule $isc_redirect_rule) {
    $isc_redirect_rule->set('enabled', FALSE);
    $isc_redirect_rule->save();
    $this->messenger()->addStatus($this->t('Rule disabled.'));
    return $this->redirect('entity.isc_redirect_rule.collection');
  }

}
