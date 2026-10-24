/** Config object injected by PHP as `window.swFormsConfig` (src/WordPress/Admin/AdminPage.php). */
export interface SwfConfig {
  /** REST root of the plugin namespace; always ends with `/`. */
  restRoot: string;
  /** REST root of the WordPress core API (pages for the confirmation picker). */
  wpRestRoot: string;
  /** `wp_rest` nonce, sent as `X-WP-Nonce`. */
  nonce: string;
  locale: string;
  version: string;
  adminUrl: string;
  capabilities: string[];
  siteUrl: string;
}

export type Capability = 'swf_manage_forms' | 'swf_view_entries' | 'swf_manage_entries' | 'swf_settings';
