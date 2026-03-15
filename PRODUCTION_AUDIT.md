Production audit summary

Implemented in this build:
- compiled matcher runtime cache in dedicated cache bin cache.redirect_rules;
- lock-protected cache rebuild to avoid concurrent rebuild storms;
- compiled payload versioning and structure validation;
- runtime exclusion of broken rules from active matcher cache;
- admin diagnostics block for missing bundle / field / term / target entity;
- request-level and backend caching for destination normalization;
- stronger loop detection that compares current alias and canonical internal path;
- repeat_count and last_seen for throttled failure log entries;
- benchmark and production smoke-test documentation;
- additional kernel tests for diagnostics and matcher cache invalidation.

Deliberately not implemented in this build:
- event-level redirect log table for every single hit.
