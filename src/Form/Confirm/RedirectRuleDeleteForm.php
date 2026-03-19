<?php

namespace Drupal\isc_redirect_manager\Form\Confirm;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;

/**
 * Форма підтвердження видалення правила переадресації.
 */
final class RedirectRuleDeleteForm extends EntityDeleteForm {

  public function getCancelUrl(): Url {
    $entity = $this->getEntity();
    if ($entity instanceof IscRedirectRule) {
      return Url::fromRoute('isc_redirect_manager.bundle_rules', [
        'entity_type' => $entity->getTargetEntityType(),
        'bundle' => $entity->getBundle(),
      ]);
    }

    return parent::getCancelUrl();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity = $this->getEntity();
    parent::submitForm($form, $form_state);

    if ($entity instanceof IscRedirectRule) {
      $form_state->setRedirect('isc_redirect_manager.bundle_rules', [
        'entity_type' => $entity->getTargetEntityType(),
        'bundle' => $entity->getBundle(),
      ]);
    }
  }

}
