<?php

namespace Drupal\isc_redirect_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Service\RedirectFailureLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm form for clearing redirect statistics.
 */
class ClearRedirectStatsForm extends ConfirmFormBase {

  protected RedirectFailureLogger $failureLogger;

  public function __construct(RedirectFailureLogger $failureLogger) {
    $this->failureLogger = $failureLogger;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('isc_redirect_manager.failure_logger'));
  }

  public function getFormId(): string {
    return 'isc_redirect_manager_clear_stats_form';
  }

  public function getQuestion(): string {
    return (string) $this->t('Очистити всю статистику редиректів?');
  }

  public function getDescription(): string {
    return (string) $this->t('Усі накопичені лічильники спрацювань буде видалено. Цю дію не можна скасувати.');
  }

  public function getConfirmText(): string {
    return (string) $this->t('Очистити статистику');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('isc_redirect_manager.stats');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->failureLogger->clearStats();
    $this->messenger()->addStatus($this->t('Статистику очищено.'));
    $form_state->setRedirect('isc_redirect_manager.stats');
  }

}
