import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { describe, expect, it } from 'vitest';
import ElementPreview from '../src/features/builder/ElementPreview.vue';
import { createElement, duplicate } from '../src/features/builder/document';
import type { FieldTypeDefinition, FormDocument } from '../src/types/api';
import en from '../src/i18n/en';

const defaults = { rows: [{ label: 'Service <script>', value: 'service' }, { label: 'Quality', value: 'quality' }], options: [{ label: 'Disagree', value: '1' }, { label: 'Agree', value: '5' }] };
const definition: FieldTypeDefinition = { type: 'matrix', label: 'Matrix / Likert', icon: 'r_grid_on', category: 'choice', isData: true, container: '', supports: ['key', 'label', 'required'], settings: [], defaults };

describe('matrix builder', () => {
  it('creates independent copies while retaining stable answer keys', () => {
    const element = createElement(definition, new Set());
    const copy = duplicate({ fields: [element] } as FormDocument, element.id)!;
    expect(copy.id).not.toBe(element.id);
    expect(copy.settings).toEqual(defaults);
    (copy.settings!.rows as { label: string; value: string }[])[0]!.label = 'New label';
    expect(element.settings).toEqual(defaults);
    expect((copy.settings!.rows as { value: string }[])[0]!.value).toBe('service');
  });
  it('previews every row and column with escaped labels', () => {
    const wrapper = mount(ElementPreview, { props: { element: createElement(definition, new Set()), known: true }, global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] } });
    expect(wrapper.findAll('tbody tr')).toHaveLength(2);
    expect(wrapper.findAll('thead th').map((node) => node.text())).toEqual(['Disagree', 'Agree']);
    expect(wrapper.findAll('tbody .swf-preview__radio')).toHaveLength(4);
    expect(wrapper.find('script').exists()).toBe(false);
    expect(wrapper.text()).toContain('Service <script>');
  });
});
