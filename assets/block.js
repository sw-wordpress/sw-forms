/* Dynamic block: save only the form identifier; PHP renders the current publication. */
(function (wp) {
  var h = wp.element.createElement;
  var strings = window.swFormsBlockI18n;
  wp.blocks.registerBlockType('swforms/form', {
    apiVersion: 3,
    title: 'SW Forms', icon: 'feedback', category: 'widgets',
    attributes: { formId: { type: 'integer', default: 0 }, title: { type: 'boolean', default: false } },
    edit: function (props) {
      var state = wp.element.useState([]), error = wp.element.useState('');
      wp.element.useEffect(function () {
        var mounted = true;
        wp.apiFetch({ path: '/swforms/v1/forms/embed-options' }).then(function (result) { if (mounted) state[1](result.items); }).catch(function (failure) { if (mounted) error[1](failure.message); });
        return function () { mounted = false; };
      }, []);
      return h('div', wp.blockEditor.useBlockProps(),
        error[0] ? h(wp.components.Notice, { status: 'error', isDismissible: false }, error[0]) : null,
        h(wp.components.SelectControl, { label: strings.form, value: props.attributes.formId,
          options: [{ label: strings.select, value: 0 }].concat(state[0].map(function (form) { return { label: form.title + ' (#' + form.id + ')', value: form.id }; })),
          onChange: function (value) { props.setAttributes({ formId: Number(value) }); } }),
        h(wp.components.ToggleControl, { label: strings.title, checked: props.attributes.title, onChange: function (value) { props.setAttributes({ title: value }); } }),
        props.attributes.formId ? h('div', { inert: '' }, h(wp.serverSideRender, { block: 'swforms/form', attributes: props.attributes })) : null);
    },
    save: function () { return null; }
  });
})(window.wp);
