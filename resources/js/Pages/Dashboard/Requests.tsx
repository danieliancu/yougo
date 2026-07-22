import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Card, SecondaryButton } from '@/Components/Ui';
import { useT } from '@/i18n';
import { PageProps } from '@/types';

type RequestListItem = {
  id: number;
  type: string;
  status: string;
  priority: string;
  title?: string | null;
  description?: string | null;
  channel: string;
  client_name?: string | null;
  client_phone?: string | null;
  preferred_date?: string | null;
  preferred_window?: string | null;
  location?: { id: number; name: string } | null;
  service?: { id: number; name: string } | null;
  assignee?: { id: number; name: string } | null;
  conversation_id?: number | null;
  created_at?: string | null;
};
type RequestListPayload = {
  items: RequestListItem[];
  pagination: { current_page: number; last_page: number; total: number; next_page_url?: string | null; prev_page_url?: string | null };
  filters: { status: string; priority: string; type: string; search: string };
  counters: { new: number; urgent: number; in_progress: number; resolved: number };
};

const STATUSES = ['new', 'in_progress', 'contacted', 'resolved', 'closed'];
const PRIORITIES = ['normal', 'high', 'urgent'];
const TYPES = ['general', 'quote', 'job', 'callback', 'diagnostic', 'information'];

function priorityTone(priority: string): 'slate' | 'amber' | 'red' {
  if (priority === 'urgent') return 'red';
  if (priority === 'high') return 'amber';

  return 'slate';
}

export default function Requests() {
  const t = useT();
  const { requests } = usePage<PageProps<{ requests?: RequestListPayload | null }>>().props;
  const [search, setSearch] = useState(requests?.filters.search ?? '');

  if (!requests) {
    return null;
  }

  function applyFilters(overrides: Partial<RequestListPayload['filters']>) {
    router.get('/dashboard/requests', { ...requests!.filters, ...overrides }, { preserveState: true, preserveScroll: true, replace: true });
  }

  function updateRequest(id: number, data: Record<string, string | number>) {
    router.patch(`/customer-requests/${id}`, data, { preserveState: true, preserveScroll: true });
  }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
        <Card className="p-4">
          <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('requestsCounterNew')}</p>
          <p className="mt-1 text-2xl font-bold app-text">{requests.counters.new}</p>
        </Card>
        <Card className="p-4">
          <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('requestsCounterUrgent')}</p>
          <p className="mt-1 text-2xl font-bold text-red-600">{requests.counters.urgent}</p>
        </Card>
        <Card className="p-4">
          <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('requestsCounterInProgress')}</p>
          <p className="mt-1 text-2xl font-bold app-text">{requests.counters.in_progress}</p>
        </Card>
        <Card className="p-4">
          <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t('requestsCounterResolved')}</p>
          <p className="mt-1 text-2xl font-bold app-text">{requests.counters.resolved}</p>
        </Card>
      </div>

      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-2">
          <form
            className="flex min-w-0 flex-1 items-center gap-2"
            onSubmit={(event) => { event.preventDefault(); applyFilters({ search }); }}
          >
            <input
              type="text"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={t('requestsSearchPlaceholder')}
              className="h-10 w-full min-w-0 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none app-panel app-text"
            />
            <SecondaryButton type="submit">{t('requestsSearchSubmit')}</SecondaryButton>
          </form>
          <select
            value={requests.filters.status}
            onChange={(event) => applyFilters({ status: event.target.value })}
            className="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm app-panel app-text"
          >
            <option value="">{t('requestsFilterAllStatuses')}</option>
            {STATUSES.map((status) => (
              <option key={status} value={status}>{t(`requestStatus_${status}`)}</option>
            ))}
          </select>
          <select
            value={requests.filters.priority}
            onChange={(event) => applyFilters({ priority: event.target.value })}
            className="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm app-panel app-text"
          >
            <option value="">{t('requestsFilterAllPriorities')}</option>
            {PRIORITIES.map((priority) => (
              <option key={priority} value={priority}>{t(`requestPriority_${priority}`)}</option>
            ))}
          </select>
          <select
            value={requests.filters.type}
            onChange={(event) => applyFilters({ type: event.target.value })}
            className="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm app-panel app-text"
          >
            <option value="">{t('requestsFilterAllTypes')}</option>
            {TYPES.map((type) => (
              <option key={type} value={type}>{t(`requestType_${type}`)}</option>
            ))}
          </select>
        </div>
      </Card>

      {requests.items.length === 0 ? (
        <Card className="p-8 text-center">
          <p className="text-sm app-text-muted">{t('requestsEmptyState')}</p>
        </Card>
      ) : (
        <div className="grid gap-3">
          {requests.items.map((item) => (
            <Card key={item.id} className="p-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={priorityTone(item.priority)}>{t(`requestPriority_${item.priority}`)}</Badge>
                    <span className="text-xs font-bold uppercase tracking-wide app-text-muted">{t(`requestType_${item.type}`)}</span>
                  </div>
                  {item.title && <p className="mt-2 font-bold app-text">{item.title}</p>}
                  {item.description && <p className="mt-1 text-sm app-text-soft">{item.description}</p>}
                  <p className="mt-2 text-sm app-text-muted">
                    {item.client_name} {item.client_phone ? `· ${item.client_phone}` : ''}
                  </p>
                  {item.conversation_id && (
                    <a href={`#conversation-${item.conversation_id}`} className="mt-1 inline-block text-xs font-bold text-indigo-600">
                      {t('requestsViewSourceConversation')}
                    </a>
                  )}
                </div>
                <div className="flex shrink-0 flex-col gap-2 sm:items-end">
                  <select
                    value={item.status}
                    onChange={(event) => updateRequest(item.id, { status: event.target.value })}
                    className="h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm app-panel app-text"
                  >
                    {STATUSES.map((status) => (
                      <option key={status} value={status}>{t(`requestStatus_${status}`)}</option>
                    ))}
                  </select>
                  <select
                    value={item.priority}
                    onChange={(event) => updateRequest(item.id, { priority: event.target.value })}
                    className="h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm app-panel app-text"
                  >
                    {PRIORITIES.map((priority) => (
                      <option key={priority} value={priority}>{t(`requestPriority_${priority}`)}</option>
                    ))}
                  </select>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {requests.pagination.last_page > 1 && (
        <div className="flex items-center justify-between">
          <SecondaryButton
            disabled={!requests.pagination.prev_page_url}
            onClick={() => requests.pagination.prev_page_url && router.get(requests.pagination.prev_page_url, {}, { preserveState: true, preserveScroll: true })}
          >
            {t('requestsPrevPage')}
          </SecondaryButton>
          <p className="text-sm app-text-muted">
            {requests.pagination.current_page} / {requests.pagination.last_page}
          </p>
          <SecondaryButton
            disabled={!requests.pagination.next_page_url}
            onClick={() => requests.pagination.next_page_url && router.get(requests.pagination.next_page_url, {}, { preserveState: true, preserveScroll: true })}
          >
            {t('requestsNextPage')}
          </SecondaryButton>
        </div>
      )}
    </div>
  );
}
