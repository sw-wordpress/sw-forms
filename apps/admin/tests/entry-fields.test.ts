import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { describe, expect, it } from 'vitest';
import EntryFields from '../src/features/entries/EntryFields.vue';
import en from '../src/i18n/en';
import type { EntryNode } from '../src/types/api';

function render(nodes: EntryNode[]) {
  return mount(EntryFields, { props: { nodes }, global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] } });
}

describe('entry values and uploaded files', () => {
  it('renders escaped attachment labels as safe new-window links', () => {
    const wrapper = render([{ id: 'file', type: 'file', label: 'Documents', files: [{ name: '<img src=x onerror=alert(1)>.txt', url: 'https://example.test/file.txt' }] }]);
    const link = wrapper.get('a');
    expect(link.text()).toBe('<img src=x onerror=alert(1)>.txt');
    expect(link.attributes('href')).toBe('https://example.test/file.txt');
    expect(link.attributes('rel')).toContain('noopener');
    expect(link.attributes('rel')).toContain('noreferrer');
    expect(wrapper.find('img').exists()).toBe(false);
  });
  it('shows empty optional uploads and scalar values as unanswered', () => {
    const wrapper = render([{ id: 'f', type: 'file', label: 'Files', files: [] }, { id: 't', type: 'input', label: 'Text', value: '' }]);
    expect(wrapper.findAll('dd').map((node) => node.text())).toEqual(['—', '—']);
  });
  it('retains grouping and row labels around file links', () => {
    const file = { id: 'f', type: 'file', label: 'File', files: [{ name: 'one.pdf', url: 'https://example.test/one.pdf' }] };
    const wrapper = render([{ id: 'group', type: 'group', label: 'Details', children: [file] }, { id: 'rows', type: 'group', label: 'People', rows: [[file], [{ id: 'text', type: 'input', label: 'Name', value: 'Ada' }]] }]);
    expect(wrapper.findAll('a')).toHaveLength(2);
    expect(wrapper.text()).toContain('Entry 1');
    expect(wrapper.text()).toContain('Entry 2');
    expect(wrapper.text()).toContain('Ada');
  });
  it('handles an empty repeater without inventing a row', () => {
    const wrapper = render([{ id: 'rows', type: 'group', label: 'Rows', rows: [] }]);
    expect(wrapper.find('dd').text()).toBe('—');
    expect(wrapper.findAll('li')).toHaveLength(0);
  });
});
