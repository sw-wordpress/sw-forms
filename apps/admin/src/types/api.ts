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
  logic: Logic | null;
}

export interface WebhookHeader {
  name: string;
  /** Empty for secret headers: the value is write-only (stored encrypted on the server). */
  value: string;
  secret: boolean;
  /** From the server: a secret value is stored. */
  hasValue?: boolean;
}

export interface WebhookMapping {
  /** Element id (stable across key renames). */
  field: string;
  /** Target name, dots nest (`contact.email`). */
  name: string;
}

export interface Webhook {
  id: string;
  name: string;
  enabled: boolean;
  url: string;
  method: 'POST' | 'PUT' | 'PATCH';
  format: 'json' | 'form';
  envelope: boolean;
  fields: 'all' | 'custom';
  mapping: WebhookMapping[];
  headers: WebhookHeader[];
  logic: Logic | null;
  /** From the server: a signing secret is stored. */
  hasSecret?: boolean;
  /** Write-only: new signing secret. */
  secret?: string;
  /** Write-only: remove the stored signing secret. */
  clearSecret?: boolean;
}

export type DeliveryStatus = 'pending' | 'success' | 'failed' | 'dismissed';

export interface WebhookDelivery {
  id: number;
  formId: number;
  entryId: number;
  webhookId: string;
  event: string;
  eventId: string;
  status: DeliveryStatus;
  attempts: number;
  responseCode: number;
  message: string;
  durationMs: number;
  nextAttemptAt: string | null;
  createdAt: string;
  updatedAt: string;
  resendable: boolean;
}

export interface DeliveryList {
  items: WebhookDelivery[];
  total: number;
  pages: number;
  failures: number;
}

export interface WebhookTestResult {
  success: boolean;
  status: number | null;
  message: string;
  durationMs: number;
  response: string;
  payload: Record<string, unknown>;
}

export interface FormSettings {
  submitLabel: string;
  cssClass: string;
  confirmation: { type: 'message' | 'redirect' | 'page'; message: string; url: string; pageId: number };
  storeEntries: boolean;
  retentionDays: number;
  spam: { honeypot: boolean; minSeconds: number; rateLimit: number };
  notifications: Notification[];
  webhooks: Webhook[];
}

export interface FormDocument {
  schemaVersion: number;
  fields: ElementNode[];
  settings: FormSettings;
}

export type FormStatus = 'active' | 'inactive';

export interface FormSummary {
  id: number;
  title: string;
  status: FormStatus;
  currentVersion: number;
  hasDraft: boolean;
  entries: number;
  unread: number;
  /** Webhook deliveries that failed for good and were not dismissed. */
  webhookFailures: number;
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
  children?: EntryNode[];
  rows?: EntryNode[][];
}

export interface EntryLogItem {
  type: string;
  actionId: string;
  status: 'success' | 'failed' | 'skipped' | 'queued' | 'retry' | string;
  message: string;
  createdAt: string;
}

export interface EntryDetail {
  entry: EntrySummary & { data: Record<string, unknown> };
  fields: EntryNode[];
  user: string;
  log: EntryLogItem[];
  deliveries: WebhookDelivery[];
}

export type BulkAction = 'read' | 'unread' | 'star' | 'unstar' | 'spam' | 'trash' | 'restore' | 'delete';

export interface GlobalSettings {
  ip_mode: 'hash' | 'none';
  from_email: string;
  from_name: string;
  delete_data_on_uninstall: boolean;
  load_css: boolean;
  /** Empty: the site's admin e-mail address. */
  webhook_alert_email: string;
  /** Internal hosts webhooks may call (host, *.domain, IP, CIDR). */
  webhook_allowed_hosts: string[];
}

export interface WpPage {
  id: number;
  title: { rendered: string };
  link: string;
}
