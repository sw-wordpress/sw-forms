# Feature overview

Detailed feature list moved from the former README. Field types and settings: [schema.md](schema.md).

* Builder: palette, canvas (drag & drop, columns with resize handles, undo/redo, keyboard),
  inspector driven by the field types' settings schema, logic editor, real preview.
* Fields: input (text, e-mail, phone, URL, number, date, password, hidden), textarea, checkbox
  (single/list), radio (with "other"), select (single/multiple), group (repeatable), columns.
  Further types (e.g. rating, signature, file upload, calculation, HTML block, page break) are
  registered in `src/WordPress/Fields/`.
* Conditional visibility for every element and column, evaluated identically in the browser and
  on the server; hidden fields are neither validated nor stored.
* Entries with inbox/unread/starred/spam/trash, search, bulk actions, detail view with action log.
* Spam protection without captcha that works with page caches; GDPR: hashed or no IP, retention.
* Accessible output (labels, fieldsets, `aria-describedby`, error summary with focus), works
  without JavaScript.
* Embedding: `[sw_form id="3"]` or `<?php echo swf_render_form( 3 ); ?>`.
* Designs: seven variants (classic, floating labels, minimal lines, dark focus, guided card,
  animated float, surprise motion), light/dark/system scheme, own colours; interactive gallery
  ([schema](schema.md#design-060), [extending](extending.md#design-variants-060)).

## Repeating groups

In the builder, add a **Group**, place child fields inside it, then enable **Repeatable**
in its properties. Set the minimum/maximum rows and add-button label there. Visitors can
add or remove records; each row retains its own values and conditional state. Entries and
webhooks receive a list of objects under the group's data key; see [schema.md](schema.md#data-shape).

The runtime acceptance test in `apps/admin/tests/frontend-runtime.test.ts` covers add/remove,
row limits, focus, unique input names and preserving records after deleting a preceding row.
