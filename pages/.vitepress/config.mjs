const guide = [
  { text: 'Getting started', link: '/getting-started' },
  { text: 'Building forms', link: '/building-forms' },
  { text: 'Configuration', link: '/configuration' },
  { text: 'Notifications and webhooks', link: '/notifications-webhooks' },
  { text: 'Entries and privacy', link: '/entries-privacy' },
  { text: 'Templates, import and history', link: '/templates-import' },
  { text: 'Troubleshooting', link: '/troubleshooting' },
  { text: 'Changelog', link: '/Changelog' }
];

export default {
  lang: 'en-US',
  title: 'SW Forms',
  description: 'User documentation for the SW Forms WordPress plugin.',
  base: '/sw-forms/',
  srcExclude: ['README.md'],
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started' },
      { text: 'Changelog', link: '/Changelog' },
      { text: 'Repository', link: 'https://github.com/sw-wordpress/sw-forms' }
    ],
    sidebar: [{ text: 'User guide', items: guide }],
    search: { provider: 'local' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/sw-wordpress/sw-forms' }],
    editLink: {
      pattern: 'https://github.com/sw-wordpress/sw-forms/edit/master/pages/:path',
      text: 'Edit this page on GitHub'
    },
    outline: { level: [2, 3] }
  }
};
