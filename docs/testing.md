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
| Complete frontend after media tests | 44.92% |
| Frontend form runtime | 88.74% |
| Filename parser, REST client, entry-file component | 100% |
| PHP upload field | 98.28% |
| PHP image-upload field / local preview manager | 100% |
| PHP incoming-file and upload-storage classes | 100% |
| PHP signature/audio/video fields | 100% |
| PHP shared recording field | 98.15% |
| Browser crop/signature/file bridge/manager modules | 100% |
| Browser recording control | 99.04% |

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
