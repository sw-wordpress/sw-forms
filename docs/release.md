# Release build and CI

Moved from the former README. German migration checklist for other plugins:
[composer-vendor-prefixed.md](composer-vendor-prefixed.md); tests and coverage: [testing.md](testing.md).

## Release dependencies

The release workflow (`.github/workflows/main.yml`) installs production Composer dependencies and
isolates them with `coenjacobs/mozart:1.2.3` in `vendor-prefixed/`, using the namespace
`SWForms\Dependencies\` (classmap prefix `SWForms_`). Development dependencies and the
original `vendor/` directory are not included in the release ZIP.
Exception: Action Scheduler is excluded from Mozart and copied unchanged to
`libraries/action-scheduler/` (see the checklist above).

The plugin prefers `vendor-prefixed/autoload.php` and registers its own `SWForms\`
classes from `src/` on that loader. Source checkouts fall back to `vendor/autoload.php`,
so normal development still only requires `composer install`.

To reproduce the dependency isolation locally (use a disposable checkout because Mozart
rewrites dependency references):

```bash
composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/project" \
  coenjacobs/mozart:1.2.3 /mozart/bin/mozart compose
php tests/bootstrap.php
```

The workflow also builds the admin and frontend assets and smoke-tests the staged plugin
without the original Composer directory before packaging it under the `sw-forms/` slug.

## SonarQube Cloud

The test workflow (`.github/workflows/tests.yml`) runs portable PHP tests, real WordPress/MariaDB
and multipart HTTP integration tests, and frontend tests. It imports merged full-application PHP
Clover, frontend LCOV, PHPUnit JUnit and frontend test-execution reports into SonarQube.
Configure the `SONAR_TOKEN` Actions secret and `SONAR_ORGANIZATION` Actions variable
(organization-level bindings may be reused). Project mapping and source paths are in
`sonar-project.properties`; fork PRs run tests without the scanner token.

Unmeasured application code remains in the coverage scope; generated assets and dependencies
are not analysis inputs. Thresholds and report formats: [testing.md](testing.md).
