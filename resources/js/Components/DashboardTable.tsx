import { Check, MoreHorizontal, X } from 'lucide-react';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { TranslateFn } from '@/i18n';

// Shared table/row primitives used by every dashboard list (Bookings, Requests, Customers)
// so they all share one visual language — a disciplined table with a compact status pill
// and a "..." row actions menu, instead of each page inventing its own layout.

export function DashboardTable({ headers, children, minWidth = '920px' }: { headers: ReactNode[]; children: ReactNode; minWidth?: string }) {
  return (
    <div className="min-w-0 max-w-full overflow-hidden rounded-2xl border shadow-sm app-border app-panel">
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-sm" style={{ minWidth }}>
          {headers.length > 0 && (
            <thead className="app-panel-soft">
              <tr className="border-b app-border">
                {headers.map((header, index) => (
                  <th key={index} className="whitespace-nowrap px-5 py-4 text-xs font-semibold uppercase tracking-wide app-text-muted">
                    {header}
                  </th>
                ))}
              </tr>
            </thead>
          )}
          <tbody>{children}</tbody>
        </table>
      </div>
    </div>
  );
}

export function DashboardTableHeaderLabel({ children }: { children: ReactNode }) {
  return (
    <span>{children}</span>
  );
}

export function dashboardTableRowClass(index: number) {
  return `border-b last:border-b-0 app-border transition hover:bg-indigo-500/5 ${index % 2 === 0 ? 'app-panel' : 'app-panel-soft'}`;
}

const STATUS_TONES: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
  confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
  programat: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
  cancelled: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
  completed: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
  open: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-400',
  new: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
  in_progress: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
  contacted: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-300',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
  closed: 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300',
};

const STATUS_DOT_STATUSES = new Set(['pending', 'new']);
const STATUS_CHECK_STATUSES = new Set(['completed', 'resolved']);

// 'new' (request) and 'pending' (booking) mean the same thing — "awaiting action" —
// so they share the exact same label via one function, instead of two i18n keys that
// could drift apart. Anything reading a request status label (StatusPill, filters,
// quick-action menus) must go through this, never `t(`requestStatus_${status}`)` directly.
export function requestStatusLabel(status: string, t: TranslateFn): string {
  return status === 'new' ? t('statusPending') : t(`requestStatus_${status}`);
}

export function StatusPill({ status, t, compact = false, className = '' }: { status: string; t: TranslateFn; compact?: boolean; className?: string }) {
  const labels: Record<string, string> = {
    pending: t('statusPending'),
    confirmed: t('statusConfirmed'),
    programat: t('statusScheduled'),
    cancelled: t('statusCancelled'),
    completed: t('statusCompleted'),
    open: t('intentInquiry'),
    new: requestStatusLabel('new', t),
    in_progress: requestStatusLabel('in_progress', t),
    contacted: requestStatusLabel('contacted', t),
    resolved: requestStatusLabel('resolved', t),
    closed: requestStatusLabel('closed', t),
  };
  const sizeClasses = compact ? 'min-w-28 px-2 py-1 text-[10px]' : 'min-w-28 px-2 py-0.5 text-sm';

  return (
    <span className={`inline-flex items-center justify-center gap-1 whitespace-nowrap rounded-md font-semibold ${sizeClasses} ${STATUS_TONES[status] ?? STATUS_TONES.completed} ${className}`}>
      {STATUS_DOT_STATUSES.has(status) && <span className="railway-lights shrink-0" aria-hidden="true" />}
      {STATUS_CHECK_STATUSES.has(status) && <Check className="h-3 w-3 shrink-0 stroke-[3]" />}
      {status === 'cancelled' && <X className="h-3 w-3 shrink-0 stroke-[3]" />}
      {labels[status] ?? status}
    </span>
  );
}

export function RowActionsMenu({ label, children }: { label: string; children: (close: () => void) => ReactNode }) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ left: 0, top: 0 });
  const ref = useRef<HTMLDivElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    function onClickOutside(event: MouseEvent) {
      const target = event.target as Node;
      if (
        ref.current
        && !ref.current.contains(target)
        && menuRef.current
        && !menuRef.current.contains(target)
      ) {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, []);

  useEffect(() => {
    if (!open) return;

    function closeMenu() {
      setOpen(false);
    }

    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);

    return () => {
      window.removeEventListener('resize', closeMenu);
      window.removeEventListener('scroll', closeMenu, true);
    };
  }, [open]);

  function updatePosition() {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (!rect) return;

    const menuWidth = 192;
    const menuHeight = 224;
    const gap = 8;
    const left = Math.min(
      Math.max(16, rect.right - menuWidth),
      window.innerWidth - menuWidth - 16,
    );
    const spaceBelow = window.innerHeight - rect.bottom;
    const top = spaceBelow < menuHeight && rect.top > menuHeight
      ? rect.top - menuHeight - gap
      : rect.bottom + gap;

    setPosition({
      left,
      top: Math.max(16, Math.min(top, window.innerHeight - menuHeight - 16)),
    });
  }

  function toggleOpen() {
    if (!open) {
      updatePosition();
    }

    setOpen((value) => !value);
  }

  return (
    <div ref={ref} className="relative flex justify-end">
      <button
        ref={buttonRef}
        type="button"
        aria-label={label}
        title={label}
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={toggleOpen}
        className="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg app-text-muted transition hover:bg-[var(--app-panel-soft)] hover:app-text"
      >
        <MoreHorizontal className="h-4 w-4" />
      </button>
      {open && (
        <div
          ref={menuRef}
          className="fixed z-50 w-48 rounded-lg border p-1 shadow-xl app-border app-panel"
          style={{ left: position.left, top: position.top }}
        >
          {children(() => setOpen(false))}
        </div>
      )}
    </div>
  );
}

export function RowActionButton({ children, onClick, tone = 'default' }: { children: ReactNode; onClick: () => void; tone?: 'default' | 'danger' }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-semibold transition hover:bg-[var(--app-panel-soft)] ${tone === 'danger' ? 'text-red-600' : 'app-text-soft'}`}
    >
      {children}
    </button>
  );
}

export function RowActionLink({ children, href }: { children: ReactNode; href: string }) {
  return (
    <a href={href} className="flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-semibold transition app-text-soft hover:bg-[var(--app-panel-soft)]">
      {children}
    </a>
  );
}
