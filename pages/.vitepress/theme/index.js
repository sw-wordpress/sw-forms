import { h } from 'vue';
import DefaultTheme from 'vitepress/theme';
import './style.css';

export default {
  extends: DefaultTheme,
  Layout() {
    return h(DefaultTheme.Layout, null, {
      'layout-bottom': () => h('footer', { class: 'swf-docs-footer' },
        'Open source by Stefan Warnat – free to use, free to change, better together.')
    });
  }
};
