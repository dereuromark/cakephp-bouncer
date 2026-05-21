import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'cakephp-bouncer',
  description: 'Approval workflow for CakePHP — propose, review, approve or reject changes with diff and 3-way merge.',
  base: '/cakephp-bouncer/',
  cleanUrls: true,
  head: [
    ['link', { rel: 'icon', href: '/cakephp-bouncer/favicon.svg', type: 'image/svg+xml' }],
  ],
  themeConfig: {
    logo: '/logo.svg',
    nav: [
      { text: 'Guide', link: '/guide/', activeMatch: '/guide/' },
      { text: 'Features', link: '/features/', activeMatch: '/features/' },
      {
        text: 'Links',
        items: [
          { text: 'Live Demo', link: 'https://sandbox.dereuromark.de/sandbox/bouncer-examples' },
          { text: 'GitHub', link: 'https://github.com/dereuromark/cakephp-bouncer' },
          { text: 'Packagist', link: 'https://packagist.org/packages/dereuromark/cakephp-bouncer' },
          { text: 'Issues', link: 'https://github.com/dereuromark/cakephp-bouncer/issues' },
        ],
      },
    ],
    sidebar: {
      '/guide/': [
        {
          text: 'Guide',
          items: [
            { text: 'Getting Started', link: '/guide/' },
            { text: 'Configuration', link: '/guide/configuration' },
            { text: 'Usage', link: '/guide/usage' },
            { text: 'View Helper', link: '/guide/view-helper' },
          ],
        },
      ],
      '/features/': [
        {
          text: 'Features',
          items: [
            { text: 'Overview', link: '/features/' },
            { text: 'Admin UI', link: '/features/admin-ui' },
            { text: 'Approval Workflow', link: '/features/approval-workflow' },
            { text: '3-Way Merge', link: '/features/three-way-merge' },
            { text: 'Advanced Patterns', link: '/features/advanced-patterns' },
            { text: 'AuditStash Integration', link: '/features/audit-stash-integration' },
            { text: 'Troubleshooting', link: '/features/troubleshooting' },
          ],
        },
      ],
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/dereuromark/cakephp-bouncer' },
    ],
    search: {
      provider: 'local',
    },
    editLink: {
      pattern: 'https://github.com/dereuromark/cakephp-bouncer/edit/master/docs/:path',
      text: 'Edit this page on GitHub',
    },
    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright Mark Scherer',
    },
  },
})
