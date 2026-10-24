# AGENTS.md – rules for SW Forms

Concept: `docs/schema.md` (form document), `docs/hooks.md`, `docs/rest-api.md`, `docs/extending.md`.

## Architecture
- `src/Core` is framework independent: no WordPress functions. PSR-12, `strict_types`, camelCase, PHPStan level 8.
- `src/WordPress` is the WordPress adapter: WPCS (tabs, snake_case), PSR-4 class names.
- The form is one JSON document. Builder, renderer, validation and data shape all derive from it.
- Conditional logic exists twice (PHP `src/Core/Logic`, TypeScript `apps/admin/shared/logic.ts`);
  both must pass `tests/fixtures/logic/*.json`. Change both together and extend the fixtures.
- The PHP renderer is authoritative; the builder canvas is an approximation, the preview shows the real output.
- Element ids are immutable, keys are data contracts (warn before changing published keys).
- No WordPress nonce for anonymous submissions (cached pages): signed submission token, honeypot, rate limit.

## Workflow
1. `composer check` (phpcs core, phpcs WordPress, phpstan, phpunit).
2. `cd apps/admin && npm run lint && npm run typecheck && npm test && npm run build`.
3. Against the local WordPress: `php tests/wp-smoke.php`, `node apps/admin/scripts/e2e/frontend.mjs`,
   `node apps/admin/scripts/e2e/admin.mjs` (Playwright Chromium from `~/.cache/ms-playwright`).
4. User facing text: PHP → `bin/i18n.sh update`, translate `languages/sw-forms-de_DE.po`,
   `bin/i18n.sh compile && bin/i18n.sh check`; SPA → `apps/admin/src/i18n/{en,de}.ts` (parity test).
   In vue-i18n messages write merge tags as `{'{'}field:name{'}'}` and `|` as `{'|'}`.
