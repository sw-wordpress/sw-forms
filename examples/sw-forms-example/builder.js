/*
 * Loaded through the sw-forms-example handle, which depends on sw-forms-builder-api.
 * register() works both before and after the SPA initializes; no load-event race is needed.
 * This file deliberately uses plain JS and render functions: no build step or second Vue
 * installation. See docs/extending.md for the stable builder API contract.
 */
window.swFormsBuilder.register(function (api) {
  // Share the host runtime so components and reactive state belong to the same Vue app.
  var h = api.vue.h;
  // The slug must exactly match ReferenceField::type(); this registers UI, not a PHP type.
  api.registerFieldType('example-reference', {
    inspector: api.vue.defineComponent({
      // modelValue contains settings; element contains the complete selected field.
      props: ['modelValue', 'element'],
      // A settings patch enters the host's normal undo, autosave and publish flow.
      emits: ['update:modelValue'],
      setup: function (props, context) {
        // Read props inside the render function so later selection/settings changes update UI.
        return function () {
          // A wrapping label gives the native input an accessible name without a hardcoded ID.
          return h('label', ['Example caption', h('input', {
            value: props.modelValue.caption || '',
            // Never mutate modelValue directly; preserve other settings by emitting a patch.
            onInput: function (event) { context.emit('update:modelValue', { caption: event.target.value }); }
          })]);
        };
      }
    }),
    // Optional, inert approximation: the preview/public form still use ReferenceField::render().
    canvas: api.vue.defineComponent({
      // The host supplies current field metadata and settings; the canvas does not edit them.
      props: ['element', 'settings'],
      setup: function (props) {
        return function () { return h('p', { class: 'swf-preview' }, (props.settings.caption || 'Reference') + ': REF-123'); };
      }
    })
  });
});
