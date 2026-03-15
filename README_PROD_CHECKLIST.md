ISC Redirect Manager production smoke checklist

1. Create a node bundle rule and verify redirect.
2. Create a taxonomy term rule and verify redirect.
3. Verify uk content resolves to uk destination.
4. Verify en content resolves to en destination.
5. Verify neutral language mode keeps destination language-neutral.
6. Verify fixed language mode forces the configured language.
7. Verify internal-path destination works.
8. Verify alias destination works.
9. Verify enable/disable confirm form works.
10. Verify duplicate active rule cannot be enabled.
11. Verify stats page opens from rule hits and keeps rule context.
12. Verify clear statistics confirm form works.
13. Delete a temporary bundle and confirm diagnostics block reports the rule.
14. Delete a temporary field and confirm diagnostics block reports the rule.
15. Confirm normal frontend requests remain fast after cache warmup.
