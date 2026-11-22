# Documentation maintenance

## Page plan (recorded before implementation)

The existing README and technical documentation are predominantly English; user documentation therefore uses English and the English UI labels. The audience is WordPress administrators building contact, registration and other data-collection forms, and staff processing their entries.

| Page | Why it is needed |
| --- | --- |
| `index.md` | Factual feature overview and routes into task-oriented guides. |
| `getting-started.md` | Requirements, release ZIP installation and the first real submission. |
| `building-forms.md` | Fields, keys, layout, conditions, steps, preview and publishing. |
| `configuration.md` | Global and per-form defaults, embedding, design and optional integrations. |
| `notifications-webhooks.md` | Configure outgoing data, test delivery and understand background work. |
| `entries-privacy.md` | Process/export entries, handle uploads and choose storage/deletion policies. |
| `templates-import.md` | Templates, JSON transfer, published versions and third-party migration limits. |
| `troubleshooting.md` | Diagnose missing forms, delivery failures, access and submission errors. |
| `Changelog.md` | User-facing changes; only evidenced release notes and unreleased work. |

Evidence: `README.md`, `docs/features.md`, `docs/schema.md`, `apps/admin/src/i18n/en.ts`, builder/forms/entries/settings views, `src/WordPress/Forms/FormSettings.php`, `Support/Settings.php`, `Support/Capabilities.php`, `Submission/SpamGuard.php`, upload/privacy/webhook implementations and their tests. Existing technical documents remain in `docs/` and are linked rather than reproduced.

This maintainer page is excluded from the published site by `srcExclude`.

## Build and update

Keep user instructions aligned with English UI labels, implementation and tests. Update `pages/Changelog.md` in the same change, using only nonempty `Feature`, `Bugs` and `Other topics` categories beneath `Unreleased` or an evidenced release. No release notes were available for the published 1.0.1/1.0.0 releases when the documentation was introduced.

Use Node.js 22 and npm. From the repository root, build a disposable copy outside the checkout (no package file or lockfile belongs in `pages/`):

```sh
DOCS_BUILD="$(mktemp -d)"
cp -R pages "$DOCS_BUILD/pages"
npm install --prefix "$DOCS_BUILD" --no-save --package-lock=false --ignore-scripts --no-audit --no-fund vitepress@1.6.4
"$DOCS_BUILD/node_modules/.bin/vitepress" build "$DOCS_BUILD/pages"
node pages/.vitepress/verify.mjs "$DOCS_BUILD/pages/.vitepress/dist"
# Optional local preview: open the printed URL with /sw-forms/ appended.
"$DOCS_BUILD/node_modules/.bin/vitepress" preview "$DOCS_BUILD/pages"
```

Stop preview with Ctrl-C and remove the disposable directory afterwards. VitePress 1.6.4 is pinned and production-build tested; transitive dependencies are not lockfile-pinned. The workflow uses the same isolated layout under `runner.temp`; existing application dependency manifests are unchanged. Keep dead-link checking enabled.

`pages/.vitepress/config.mjs` sets `/sw-forms/` for the project Pages URL, local search, navigation and edit links against the discovered default branch `master`. No custom domain was found in the repository. The custom theme uses a single `layout-bottom` footer (not the default sidebar-hidden footer); keep its exact text unchanged and verify it on home, guide and Changelog pages.

## Deployment and one-time setup

`.github/workflows/documentation.yml` builds relevant pull requests without deploying. Relevant pushes to `master`, or a manual run on `master`, build and deploy the Pages artifact. Only the deploy job has Pages/OIDC write permissions; deployment concurrency is serialized. No PAT or `gh-pages` branch is used.

The Pages API returned 404 during initial discovery, so configuration/publication could not be confirmed. A repository administrator must confirm **Settings → Pages → Source → GitHub Actions**, allow the `github-pages` environment to deploy from `master`, and enable Actions as required. Do not change repository settings as part of a documentation edit. A successful build or merged PR is not proof the site is published: check the deployment result and the served URL separately.

Before merging, check the isolated build, internal links, navigation, case-sensitive `Changelog` URL, local search assets and `/sw-forms/` asset paths. Check the exact visible footer on every page, including pages with a sidebar.

## Screenshots and package boundaries

Only add actual sanitized application captures to `pages/public/screenshots/`, with descriptive alt text. Use a disposable instance or synthetic records; redact secrets, personal data and private recipients. Do not commit mock screenshots. The included screenshots were captured from the real demonstration installation at `https://wp-demo.stefanwarnat.de/`: the forms list, contact form builder and public contact form. They show synthetic demo definitions and empty inputs, not visitor records. Captures used read-only navigation: no form definitions were saved and no submissions were sent. The WordPress admin toolbar and user identity were cropped out. The demo frontend privacy notice is explicitly an example and must be replaced before production use.

`bin/build-zip.sh` packages an explicit allowlist of runtime directories/files. `pages/`, `.github/`, `node_modules/` and temporary documentation builds are not in it. Keep this boundary when changing release packaging. A full plugin ZIP build additionally requires built application assets, prefixed Composer dependencies, Action Scheduler and release smoke-test infrastructure; the documentation build does not supply those.
