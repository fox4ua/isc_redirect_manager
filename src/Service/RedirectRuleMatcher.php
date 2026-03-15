<?php

namespace Drupal\isc_redirect_manager\Service;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\isc_redirect_manager\Entity\IscRedirectRule;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Matches redirect rules against entity pages using compiled runtime cache.
 */
class RedirectRuleMatcher {

  private const COMPILED_CACHE_ID = 'isc_redirect_manager:compiled_rules:v3';
  private const COMPILED_VERSION = 3;

  /**
   * Per-request normalized destination cache.
   *
   * @var array<string, ?string>
   */
  protected array $normalizedDestinationStatic = [];

  /**
   * Per-request compiled rules cache.
   */
  protected ?array $compiledRuntimeRules = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PathValidatorInterface $pathValidator,
    protected RequestStack $requestStack,
    protected LoggerChannelInterface $logger,
    protected CacheBackendInterface $cacheBackend,
    protected LanguageManagerInterface $languageManager,
    protected RedirectFailureLogger $failureLogger,
    protected AliasManagerInterface $aliasManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected LockBackendInterface $lock,
  ) {}

  public function match(ContentEntityInterface $entity): ?RedirectResponse {
    $entity = $this->getTranslatedEntity($entity);
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    $compiled = $this->getCompiledRuntimeRules();
    $rules = $compiled['active'][$entity_type . ':' . $bundle] ?? [];
    if ($rules === []) {
      return NULL;
    }

    $current_langcode = $this->getCurrentContentLangcode();
    foreach ($rules as $rule) {
      if (!$this->runtimeRuleMatchesEntity($rule, $entity)) {
        continue;
      }

      $destination = $this->buildDestinationFromParts(
        (string) $rule['destination'],
        (string) $rule['language_mode'],
        (string) $rule['target_langcode'],
        $current_langcode,
      );

      $validated_destination = $this->normalizeDestination($destination, TRUE);
      if ($validated_destination === NULL) {
        $this->failureLogger->logFailure([
          'event_type' => 'invalid_destination',
          'rule_id' => (string) $rule['id'],
          'rule_label' => (string) $rule['label'],
          'nid' => (int) $entity->id(),
          'langcode' => $current_langcode,
          'base_destination' => (string) $rule['destination'],
          'built_destination' => $destination,
          'reason' => 'Destination is invalid, inaccessible or does not resolve in the selected language.',
        ]);
        continue;
      }

      if ($this->isRedirectLoop($entity, $validated_destination)) {
        $this->failureLogger->logFailure([
          'event_type' => 'redirect_loop',
          'rule_id' => (string) $rule['id'],
          'rule_label' => (string) $rule['label'],
          'nid' => (int) $entity->id(),
          'langcode' => $current_langcode,
          'base_destination' => (string) $rule['destination'],
          'built_destination' => $validated_destination,
          'reason' => 'Redirect loop detected: destination resolves to the current entity page.',
        ]);
        continue;
      }

      $this->failureLogger->incrementRuleHit((string) $rule['id'], (int) $entity->id(), $validated_destination);
      return new RedirectResponse($validated_destination, (int) $rule['status_code']);
    }

    return NULL;
  }

  public function isConfigurationDestinationValid(string $destination, string $language_mode, string $target_langcode = ''): bool {
    if ($destination === '' || UrlHelper::isExternal($destination)) {
      return FALSE;
    }

    if ($language_mode === 'fixed') {
      return $this->normalizeDestination($this->buildDestinationFromParts($destination, 'fixed', $target_langcode, $target_langcode), TRUE) !== NULL;
    }
    if ($language_mode === 'neutral') {
      return $this->normalizeDestination($this->buildDestinationFromParts($destination, 'neutral', '', ''), TRUE) !== NULL;
    }

    foreach (array_keys($this->languageManager->getLanguages()) as $langcode) {
      if ($this->normalizeDestination($this->buildDestinationFromParts($destination, 'content', '', $langcode), TRUE) !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function hasEnabledConflict(IscRedirectRule $candidate): bool {
    $rules = $this->entityTypeManager->getStorage('isc_redirect_rule')->loadByProperties([
      'enabled' => TRUE,
      'entity_type' => $candidate->getTargetEntityType(),
      'bundle' => $candidate->getBundle(),
    ]);
    $signature = $this->buildConflictSignature($candidate);
    foreach ($rules as $rule) {
      if (!$rule instanceof IscRedirectRule || $rule->id() === $candidate->id()) {
        continue;
      }
      if ($this->buildConflictSignature($rule) === $signature) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function getCompiledDiagnostics(): array {
    return $this->getCompiledRuntimeRules()['diagnostics'] ?? [];
  }

  protected function getCompiledRuntimeRules(): array {
    if ($this->compiledRuntimeRules !== NULL && $this->isCompiledPayloadValid($this->compiledRuntimeRules)) {
      return $this->compiledRuntimeRules;
    }

    $cached = $this->cacheBackend->get(self::COMPILED_CACHE_ID);
    if ($cached && $this->isCompiledPayloadValid($cached->data)) {
      return $this->compiledRuntimeRules = $cached->data;
    }

    $lock_name = 'isc_redirect_manager.rules_rebuild';
    if ($this->lock->acquire($lock_name, 10.0)) {
      try {
        $cached = $this->cacheBackend->get(self::COMPILED_CACHE_ID);
        if ($cached && $this->isCompiledPayloadValid($cached->data)) {
          return $this->compiledRuntimeRules = $cached->data;
        }
        $compiled = $this->buildCompiledRuntimeRules();
        $this->cacheBackend->set(self::COMPILED_CACHE_ID, $compiled, CacheBackendInterface::CACHE_PERMANENT, ['isc_redirect_manager_rules']);
        return $this->compiledRuntimeRules = $compiled;
      }
      finally {
        $this->lock->release($lock_name);
      }
    }

    usleep(150000);
    $cached = $this->cacheBackend->get(self::COMPILED_CACHE_ID);
    if ($cached && $this->isCompiledPayloadValid($cached->data)) {
      return $this->compiledRuntimeRules = $cached->data;
    }

    return $this->compiledRuntimeRules = $this->buildCompiledRuntimeRules();
  }

  protected function isCompiledPayloadValid(mixed $data): bool {
    return is_array($data)
      && (int) ($data['version'] ?? 0) === self::COMPILED_VERSION
      && isset($data['active']) && is_array($data['active'])
      && isset($data['diagnostics']) && is_array($data['diagnostics']);
  }

  protected function buildCompiledRuntimeRules(): array {
    $result = [
      'version' => self::COMPILED_VERSION,
      'active' => [],
      'diagnostics' => [],
      'rebuilt' => time(),
    ];

    $bundle_map = [
      'node' => array_keys($this->bundleInfo->getBundleInfo('node')),
      'taxonomy_term' => array_keys($this->bundleInfo->getBundleInfo('taxonomy_term')),
    ];

    $rules = $this->entityTypeManager->getStorage('isc_redirect_rule')->loadByProperties(['enabled' => TRUE]);
    foreach ($rules as $rule) {
      if (!$rule instanceof IscRedirectRule || !$rule->isEnabled()) {
        continue;
      }

      $diagnostics = $this->getRuleIntegrityIssues($rule, $bundle_map);
      if ($diagnostics !== []) {
        $result['diagnostics'][(string) $rule->id()] = [
          'rule_id' => (string) $rule->id(),
          'label' => (string) $rule->label(),
          'bundle' => $rule->getBundle(),
          'entity_type' => $rule->getTargetEntityType(),
          'destination' => $rule->getDestination(),
          'issues' => $diagnostics,
        ];
        continue;
      }

      $key = $rule->getTargetEntityType() . ':' . $rule->getBundle();
      $result['active'][$key][] = [
        'id' => (string) $rule->id(),
        'label' => (string) $rule->label(),
        'entity_type' => $rule->getTargetEntityType(),
        'bundle' => $rule->getBundle(),
        'match_mode' => $rule->getMatchMode(),
        'target_entity_id' => $rule->getTargetEntityId(),
        'field_name' => $rule->getFieldName(),
        'condition_type' => $rule->getConditionType(),
        'vocabulary' => $rule->getVocabulary(),
        'match_value' => $rule->getMatchValue(),
        'destination' => $rule->getDestination(),
        'language_mode' => $rule->getLanguageMode(),
        'target_langcode' => $rule->getTargetLangcode(),
        'status_code' => $rule->getStatusCode(),
        'weight' => $rule->getWeight(),
      ];
    }

    foreach ($result['active'] as &$items) {
      usort($items, static function (array $a, array $b): int {
        $weight = ((int) $a['weight']) <=> ((int) $b['weight']);
        if ($weight !== 0) {
          return $weight;
        }
        return strnatcasecmp((string) $a['label'], (string) $b['label']);
      });
    }

    return $result;
  }

  protected function getRuleIntegrityIssues(IscRedirectRule $rule, array $bundle_map): array {
    $issues = [];
    $entity_type = $rule->getTargetEntityType();
    $bundle = $rule->getBundle();
    if ($bundle === '' || !in_array($bundle, $bundle_map[$entity_type] ?? [], TRUE)) {
      $issues[] = 'missing_bundle';
      return $issues;
    }

    if ($rule->getMatchMode() === 'entity_id' && $rule->getTargetEntityId() !== '') {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($rule->getTargetEntityId());
      if (!$entity) {
        $issues[] = 'missing_target_entity';
      }
    }

    if ($rule->getMatchMode() === 'field_value') {
      $field_name = $rule->getFieldName();
      $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
      if ($field_name === '' || !isset($definitions[$field_name])) {
        $issues[] = 'missing_field';
        return $issues;
      }

      if ($rule->getConditionType() === 'taxonomy_term' && $rule->getMatchValue() !== '') {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($rule->getMatchValue());
        if (!$term) {
          $issues[] = 'missing_term';
        }
      }
    }

    return $issues;
  }

  protected function ruleMatchesEntity(IscRedirectRule $rule, ContentEntityInterface $entity): bool {
    if ($rule->getMatchMode() === 'entity_bundle') {
      return TRUE;
    }
    if ($rule->getMatchMode() === 'entity_id') {
      return (string) $entity->id() === $rule->getTargetEntityId();
    }
    $field_name = $rule->getFieldName();
    $condition_type = $rule->getConditionType();
    $match_value = $rule->getMatchValue();
    if ($field_name === '' || $condition_type === '' || $match_value === '') {
      return FALSE;
    }
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return FALSE;
    }
    return $this->fieldMatches($entity, $field_name, $condition_type, $rule->getVocabulary(), $match_value);
  }

  protected function runtimeRuleMatchesEntity(array $rule, ContentEntityInterface $entity): bool {
    $mode = (string) ($rule['match_mode'] ?? 'field_value');
    if ($mode === 'entity_bundle') {
      return TRUE;
    }
    if ($mode === 'entity_id') {
      return (string) $entity->id() === (string) ($rule['target_entity_id'] ?? $rule['match_value'] ?? '');
    }

    $field_name = (string) ($rule['field_name'] ?? '');
    $condition_type = (string) ($rule['condition_type'] ?? '');
    $match_value = (string) ($rule['match_value'] ?? '');
    $vocabulary = (string) ($rule['vocabulary'] ?? '');
    if ($field_name === '' || $condition_type === '' || $match_value === '') {
      return FALSE;
    }
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return FALSE;
    }

    return $this->fieldMatches($entity, $field_name, $condition_type, $vocabulary, $match_value);
  }

  protected function fieldMatches(ContentEntityInterface $entity, string $field_name, string $condition_type, string $vocabulary, string $match_value): bool {
    $field = $entity->get($field_name);
    if ($condition_type === 'taxonomy_term') {
      foreach ($field->referencedEntities() as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }
        if ($vocabulary !== '' && $term->bundle() !== $vocabulary) {
          continue;
        }
        if ((string) $term->id() === $match_value) {
          return TRUE;
        }
      }
      return FALSE;
    }
    foreach ($field->getValue() as $item) {
      if ((string) ($item['value'] ?? '') === $match_value) {
        return TRUE;
      }
    }
    return FALSE;
  }

  protected function getTranslatedEntity(ContentEntityInterface $entity): ContentEntityInterface {
    $langcode = $this->getCurrentContentLangcode();
    return $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
  }

  protected function getCurrentContentLangcode(): string {
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

  protected function buildDestinationFromParts(string $destination, string $language_mode, string $target_langcode, string $current_langcode): string {
    $destination = '/' . ltrim($destination, '/');
    if ($destination === '/<front>') {
      return '<front>';
    }
    $destination = $this->stripLanguagePrefix($destination);
    if ($language_mode === 'neutral') {
      return $destination;
    }
    $langcode = $language_mode === 'fixed' ? $target_langcode : $current_langcode;
    $languages = $this->languageManager->getLanguages();
    if ($langcode === '' || !isset($languages[$langcode])) {
      return $destination;
    }
    return '/' . $langcode . ($destination === '/' ? '' : $destination);
  }

  protected function stripLanguagePrefix(string $path): string {
    foreach (array_keys($this->languageManager->getLanguages()) as $langcode) {
      $prefix = '/' . $langcode;
      if ($path === $prefix) {
        return '/';
      }
      if (str_starts_with($path, $prefix . '/')) {
        return substr($path, strlen($prefix)) ?: '/';
      }
    }
    return $path;
  }

  protected function normalizeDestination(string $destination, bool $check_access = FALSE): ?string {
    $key = ($check_access ? '1' : '0') . ':' . $destination;
    if (array_key_exists($key, $this->normalizedDestinationStatic)) {
      return $this->normalizedDestinationStatic[$key];
    }

    $resolved = NULL;
    if ($destination !== '' && !UrlHelper::isExternal($destination)) {
      $url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($destination);
      if ($url && (!$check_access || !$url->isRouted() || $url->access())) {
        $resolved = $url->toString();
      }
    }

    // Destination normalization is intentionally request-local only.
    // Alias resolution, route existence and access context can change outside
    // of redirect rule cache invalidation, so persisting normalized results
    // across requests risks stale or cross-context destinations. The matcher is
    // already optimized through compiled rule cache; keeping normalization
    // static within the current request gives most of the benefit without the
    // production risk of backend-cached route/alias state.
    return $this->normalizedDestinationStatic[$key] = $resolved;
  }

  protected function isRedirectLoop(ContentEntityInterface $entity, string $destination): bool {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return FALSE;
    }

    $current_path = $this->normalizePath((string) $request->getPathInfo());
    $destination_path = $this->normalizePath((string) (parse_url($destination, PHP_URL_PATH) ?? $destination));
    if ($current_path === $destination_path) {
      return TRUE;
    }

    $internal_current = $entity->getEntityTypeId() === 'taxonomy_term' ? '/taxonomy/term/' . $entity->id() : '/node/' . $entity->id();
    $internal_current = $this->normalizePath($internal_current);
    $current_langcode = $this->getCurrentContentLangcode();
    $aliases = [
      $this->normalizePath($this->aliasManager->getAliasByPath($internal_current, $current_langcode)),
      $this->normalizePath($this->aliasManager->getAliasByPath($internal_current)),
    ];

    if ($destination_path === $internal_current || in_array($destination_path, $aliases, TRUE)) {
      return TRUE;
    }

    $destination_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($destination);
    if ($destination_url && $destination_url->isRouted()) {
      $current_route_name = (string) $request->attributes->get('_route');
      if ($destination_url->getRouteName() === $current_route_name) {
        $current_route_parameters = $this->normalizeRouteParameters($request->attributes->all());
        $destination_route_parameters = $this->normalizeRouteParameters($destination_url->getRouteParameters());
        $relevant_current_parameters = array_intersect_key($current_route_parameters, $destination_route_parameters);
        if ($destination_route_parameters === $relevant_current_parameters) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  protected function normalizePath(string $path): string {
    $path = rtrim((string) $path, '/');
    return $path === '' ? '/' : $path;
  }

  protected function normalizeRouteParameters(array $parameters): array {
    $normalized = [];
    foreach ($parameters as $key => $value) {
      if (is_scalar($value) || $value === NULL) {
        $normalized[$key] = (string) $value;
      }
      elseif ($value instanceof ContentEntityInterface) {
        $normalized[$key] = $value->getEntityTypeId() . ':' . $value->id();
      }
    }
    return $normalized;
  }

  protected function buildConflictSignature(IscRedirectRule $rule): string {
    return implode('|', [
      $rule->getTargetEntityType(),
      $rule->getBundle(),
      $rule->getMatchMode(),
      $rule->getTargetEntityId(),
      $rule->getFieldName(),
      $rule->getConditionType(),
      $rule->getVocabulary(),
      $rule->getMatchValue(),
    ]);
  }
}
