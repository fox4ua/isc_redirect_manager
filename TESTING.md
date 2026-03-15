# ISC Redirect Manager test suite

This module now includes a baseline regression suite for Drupal 10/11.

## Included tests

### Kernel tests
- `RedirectRuleMatcherKernelTest`
  - successful node redirect
  - successful taxonomy term redirect
  - invalid destination logging
  - redirect loop logging
- `RedirectFailureLoggerKernelTest`
  - aggregate stats increment
  - daily stats increment
  - stats clear
  - duplicate failure throttling
- `RedirectRuleConflictKernelTest`
  - enabled conflict detection
  - non-conflicting rule detection

### Functional tests
- `RedirectAdminPagesTest`
  - main admin pages open for authorized users
- `RedirectStatsRuleContextTest`
  - `rule_id` context limits the stats page to one rule
- `RedirectStatsFilterFormTest`
  - stats filter form applies bundle filter via GET redirect
- `RedirectStatsClearTest`
  - confirm form clears saved stats

## Run all tests

```bash
vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/isc_redirect_manager/tests
```

## Run only kernel tests

```bash
vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/isc_redirect_manager/tests/src/Kernel
```

## Run only functional tests

```bash
vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/isc_redirect_manager/tests/src/Functional
```

## Notes

- The tests are designed to be self-contained and create their own rules and stats.
- Functional tests use the `stark` theme to reduce admin theme side effects.
- In this environment only PHP syntax was checked. Full execution requires a real Drupal test environment.
