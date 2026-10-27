# SW Forms

Drag and drop form builder for WordPress: fields, groups and repeaters, column layouts,
conditional logic, entries, e-mail notifications and an extension API.

* Builder: palette, canvas (drag & drop, columns with resize handles, undo/redo, keyboard),
  inspector driven by the field types' settings schema, logic editor, real preview.
* Fields: input (text, e-mail, phone, URL, number, date, password, hidden), textarea, checkbox
  (single/list), radio (with "other"), select (single/multiple), group (repeatable), columns.
* Conditional visibility for every element and column, evaluated identically in the browser and
  on the server; hidden fields are neither validated nor stored.
* Entries with inbox/unread/starred/spam/trash, search, bulk actions, detail view with action log.
* Spam protection without captcha that works with page caches; GDPR: hashed or no IP, retention.
* Accessible output (labels, fieldsets, `aria-describedby`, error summary with focus), works
  without JavaScript.
* Embedding: `[sw_form id="3"]` or `<?php echo swf_render_form( 3 ); ?>`.

## Development

```bash
composer install
cd apps/admin && npm install && npm run build   # admin SPA + frontend runtime
```

Requirements: PHP 8.2, WordPress 6.4. See `AGENTS.md` for checks and conventions.

## Release dependencies

The release workflow installs production Composer dependencies and isolates them with
`coenjacobs/mozart:1.2.3` in `vendor-prefixed/`, using the namespace
`SWForms\Dependencies\` (classmap prefix `SWForms_`). Development dependencies and the
original `vendor/` directory are not included in the release ZIP.

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
There are currently no third-party production PHP libraries; the same isolation applies
when they are added later.

Reusable migration checklist for other plugins (German):
[Composer dependency isolation](docs/composer-vendor-prefixed.md).

## Roadmap

Phase 2: webhooks (queue with Action Scheduler, retries, HMAC signature, delivery log).
Phase 3: more merge tags, CSV export, captcha providers. Phase 4: frozen extension API, sample add-on.
Phase 5: block, import/export, multi step, file upload, templates.

## SonarQube Cloud

The test workflow uploads merged PHP Clover coverage and analyzes same-repository PRs
and pushes to the default branch. Configure the `SONAR_TOKEN` Actions secret and
`SONAR_ORGANIZATION` Actions variable (organization-level bindings may be reused).
Project mapping and source paths are in `sonar-project.properties`. Fork PRs run tests
without access to the scanner token. Coverage uses the existing PHPUnit scopes;
unmeasured application code is not excluded from SonarQube. Frontend tests and builds
run separately; JavaScript/TypeScript coverage is not collected by this PHP rollout.
Generated assets and third-party dependencies are not application analysis inputs.
