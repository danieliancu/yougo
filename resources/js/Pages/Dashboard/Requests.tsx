import { router, usePage } from '@inertiajs/react';
import { MessageCircle, Phone } from 'lucide-react';
import { useState } from 'react';
import { Card, SecondaryButton } from '@/Components/Ui';
import { DashboardTable, DashboardTableHeaderLabel, dashboardTableRowClass, requestStatusLabel, RowActionButton, RowActionLink, RowActionsMenu, StatusPill } from '@/Components/DashboardTable';
import { TranslateFn, useT } from '@/i18n';
import { formatBusinessDateWithWeekday, formatTimeOnly } from '@/lib/dateFormat';
import { PageProps, Salon } from '@/types';

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
  const { requests, salon } = usePage<PageProps<{ requests?: RequestListPayload | null; salon: Pick<Salon, 'date_format'> }>>().props;
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
              <option key={status} value={status}>{requestStatusLabel(status, t)}</option>
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
        <>
          <div className="space-y-3 md:hidden">
            {requests.items.map((item) => (
              <MobileRequestCard key={item.id} item={item} t={t} salon={salon} onUpdate={updateRequest} />
            ))}
          </div>
          <div className="hidden md:block">
            <DashboardTable
              headers={[
                <DashboardTableHeaderLabel>{t('date')}</DashboardTableHeaderLabel>,
                <DashboardTableHeaderLabel>{t('time')}</DashboardTableHeaderLabel>,
                <DashboardTableHeaderLabel>{t('priorityAndStatus')}</DashboardTableHeaderLabel>,
                <DashboardTableHeaderLabel>{t('name')}</DashboardTableHeaderLabel>,
                <DashboardTableHeaderLabel>{t('phone')}</DashboardTableHeaderLabel>,
                <DashboardTableHeaderLabel>{t('details')}</DashboardTableHeaderLabel>,
                <span className="sr-only">{t('actions')}</span>,
              ]}
              minWidth="1100px"
            >
              {requests.items.map((item, index) => (
                <tr key={item.id} className={dashboardTableRowClass(index)}>
                  <td className="whitespace-nowrap px-5 py-4 align-top text-sm font-semibold app-text">
                    {item.created_at ? formatBusinessDateWithWeekday(item.created_at, salon) : '—'}
                  </td>
                  <td className="whitespace-nowrap px-5 py-4 align-top text-sm font-semibold app-text">
                    {item.created_at ? formatTimeOnly(item.created_at) : '—'}
                  </td>
                  <td className="px-5 py-4 align-top">
                    <div className="flex items-center gap-2">
                      <PrioritySelect priority={item.priority} onChange={(priority) => updateRequest(item.id, { priority })} />
                      <StatusPill status={item.status} t={t} />
                    </div>
                  </td>
                  <td className="px-5 py-4 align-top font-semibold app-text">{item.client_name || '—'}</td>
                  <td className="whitespace-nowrap px-5 py-4 align-top app-text-soft">{item.client_phone || '—'}</td>
                  <td className="min-w-72 px-5 py-4 align-top app-text-soft">
                    <RequestDetailsCell item={item} t={t} />
                  </td>
                  <td className="w-14 px-5 py-4 align-top">
                    <RequestActionsMenu item={item} t={t} onUpdate={updateRequest} />
                  </td>
                </tr>
              ))}
            </DashboardTable>
          </div>
        </>
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

function RequestDetailsCell({ item, t }: { item: RequestListItem; t: TranslateFn }) {
  return (
    <div className="space-y-1">
      <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t(`requestType_${item.type}`)}</p>
      {item.title && <p className="font-semibold app-text">{item.title}</p>}
      {item.description && <p className="line-clamp-2 text-sm app-text-soft">{item.description}</p>}
      {(item.location?.name || item.service?.name) && (
        <p className="text-xs app-text-muted">
          {[item.service?.name, item.location?.name].filter(Boolean).join(' · ')}
        </p>
      )}
    </div>
  );
}

function MobileRequestCard({ item, t, salon, onUpdate }: { item: RequestListItem; t: TranslateFn; salon: Pick<Salon, 'date_format'>; onUpdate: (id: number, data: Record<string, string | number>) => void }) {
  return (
    <Card className="p-4">
      <div className="flex min-w-0 items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-bold app-text">{item.client_name || '—'}</p>
          <p className="mt-1 text-xs font-semibold app-text-muted">
            {item.created_at ? `${formatBusinessDateWithWeekday(item.created_at, salon)}, ${formatTimeOnly(item.created_at)}` : '—'}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <PrioritySelect priority={item.priority} onChange={(priority) => onUpdate(item.id, { priority })} />
          <StatusPill status={item.status} t={t} />
          <RequestActionsMenu item={item} t={t} onUpdate={onUpdate} />
        </div>
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-2">
        <span className="text-xs font-bold uppercase tracking-wide app-text-muted">{t(`requestType_${item.type}`)}</span>
      </div>
      <div className="mt-3 space-y-1 text-sm">
        <p className="app-text-soft">{item.client_phone || t('phoneMissingShort')}</p>
        {item.title && <p className="font-semibold app-text">{item.title}</p>}
        {item.description && <p className="app-text-soft">{item.description}</p>}
      </div>
    </Card>
  );
}

function PrioritySelect({ priority, onChange }: { priority: string; onChange: (priority: string) => void }) {
  const t = useT();

  return (
    <select
      value={priority}
      onChange={(event) => onChange(event.target.value)}
      className={`h-7 shrink-0 rounded-md border-0 px-1.5 text-xs font-bold uppercase tracking-wide outline-none ${priorityTone(priority) === 'red' ? 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300' : priorityTone(priority) === 'amber' ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300'}`}
    >
      {PRIORITIES.map((option) => (
        <option key={option} value={option}>{t(`requestPriority_${option}`)}</option>
      ))}
    </select>
  );
}

function RequestActionsMenu({ item, t, onUpdate }: { item: RequestListItem; t: TranslateFn; onUpdate: (id: number, data: Record<string, string | number>) => void }) {
  return (
    <RowActionsMenu label={t('actions')}>
      {(close) => (
        <>
          {STATUSES.filter((status) => status !== item.status).map((status) => (
            <RowActionButton key={status} onClick={() => { close(); onUpdate(item.id, { status }); }}>
              {requestStatusLabel(status, t)}
            </RowActionButton>
          ))}
          {item.client_phone && (
            <RowActionLink href={`tel:${item.client_phone}`}>
              <Phone className="h-4 w-4 text-green-600" />
              {item.client_phone}
            </RowActionLink>
          )}
          {item.conversation_id && (
            <RowActionLink href={`#conversation-${item.conversation_id}`}>
              <MessageCircle className="h-4 w-4" />
              {t('requestsViewSourceConversation')}
            </RowActionLink>
          )}
        </>
      )}
    </RowActionsMenu>
  );
}
