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

## Dokumentation

Bei Änderungen an der öffentlichen Plugin-API muss die zugehörige englische Dokumentation im selben Arbeitsschritt aktualisiert werden: `docs/hooks.md` (Hooks, Parameter, Zweck, Einführungsversion), `docs/extending.md` (Interfaces, Registries, Builder-API, Beispiele und Deprecation-Policy) sowie bei Bedarf `docs/rest-api.md` und `docs/schema.md`. PHP-`@since`- und gegebenenfalls `@deprecated`-Angaben mitpflegen; bestehende Einführungsversionen nicht überschreiben. Die dort festgelegten Kompatibilitäts- und Deprecation-Regeln einhalten. Betroffene Beispiele und API-Tests anpassen, damit Code und dokumentierter Vertrag übereinstimmen.

Verfasse Dokumentation möglichst knapp und ohne Wiederholungen, um Tokens zu sparen. Beschränke sie auf nötige Entscheidungen, Sicherheitsdetails und Testhinweise; verlinke vorhandene Dokumentation statt Inhalte zu duplizieren.

## Tests und Coverage

- Jede funktionale Codeänderung (neue Funktionen, Fehlerbehebungen und Refactorings) muss durch passende automatisierte Tests abgedeckt sein; Tests im selben Arbeitsschritt ergänzen oder anpassen.
- Pflicht ist eine Zeilenabdeckung von **> 80 %** des produktiven Codes je Plugin; genau 80 % reichen nicht. PHP und JavaScript/TypeScript, soweit vorhanden, jeweils prüfen. Bestehende strengere Coverage-Grenzen bleiben verbindlich.
- Vor Abschluss relevante Tests und Coverage-Prüfungen ausführen und Ergebnisse nennen. Fehlende Coverage-Infrastruktur ergänzen; fehlende Messwerte gelten nicht als erfüllte Vorgabe. Keine produktiven Dateien ausschließen oder Grenzen senken, um die Vorgabe zu umgehen.

Vorhanden: PHP-/WordPress- und Vitest-Coverage mit bereichsweise strengeren Grenzen; siehe `docs/testing.md`, `tests/coverage/check.php` und `apps/admin/vite.config.ts`. Die bisherige PHP-Grenze von mindestens 80 % erfüllt die neue Vorgabe bei genau 80 % nicht.
