import type { ContainerKind, ElementNode, Logic } from '@shared/types';

/** Inspector definition of one setting (docs/extending.md "Settings schema"). */
export interface SettingDefinition {
  name: string;
  type: 'text' | 'textarea' | 'html' | 'code' | 'number' | 'toggle' | 'select' | 'options' | 'list' | string;
  tab: 'general' | 'validation' | 'appearance' | string;
  label: string;
  default?: unknown;
  help?: string;
  choices?: { value: string; label: string }[];
  showIf?: { setting: string; equals: unknown[] } | null;
  min?: number;
  max?: number;
  integer?: boolean;
  images?: boolean;
  fields?: SettingDefinition[];
  maxItems?: number;
  fieldTypes?: string[];
}

export interface FieldTypeDefinition {
  type: string;
  label: string;
  icon: string;
  category: string;
  isData: boolean;
  container: ContainerKind;
  supports: string[];
  settings: SettingDefinition[];
  defaults: Record<string, unknown>;
  initialChildren?: Omit<ElementNode, 'id'>[];
}

export interface Notification {
  id: string;
  name: string;
  enabled: boolean;
  to: string;
  subject: string;
  message: string;
  replyTo: string;
  fromName: string;
  attachFiles?: boolean;
  logic: Logic | null;
}

export interface FormSettings {
  submitLabel: string;
  cssClass: string;
  confirmation: { type: 'message' | 'redirect' | 'page'; message: string; url: string; pageId: number };
  storeEntries: boolean;
  retentionDays: number;
  spam: { honeypot: boolean; minSeconds: number; rateLimit: number; provider?: string };
  notifications: Notification[];
  webhooks?: Webhook[];
  steps?: StepsSettings;
  design?: DesignSettings;
}

export type DesignVariant = 'classic' | 'floating' | 'minimal' | 'dark-focus' | 'guided-card' | 'animated-float' | 'surprise-motion';
export type DesignToken = 'accent' | 'text' | 'background' | 'surface' | 'border';

/** Presentation only (docs/schema.md); the server normalises it. */
export interface DesignSettings {
  variant: DesignVariant;
  scheme: 'light' | 'dark' | 'auto';
  tokens: Partial<Record<DesignToken, string>> & { radius?: number };
}

/** Multi-step mode; page breaks only split the form while enabled. */
export interface StepsSettings {
  enabled: boolean;
  firstLabel: string;
  indicator: 'numbers' | 'dots' | 'text';
}

export interface Webhook {
  id: string;
  name: string;
  enabled: boolean;
  url: string;
  method: 'POST' | 'PUT';
  format: 'json' | 'form';
  headers: { name: string; value: string }[];
  mapping: { field: string; target: string }[];
  logic: Logic | null;
  secret?: string;
  hasSecret?: boolean;
}

export interface FormDocument {
  schemaVersion: number;
  fields: ElementNode[];
  settings: FormSettings;
}

export type FormStatus = 'active' | 'inactive';

/** Gallery card of `GET /form-templates` (metadata only, docs/rest-api.md). */
export interface FormTemplateCard {
  id: string;
  version: string;
  title: string;
  description?: string;
  icon?: string;
  category: string;
  tags: string[];
  fields: number;
  steps: number;
  conditions: number;
  isMultiStep: boolean;
}

/** Category group of the gallery. */
export interface TemplateCategory {
  slug: string;
  title: string;
  icon: string;
  count: number;
}

/** Full item of `GET /form-templates/{id}`; `previewUrl` renders the real form (demo only). */
export interface FormTemplate extends FormTemplateCard {
  document: FormDocument;
  previewUrl: string;
}

export interface FormSummary {
  id: number;
  title: string;
  status: FormStatus;
  currentVersion: number;
  hasDraft: boolean;
  entries: number;
  unread: number;
  webhookFailures?: number;
  createdAt: string;
  updatedAt: string;
}

export interface FormDetail {
  id: number;
  title: string;
  status: FormStatus;
  currentVersion: number;
  hasDraft: boolean;
  document: FormDocument;
  /** Element id => key of the published version. */
  publishedKeys: Record<string, string>;
  previewUrl: string;
  shortcode: string;
  updatedAt: string;
}

export interface SchemaError {
  path: string;
  code: string;
  message: string;
}

export type EntryFilter = 'inbox' | 'unread' | 'starred' | 'spam' | 'trash';
export type EntryStatus = 'unread' | 'read' | 'spam' | 'trash';

export interface EntrySummary {
  id: number;
  formId: number;
  formVersion: number;
  status: EntryStatus;
  starred: boolean;
  meta: { sourceUrl?: string };
  userId: number;
  createdAt: string;
  values: Record<string, string>;
}

export interface EntryList {
  items: EntrySummary[];
  total: number;
  pages: number;
  counts: Record<EntryFilter, number>;
  columns: { id: string; label: string }[];
}

export interface EntryNode {
  id: string;
  type: string;
  label: string;
  value?: string;
  files?: { name: string; url: string }[];
  children?: EntryNode[];
  rows?: EntryNode[][];
}

export interface EntryLogItem {
  type: string;
  actionId: string;
  status: 'success' | 'failed' | 'skipped' | string;
  message: string;
  createdAt: string;
}

export interface EntryDetail {
  entry: EntrySummary & { data: Record<string, unknown> };
  fields: EntryNode[];
  user: string;
  log: EntryLogItem[];
  webhooks?: WebhookDelivery[];
}

export interface WebhookDelivery {
  id: number;
  webhookId: string;
  eventId: string;
  status: 'pending' | 'running' | 'success' | 'failed';
  attempts: number;
  httpCode: number;
  durationMs: number;
  message: string;
  nextAttemptAt: string | null;
}

export interface WebhookTestResult {
  success: boolean;
  code: number;
  duration: number;
  message: string;
}

export type BulkAction = 'read' | 'unread' | 'star' | 'unstar' | 'spam' | 'trash' | 'restore' | 'delete';

export interface GlobalSettings {
  captcha?: Record<'turnstile' | 'hcaptcha', { siteKey: string; hasSecret?: boolean; secret?: string }>;
  ip_mode: 'hash' | 'none';
  from_email: string;
  from_name: string;
  delete_data_on_uninstall: boolean;
  load_css: boolean;
}

export interface WpPage {
  id: number;
  title: { rendered: string };
  link: string;
}
