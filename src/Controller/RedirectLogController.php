<?php

namespace Drupal\isc_redirect_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Service\RedirectFailureLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the redirect fallback log.
 */
class RedirectLogController extends ControllerBase {

  protected RedirectFailureLogger $failureLogger;
  protected DateFormatterInterface $dateFormatter;

  public function __construct(RedirectFailureLogger $failureLogger, DateFormatterInterface $dateFormatter) {
    $this->failureLogger = $failureLogger;
    $this->dateFormatter = $dateFormatter;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('isc_redirect_manager.failure_logger'),
      $container->get('date.formatter'),
    );
  }

  public function page(): array {
    $rows = [];
    foreach ($this->failureLogger->getLog() as $item) {
      $rule_cell = (string) ($item['rule_label'] ?? '');
      if (!empty($item['rule_id']) && $this->currentUser()->hasPermission('manage isc redirect rules')) {
        $url = Url::fromRoute('entity.isc_redirect_rule.edit_form', ['isc_redirect_rule' => $item['rule_id']]);
        $rule_cell = Link::fromTextAndUrl((string) ($item['rule_label'] ?? $item['rule_id']), $url)->toString();
      }

      $rows[] = [
        !empty($item['timestamp']) ? $this->dateFormatter->format((int) $item['timestamp'], 'short') : '-',
        $item['event_type'] ?? 'fallback',
        ['data' => ['#markup' => $rule_cell]],
        $item['nid'] ?? '',
        $item['langcode'] ?? '',
        $item['base_destination'] ?? '',
        $item['built_destination'] ?? '',
        $item['reason'] ?? '',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Time'),
        $this->t('Event'),
        $this->t('Rule'),
        $this->t('Node ID'),
        $this->t('Language'),
        $this->t('Base destination'),
        $this->t('Built destination'),
        $this->t('Reason'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No redirect failures logged yet.'),
    ];
  }

}
