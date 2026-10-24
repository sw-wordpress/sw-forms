import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { Dialog, Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import 'quasar/dist/quasar.prod.css';
import '@quasar/extras/material-icons-round/material-icons-round.css';
import './styles/fonts.css';
import './styles/app.css';
import './styles/builder.css';

import App from './App.vue';
import { getConfig, resolveUiLocale } from './config';
import { createAppI18n } from './i18n';
import quasarLang from './i18n/quasarLang';
import { createAppRouter } from './router';

const MOUNT_ID = 'sw-forms-app';

function showFatal(el: HTMLElement, message: string): void {
  el.classList.add('swf-root');
  const p = document.createElement('p');
  p.setAttribute('role', 'alert');
  p.textContent = message;
  el.replaceChildren(p);
}

function bootstrap(): void {
  const el = document.getElementById(MOUNT_ID);
  if (!el) {
    console.error(`SW Forms: mount node #${MOUNT_ID} not found`);
    return;
  }
  let config;
  try {
    config = getConfig();
  } catch (error) {
    console.error(error);
    showFatal(el, 'SW Forms: configuration missing (window.swFormsConfig).');
    return;
  }

  el.classList.add('swf-root', 'swf-fullbleed');
  document.body.classList.add('swf-admin-page');

  const app = createApp(App);
  app.use(createPinia());
  app.use(createAppRouter());
  app.use(createAppI18n(config.locale));
  app.use(Quasar, {
    plugins: { Notify, Dialog },
    iconSet,
    lang: quasarLang(resolveUiLocale(config.locale)),
    // Portals (dialogs, menus, tooltips, notifications) live on <body>: give them the root class.
    config: { globalNodes: { class: 'swf-root' } },
  });
  app.mount(el);
}

bootstrap();
