# Building forms

![Contact form in the SW Forms builder, with the Elements palette, canvas and Properties panel](/screenshots/sw-forms-builder.png)

*The demo contact form uses synthetic field definitions; no visitor submissions are shown.*

## Fields and data keys

Open a form from **SW Forms → Forms**. Drag an item from **Elements** to the canvas, or click it to add it. Select an element to edit **Properties**: **General**, **Validation**, **Logic** and **Appearance**. Available controls depend on the selected type.

| Task | Available elements |
| --- | --- |
| Text and contact details | Input (including email, phone, URL, number, date, password and hidden), textarea, name, address, birthdate |
| Choices and feedback | Checkbox, radio, select, tags, matrix, rating, scale, score, range and colour |
| Structure and calculations | Group, columns, HTML block, calculation and page break |
| Files and media | File upload, image, audio, video and signature |

For choices, set labels and stored values. **Edit as text** accepts one option per line, optionally `Label | value`. Changing stored values or published field keys can break conditions and receiving systems.

**Field name (data key)** is used in entries, email placeholders and webhook data. Use letters, digits and underscores, starting with a letter; keys must be unique at the same level. The key follows the label until the form is saved or you edit it. Keep published keys stable. The internal element ID is separate from this key.

Set helpful labels, help text and required fields. Review field-specific validation, including numeric/date bounds or file restrictions, rather than relying on browser hints alone. Hidden or password inputs are not a secret store: do not use a form to collect account passwords or embed credentials.

## Columns and repeatable groups

Add **Columns**, choose a layout and place fields within columns. Widths use a 12-unit grid. Resize with the handles (or arrow keys). Removing a column moves its fields to the column on the left.

For a list of participants, add **Group**, place fields inside it and enable **Repeatable** in its properties. Configure minimum/maximum rows and the add-button label. Each row retains its own answers and conditions. Entries and webhooks receive a list of records under the group key.

## Conditional logic

Select the dependent element and open **Logic**. Enable the condition, choose **Show**, **Hide**, **Require** or **Disable**, then add a field, comparison and value. Choose whether **all** or **any** rules must match. Example: show an additional details field when a radio answer has the stored value `other`.

Conditions also apply to columns. The server reevaluates conditions on submission; hidden fields are not validated or stored. Test both matching and non-matching answers, especially inside repeatable groups. Use stored choice values when comparing answers, not a translated display label.

## Multi-step forms

Under **Settings → Multi-step form**, enable **Enable multi-step** and choose **Numbered steps**, **Dots** or **Text only** for the progress display. Use the canvas step tabs to add, name and reorder steps and move fields between them. Removing a step moves its fields to a neighbouring step. Turning multi-step off merges the fields in order and removes step titles after confirmation.

Page breaks split the form only while multi-step is enabled. Test **Back**, **Next** and final submission on the real preview, including conditional required fields.

## Preview, save and versions

Use **Undo** and **Redo** for editing. **Preview** shows the actual PHP-rendered output with the site's theme at desktop, tablet or phone width; the canvas is only an approximation. Preview submissions validate but do not store or send data.

Click **Save** to validate and publish. Resolve the listed problems if saving is rejected. An autosaved draft is not the live form. Existing entries retain their original form version; see [published versions](./templates-import.md#published-versions) before restoring an earlier layout.

The [technical schema](https://github.com/sw-wordpress/sw-forms/blob/master/docs/schema.md) covers data shapes, calculation expressions and individual type settings. Add-on authors should use the [extension guide](https://github.com/sw-wordpress/sw-forms/blob/master/docs/extending.md).
