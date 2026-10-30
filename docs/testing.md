# Tests and coverage

The portable core, WordPress adapter and frontend need different runners. A passing core
suite does not establish coverage of uploads, persistence, permissions or the browser runtime.
CI runs all three and imports both coverage **and test execution** into SonarQube.

## Portable PHP tests

```sh
composer install
composer check
mkdir -p coverage
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-clover coverage/unit.xml --log-junit coverage/unit-junit.xml
```

PHPUnit measures the complete PHP application: `src/`, `includes/`, `sw-forms.php` and
`uninstall.php`. Unexecuted WordPress files remain in the denominator; they are not excluded
to improve the percentage. The regular suite requires no WordPress installation or database.
Coverage needs Xdebug in coverage mode or PCOV. No coverage driver means a failing coverage job.

## Real WordPress and HTTP tests

Use an installed **disposable development/test WordPress**, database and activated SW Forms
checkout. CI provisions WordPress and MariaDB automatically. Never point the suite at a
production installation: it creates/deletes its own forms, entries, pages and temporary users.
PHP needs the normal WordPress extensions plus cURL and permission to start a loopback server.
Use a symlink from `wp-content/plugins/sw-forms` to the checkout so coverage has one canonical
source path, rather than a second bind mount of the same source.

```sh
export WP_ROOT=/absolute/path/to/test-wordpress
composer test:wp
# Or include all CLI and HTTP requests in coverage:
XDEBUG_MODE=coverage composer coverage:wp
php tests/coverage/merge.php coverage/unit.xml coverage/wordpress/*.xml
php tests/coverage/junit.php coverage/unit-junit.xml coverage/wordpress-junit.xml
php tests/coverage/check.php
```

`test:wp` automates all eight existing integration scripts and the WordPress PHPUnit suite.
Standalone scripts must exit successfully **and** print their completion marker; a WordPress
fatal-error page with a misleading zero exit code cannot pass. Coverage runs remove earlier
request reports before collecting new ones. The collector includes WordPress requests that
terminate through `exit()`, so native postback and REST responses count as executed code.

The HTTP suite uses actual multipart requests and PHP's real `is_uploaded_file()` checks.
It covers allowed/forbidden files, MIME mismatch, empty/oversized files, count limits, hidden
fields, sparse repeater rows, spam/token checks, storage requirements and native postback.
Its authenticated fixture injects upload/directory/database/filter failures and verifies that
a partial upload batch leaves neither an entry nor orphaned files. Missing file metadata and
missing physical files are regression cases. The fixture listens on loopback and requires a
random per-run key; its fault hooks are not installed in the plugin.

The REST suite additionally tests publication versus drafts, invalid schema preservation,
duplication, status changes, entry search/presentation, bulk operations, missing-resource
responses and the distinction between viewing entries, editing forms and anonymous access.
PHPUnit's WordPress bootstrap and its loopback HTTP fixture explicitly select `en_US`, since
their assertions use English source messages. This does not change the site's stored locale.
Webhook tests cover encrypted secrets, draft rotation/discard, REST redaction, independent
duplicate secrets, field-id mapping, payload filters and signatures of exact JSON/form bodies.
The browser configuration round-trip runs with `node apps/admin/scripts/e2e/webhooks.mjs`.
`WebhookDeliveryTest` covers real queue execution, immutable retry snapshots, bounded backoff,
terminal failure notification/badges, temporary data erasure, lease recovery, queue persistence
failure, preview/conditions and resend authorization. `WebhookHttpTest` uses a real loopback
HTTP receiver to verify exact signed bytes and request-scoped exception cleanup. The browser
flow `node apps/admin/scripts/e2e/webhook-delivery.mjs` exercises the test button, signed echo
receiver, final failure badge, entry status and resend/polling. Its isolated loopback REST
router requires a random per-run header; no test filters are installed on the normal site.
`node apps/admin/scripts/e2e/editor-layout.mjs` verifies the editor's viewport frame at
desktop widths, independent palette/canvas scrolling, every editor tab, narrow-screen
panel access and releasing the document scroll lock when returning to the forms list.
It also captures light/dark/mobile views under `/tmp/swf-editor-layout-*.png`.

## Frontend tests

```sh
cd apps/admin
npm ci
npm run lint
npm run typecheck
npm run test:coverage
npm run build
```

Vitest collects V8 coverage for **all** application TypeScript and Vue files in `src/`,
`shared/` and `frontend/`, including unimported files. Type declarations are not executable.
The report converter normalizes LCOV and test file paths relative to the repository root,
which is essential because the test job runs in `apps/admin` and the scanner job runs at root.
Vitest JSON results become Sonar's generic test-execution XML; failures/skips retain their
outcome and text is XML escaped. PHPUnit's separate suites are combined into portable JUnit.

Frontend tests cover the real form runtime's file reading, conditional hiding/disabling,
drag/drop, row identity and scoped validation, multipart submission, error focus, retry,
preview, token renewal and concurrent-submission protection. Component tests cover escaped
file links and nested entry presentation. REST-client tests cover cookies/nonces, nonce
renewal, JSON, WordPress errors, malformed responses, cancellation and network failures.

## CI guards and reports

CI fails on missing/unexecuted coverage or missing test-execution artifacts. It enforces:

- Full PHP line coverage of at least 80%.
- Upload field/transport/storage classes at least 95%, submission service at least 85%.
- Frontend form runtime at least 85%, filename parsing and entry-file presentation 100%,
  image-preview lifecycle at least 95%, and REST client at least 95% line coverage.

SonarQube's configured quality gate remains independent; these checks do not waive it.
The artifacts imported by its separate job are `coverage/clover.xml`, `coverage/junit.xml`,
`apps/admin/coverage/lcov.info` and `apps/admin/coverage/test-execution.xml`. Coverage-merger
and test-report conversion have regression tests for overlapping hits, portable paths,
foreign paths, empty reports, escaped text and retained failures/skips.

Measured locally with PHP 8.2/PCOV and Vitest V8 during this change:

| Scope | Line coverage |
|-------|---------------|
| Portable core alone, before adapter collection | 92.31% (480/520) |
| Complete PHP application after media integration | 87.95% (4175/4747) |
| Complete frontend before new tests | 30.81% |
| Complete frontend after media tests | 44.93% |
| Frontend form runtime | 88.74% |
| Filename parser, REST client, entry-file component | 100% |
| PHP upload field | 98.28% |
| PHP image-upload field / local preview manager | 100% |
| PHP incoming-file and upload-storage classes | 100% |
| PHP signature/audio/video fields | 100% |
| PHP shared recording field | 98.15% |
| Browser crop/signature/file bridge/manager modules | 100% |
| Browser recording control | 99.05% |

Core-only and complete-application percentages are different scopes and must not be compared
as a before/after regression. Remaining frontend gaps are chiefly other builder/settings
components, layouts and application startup. A green focused upload gate is not a claim that
all UI paths are covered. Test-execution counts may only be shown on branch analyses; PR coverage is imported from
Clover/LCOV independently. SonarQube may report different percentages because its executable
line model and branch accounting differ from the local runners.

Browser smoke checks remain useful in addition to coverage tests. Against local WordPress,
run `node apps/admin/scripts/e2e/frontend.mjs`, `admin.mjs`, `uploads.mjs`, `images.mjs` and `media.mjs` with the PHP helper
on PATH and the repository-pinned Playwright Chromium installed. These are not counted as
Vitest coverage; the CI HTTP suite and DOM-runtime tests supply that report independently.

`media.mjs` launches real Chromium recording with synthetic devices (no microphone/camera
hardware needed). It checks crop pixel dimensions, drawn signature bytes, live browser-produced
audio/video streams accepted by PHP, permission-free initial rendering, automatic limits,
track shutdown on hiding/removal, busy-submit blocking, no-JS fallback and palette persistence.
Synthetic media files in `tests/fixtures/media` are committed; FFmpeg was used only to generate
those samples and is not needed in CI or at runtime. Stream-kind rejection and permanent
file cleanup are verified over real multipart HTTP. Run upload browser suites sequentially:
some assertions deliberately count all generated files in the disposable WordPress directory.
The new PHP media fields and frontend media modules have 95% coverage guards (concrete
audio/video PHP types require 100%). These do not waive SonarQube's separate quality gate.


## Milestone 3

`tests/WordPress/MilestoneThreeTest.php` covers filtered CSV and permissions, original-version
entry edits, revision conflicts, notes, multi-page privacy export/erasure, merge tags, forged
disabled values and both CAPTCHA verification APIs. External verification requests are mocked;
real site/secret credentials and provider availability require deployment testing.

Core CSV tests check formula injection and nested shapes. `logic/effects.json` is shared by
PHP and TypeScript for required/disabled cascades and independent repeater rows. Vitest covers
CAPTCHA token expiry/reset/removal and entry editor value shapes. Run
`node apps/admin/scripts/e2e/m3.mjs` against local WordPress for dynamic effects, searchable
select keyboard interaction, entry edits/notes, real CSV download, caret insertion and erasure.

WP Armour: `tests/Unit/WpArmourTest.php` prüft die optionale Anbindung ohne WordPress.
Bei aktivem WP Armour prüft `WpArmourIntegrationTest` im WordPress-Testlauf zusätzlich
REST, POST-Metadaten, Vorschau, vertrauenswürdige Aufrufe und (mit Extended) den
Wortfilter. Den Test sowohl mit Basis allein als auch mit Extended ausführen.
Der Frontend-Browsertest prüft bei aktivem WP Armour auch die Ablehnung ohne JavaScript.
`SWF_WPA_VANILLA=1 node apps/admin/scripts/e2e/frontend.mjs` ersetzt nur im Testbrowser
die WP-Armour-Skripte durch deren Vanilla-Varianten; die Website-Einstellungen bleiben gleich.

## Extension API (0.4.0)

`ExtensionApiTest` runs the [example add-on](../examples/sw-forms-example/) through real
WordPress: schema normalization, rendering, required/custom validation, repeater paths,
visibility, storage, logged actions, merge-tag escaping and legacy-filter precedence.
Vitest covers queued/late JavaScript registration and structured settings controls;
`HookDocumentationTest` checks hook documentation completeness.

For the production-build browser test, activate the example add-on in a **disposable**
WordPress and run `WP_ROOT=/path/to/test-wordpress node apps/admin/scripts/e2e/extension-api.mjs`.
It checks custom inspector/canvas loading via script handle, undo/redo, field selection,
key-value/repeater persistence, invalid/valid submission, confirmation and the action log.
The test creates/deletes its own form/page and makes no external service calls. Keep this
production-build check: WordPress versions the entry script with a query string, so shared
state must live in hashed chunks rather than the entry module.

`SWF_E2E_OFFLINE=1 node apps/admin/scripts/e2e/admin.mjs` keeps the admin test local by
answering unrelated external theme fonts/avatars with empty responses. App assets and REST
requests still use the real WordPress; this avoids flaky third-party network failures.

## Milestone 5

`MilestoneFiveTest` covers secret-free portability, inactive imports, permissions, immutable
history/draft restoration, step validation without persistence or token consumption,
forged calculation values, safe HTML, dynamic block output and owned mail attachments.
`VisualComposerTest` isolates the optional `vc_map` contract when WPBakery is absent.
Arithmetic fixtures in `tests/fixtures/calculations.json` run in PHP and TypeScript.
The frontend runtime test covers remote step progression, retained values and errors that
reveal an earlier page.

Run `WP_ROOT=/path/to/test-wordpress node apps/admin/scripts/e2e/m5.mjs` after building.
It exercises real step requests/calculations, history, templates, JSON file import/export
and Gutenberg ServerSideRender. It creates/deletes its own forms/pages and needs an admin
and a working block editor in a disposable WordPress. Existing upload tests still cover
MIME/provenance/size/rollback; attachment tests use the server-owned manifest.
