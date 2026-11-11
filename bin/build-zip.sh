#!/usr/bin/env bash
# Requires built assets, Mozart dependencies and the separate Action Scheduler library.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
DISTRIBUTION="${2:-github}"
case "$DISTRIBUTION" in github|wordpress-org) ;; *) echo "Unknown distribution" >&2; exit 1 ;; esac
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
cd "$ROOT"
PLUGIN_SLUG=sw-forms
VERSION="$(sed -n 's/^ \* Version: *//p' sw-forms.php)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
STAGING="$STAGE/$PLUGIN_SLUG"
mkdir -p "$STAGING"
test -f vendor-prefixed/autoload.php
test -f libraries/action-scheduler/action-scheduler.php
test -f assets/admin/app.js
test -d assets/frontend
# An allowlist keeps local credentials, tooling and experiments out of packages.
for directory in assets docs examples includes languages licenses src vendor-prefixed libraries; do
  rsync -a --exclude='*.log' --exclude='*.zip' "$directory/" "$STAGING/$directory/"
done
for file in sw-forms.php uninstall.php README.md LICENSE; do
  cp "$file" "$STAGING/$file"
done

if [[ "$DISTRIBUTION" == wordpress-org ]]; then
  # Only edit the staging copy. WordPress.org builds do not load an external provider.
  sed -i "/Update URI:/d; /includes\/update\/class-sw-forms-update-provider.php/d; /SWF_Update_Provider::class, 'deactivate'/d" "$STAGING/sw-forms.php"
  rm -rf "$STAGING/includes/update"
  rm -f "$STAGING/assets/js/sw-forms-updates.js"
fi

php tests/bootstrap.php "$STAGING"
php tests/uninstall-bootstrap.php "$STAGING"
(cd "$STAGE" && zip -qr package.zip "$PLUGIN_SLUG")
mv "$STAGE/package.zip" "$OUT/$PLUGIN_SLUG-$VERSION.zip"
echo "$OUT/$PLUGIN_SLUG-$VERSION.zip"
