<?php

namespace Drupal\isc_redirect_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Drupal\isc_redirect_manager\Service\RedirectFailureLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders redirect rule usage statistics.
 */
class RedirectStatsController extends ControllerBase {

  protected EntityTypeManagerInterface $redirectEntityTypeManager;
  protected DateFormatterInterface $dateFormatter;
  protected RedirectFailureLogger $failureLogger;
  protected RequestStack $iscRedirectRequestStack;

  public function __construct(RedirectFailureLogger $failureLogger, EntityTypeManagerInterface $entityTypeManager, DateFormatterInterface $dateFormatter, RequestStack $requestStack) {
    $this->failureLogger = $failureLogger;
    $this->redirectEntityTypeManager = $entityTypeManager;
    $this->dateFormatter = $dateFormatter;
    $this->iscRedirectRequestStack = $requestStack;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('isc_redirect_manager.failure_logger'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  public function page(): array {
    $request = $this->iscRedirectRequestStack->getCurrentRequest();
    $bundle = trim((string) $request->query->get('bundle', ''));
    $q = trim((string) $request->query->get('q', ''));
    $rule_id = trim((string) $request->query->get('rule_id', ''));

    /** @var \Drupal\isc_redirect_manager\Entity\IscRedirectRule|null $current_rule */
    $current_rule = NULL;
    if ($rule_id !== '') {
      $loaded = $this->redirectEntityTypeManager->getStorage('isc_redirect_rule')->load($rule_id);
      if ($loaded instanceof IscRedirectRule) {
        $current_rule = $loaded;
      }
      else {
        $rule_id = '';
      }
    }

    return [
      '#attached' => ['library' => ['isc_redirect_manager/admin']],
      'top_actions' => $this->buildTopActions(),
      'filters' => $this->formBuilder()->getForm(\Drupal\isc_redirect_manager\Form\RedirectStatsFilterForm::class),
      'table_wrapper' => $this->buildTableCard($bundle, $q, $rule_id, $current_rule),
    ];
  }

  protected function buildTopActions(): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['isc-redirect-top-actions'],
      ],
      'clear_stats' => Link::fromTextAndUrl($this->t('Очистити статистику'), Url::fromRoute('isc_redirect_manager.stats_clear'))->toRenderable() + [
        '#attributes' => [
          'class' => ['button', 'button--primary', 'isc-redirect-nowrap'],
        ],
      ],
    ];
  }

  protected function buildTableCard(string $bundle, string $q, string $rule_id, ?IscRedirectRule $current_rule): array {
    $stats = $rule_id !== ''
      ? array_filter([(string) $rule_id => $this->failureLogger->getRuleStat((string) $rule_id)])
      : $this->failureLogger->getAllRuleStats();
    $rules = $this->redirectEntityTypeManager->getStorage('isc_redirect_rule')->loadMultiple(array_keys($stats));

    uasort($stats, static function (array $a, array $b): int {
      $by_hits = ((int) ($b['hits'] ?? 0)) <=> ((int) ($a['hits'] ?? 0));
      if ($by_hits !== 0) {
        return $by_hits;
      }
      return ((int) ($b['last_triggered'] ?? 0)) <=> ((int) ($a['last_triggered'] ?? 0));
    });

    $rows = [];
    foreach ($stats as $stat_rule_id => $item) {
      if ($rule_id !== '' && $stat_rule_id !== $rule_id) {
        continue;
      }

      $rule = $rules[$stat_rule_id] ?? NULL;
      if (!$rule) {
        continue;
      }

      if ($bundle !== '' && $rule->getBundle() !== $bundle) {
        continue;
      }

      $haystack = mb_strtolower(implode(' ', [
        $rule->label(),
        $rule->getBundle(),
        $rule->getDestination(),
        (string) ($item['last_nid'] ?? ''),
      ]));
      if ($q !== '' && !str_contains($haystack, mb_strtolower($q))) {
        continue;
      }

      $rule_cell = ['#plain_text' => $rule->label()];
      if ($rule->access('update') && $this->currentUser()->hasPermission('manage isc redirect rules')) {
        $rule_cell = Link::fromTextAndUrl(
          $rule->label(),
          Url::fromRoute('entity.isc_redirect_rule.edit_form', ['isc_redirect_rule' => $rule->id()])
        )->toRenderable();
      }

      $rows[] = [
        ['data' => $rule_cell],
        ['data' => ['#plain_text' => $rule->getBundle()]],
        ['data' => ['#plain_text' => (string) ((int) ($item['hits'] ?? 0))]],
        ['data' => ['#plain_text' => !empty($item['last_triggered']) ? $this->dateFormatter->format((int) $item['last_triggered'], 'short') : '-']],
        ['data' => ['#plain_text' => !empty($item['last_nid']) ? (string) ((int) ($item['last_nid'])) : '-']],
        ['data' => ['#plain_text' => (string) ($item['last_destination'] ?? '')]],
      ];
    }

    $title = $current_rule
      ? $this->t('Статистика за поточним правилом')
      : $this->t('Загальна статистика правил');

    $description = $current_rule
      ? $this->t('Таблиця нижче показує тільки записи для правила "@label".', ['@label' => $current_rule->label()])
      : $this->t('Таблиця нижче показує статистику для всіх правил.');

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['isc-redirect-card'],
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['isc-redirect-section-header']],
        'title' => ['#markup' => '<div class="isc-redirect-section-title">' . $title . '</div>'],
        'description' => ['#markup' => '<div class="isc-redirect-section-description">' . $description . '</div>'],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Rule'),
          $this->t('Тип матеріалів'),
          $this->t('Спрацювання'),
          $this->t('Last triggered'),
          $this->t('Last node ID'),
          $this->t('Last destination'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No redirect statistics yet.'),
      ],
    ];
  }

}
