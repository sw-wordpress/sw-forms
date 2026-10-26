import { getConfig } from '@/config';
import type {
  BulkAction,
  DeliveryList,
  DeliveryStatus,
  EntryDetail,
  EntryFilter,
  EntryList,
  EntryStatus,
  EntrySummary,
  FieldTypeDefinition,
  FormDetail,
  FormDocument,
  FormStatus,
  FormSummary,
  GlobalSettings,
  Webhook,
  WebhookDelivery,
  WebhookTestResult,
  WpPage,
} from '@/types/api';
import { ApiClient } from './client';

let client: ApiClient | null = null;
let wpClient: ApiClient | null = null;

export function api(): ApiClient {
  if (!client) {
    const config = getConfig();
    client = new ApiClient({ restRoot: config.restRoot, nonce: config.nonce });
  }
  return client;
}

function wp(): ApiClient {
  if (!wpClient) {
    const config = getConfig();
    wpClient = new ApiClient({ restRoot: config.wpRestRoot, nonce: config.nonce });
  }
  return wpClient;
}

/** For tests. */
export function setApiForTests(value: ApiClient | null): void {
  client = value;
}

export const formsApi = {
  list: () => api().get<{ items: FormSummary[] }>('forms').then((r) => r.items),
  create: (title: string) => api().post<FormDetail>('forms', { title }),
  get: (id: number) => api().get<FormDetail>(`forms/${id}`),
  save: (id: number, title: string, document: FormDocument) =>
    api().put<FormDetail>(`forms/${id}`, { title, document }),
  saveDraft: (id: number, document: FormDocument) =>
    api().put<{ saved: boolean; previewUrl: string }>(`forms/${id}/draft`, { document }),
  discardDraft: (id: number) => api().delete<FormDetail>(`forms/${id}/draft`),
  setStatus: (id: number, status: FormStatus) =>
    api().request<FormDetail>('PATCH', `forms/${id}`, { body: { status } }),
  duplicate: (id: number) => api().post<FormDetail>(`forms/${id}/duplicate`),
  remove: (id: number) => api().delete<{ deleted: boolean }>(`forms/${id}`),
  fieldTypes: () => api().get<{ items: FieldTypeDefinition[] }>('field-types').then((r) => r.items),
};

export const entriesApi = {
  list: (formId: number, query: { filter: EntryFilter; search: string; page: number; per_page: number; order: 'asc' | 'desc' }) =>
    api().get<EntryList>(`forms/${formId}/entries`, query),
  get: (id: number) => api().get<EntryDetail>(`entries/${id}`),
  update: (id: number, changes: { status?: EntryStatus; starred?: boolean }) =>
    api().request<EntrySummary>('PATCH', `entries/${id}`, { body: changes }),
  remove: (id: number) => api().delete<{ deleted: boolean }>(`entries/${id}`),
  bulk: (ids: number[], action: BulkAction) => api().post<{ affected: number }>('entries/bulk', { ids, action }),
};

export const webhooksApi = {
  /** Sends example data synchronously; `document` provides the (unsaved) fields. */
  test: (formId: number, webhook: Webhook, document: FormDocument) =>
    api().post<WebhookTestResult>(`forms/${formId}/webhooks/test`, { webhook, document }),
  deliveries: (formId: number, query: { status?: DeliveryStatus | ''; webhook?: string; page?: number; per_page?: number }) =>
    api().get<DeliveryList>(`forms/${formId}/webhooks/deliveries`, query),
  /** Resend or dismiss all failed deliveries of a form. */
  bulk: (formId: number, action: 'resend' | 'dismiss') =>
    api().post<{ affected: number }>(`forms/${formId}/webhooks/deliveries/bulk`, { action }),
  resend: (id: number) => api().post<WebhookDelivery>(`webhooks/deliveries/${id}/resend`),
  dismiss: (id: number) => api().post<WebhookDelivery>(`webhooks/deliveries/${id}/dismiss`),
};

export const settingsApi = {
  get: () => api().get<GlobalSettings>('settings'),
  save: (settings: GlobalSettings) => api().put<GlobalSettings>('settings', settings),
};

export const pagesApi = {
  search: (search: string) =>
    wp().get<WpPage[]>('pages', { search, per_page: 20, _fields: 'id,title,link', status: 'publish' }),
  get: (id: number) => wp().get<WpPage>(`pages/${id}`, { _fields: 'id,title,link' }),
};
