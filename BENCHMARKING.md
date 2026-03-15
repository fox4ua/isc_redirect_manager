Benchmarking notes

Use a staging copy with at least 500 rules.

1. Warm cache: request one matching node page 20 times and record average response time.
2. Cold cache: clear cache.redirect_rules and request one matching node page once.
3. Rebuild: save one rule and immediately request a matching page.
4. Admin overview: open node rules and taxonomy rules pages and measure response time.

Expected target:
- warm cache frontend should not load redirect config entities on every request;
- compiled rule rebuild should happen rarely and under lock;
- broken rules should be excluded from runtime cache and visible only in diagnostics.
