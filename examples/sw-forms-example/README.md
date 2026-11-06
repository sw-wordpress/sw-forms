# SW Forms API example

A small, working add-on for SW Forms 0.4+. Comments in the three source files explain
why each integration point is needed. The stable contracts and deprecation policy are
in [Extending SW Forms](../../docs/extending.md); hook signatures are in
[Hooks](../../docs/hooks.md).

## Read the files in this order

1. **`sw-forms-example.php`**: WordPress plugin entry point. It waits for the host,
   attaches lazy registry callbacks, registers the editor script, and appends an action.
   The validator rejects invalid references; the merge tag resolves a stored value;
   the action writes a local entry log rather than contacting an external service.
2. **`ReferenceField.php`**: PHP field contract and settings schema. The base class
   handles ordinary text collection and normalization. `RenderContext` supplies names,
   IDs and values for both standalone fields and repeater rows. The settings demonstrate
   builder controls; `related`, `metadata` and `steps` do not implement business logic.
3. **`builder.js`**: Optional inspector/canvas components using the host's Vue runtime.
   The dependency handle creates the registration queue first. Inspector updates emit
   settings patches so undo/autosave work; the canvas only approximates the real field.

Three identifiers have different purposes: `example-reference` selects the registered
field **type**, an element **ID** identifies a field in the document/request, and its
published **key** names the stored data. The example merge tag and action intentionally
look for key `reference`; the format validator applies to every `example-reference`
field regardless of its key.

## Try it in a test installation

Install this directory as `wp-content/plugins/sw-forms-example` alongside SW Forms and
activate it. Add a **Reference** field, set its key to `reference`, and use
`{example_reference:reference}` as the confirmation message.

- Submit `abc`: validation fails and no entry/action is created.
- Submit `REF-123`: the confirmation reads `REF-123` and the entry log contains a
  successful `example` action.
- Change **Example caption**: the canvas changes immediately. Undo/redo and saving/
  reopening should preserve edits, including field selection and structured settings.

PHP and browser regression tests execute this same example; see
[Extension API tests](../../docs/testing.md#extension-api-040). The example is not
activated by SW Forms itself. Delete its test forms before removing the add-on,
because their documents still reference its field type.
