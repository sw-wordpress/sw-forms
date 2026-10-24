#!/usr/bin/env bash
# Translations of the PHP side (the admin SPA has its own catalogs in apps/admin/src/i18n).
#   bin/i18n.sh update   extract strings into languages/sw-forms.pot and merge them into the .po files
#   bin/i18n.sh compile  build the .mo files
#   bin/i18n.sh check    fail on untranslated or fuzzy strings
set -euo pipefail
cd "$(dirname "$0")/.."

case "${1:-}" in
  update)
    find src/WordPress includes sw-forms.php -name '*.php' | sort | xgettext --language=PHP --from-code=UTF-8 \
      --add-comments=translators: --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
      --keyword=esc_attr__ --keyword=esc_attr_e --keyword=_n:1,2 --keyword=_x:1,2c \
      --package-name="SW Forms" --msgid-bugs-address="" -o languages/sw-forms.pot -f -
    for po in languages/*.po; do msgmerge --quiet --update --backup=none "$po" languages/sw-forms.pot; done
    ;;
  compile)
    for po in languages/*.po; do msgfmt --check -o "${po%.po}.mo" "$po"; done
    ;;
  check)
    for po in languages/*.po; do
      stats=$(msgfmt --statistics -o /dev/null "$po" 2>&1)
      echo "$po: $stats"
      if grep -qE 'untranslated|fuzzy' <<<"$stats"; then exit 1; fi
    done
    ;;
  *)
    echo "usage: $0 update|compile|check" >&2
    exit 2
    ;;
esac
