import { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, useEffect, useState } from 'react';
import { clsx } from 'clsx';
import { Moon, Sun } from 'lucide-react';

export function Button({ className, ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...props}
      className={clsx(
        'inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
    />
  );
}

export function SecondaryButton({ className, ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type={props.type ?? 'button'}
      {...props}
      className={clsx(
        'inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50',
        'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]',
        className,
      )}
    />
  );
}

export function DangerButton({ className, ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type={props.type ?? 'button'}
      {...props}
      className={clsx(
        'inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-red-50 px-3 text-sm font-medium text-red-600 transition hover:bg-red-100 disabled:opacity-50',
        className,
      )}
    />
  );
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      {...props}
      className={clsx(
        'h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100',
        'app-panel app-text placeholder:text-[var(--app-text-muted)] focus:ring-[var(--app-focus)]',
        className,
      )}
    />
  );
}

export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <label className="block space-y-1.5">
      <span className="text-xs font-bold uppercase tracking-wide app-text-muted">{label}</span>
      {children}
      {error && <span className="block text-xs font-medium text-red-600">{error}</span>}
    </label>
  );
}

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={clsx('rounded-lg border shadow-sm app-panel', className)}>{children}</div>;
}

export function AlertModal({
  open,
  title,
  message,
  okLabel = 'OK',
  onClose,
}: {
  open: boolean;
  title: string;
  message: string;
  okLabel?: string;
  onClose: () => void;
}) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="w-full max-w-sm rounded-lg border p-6 shadow-xl app-panel">
        <h2 className="mb-2 text-base font-bold app-text">{title}</h2>
        <p className="mb-6 text-sm app-text-soft">{message}</p>
        <div className="flex justify-end">
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-bold text-white hover:bg-indigo-700"
          >
            {okLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

export function ConfirmationModal({
  open,
  title,
  message,
  confirmLabel,
  cancelLabel,
  tone = 'danger',
  onConfirm,
  onCancel,
}: {
  open: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  cancelLabel: string;
  tone?: 'danger' | 'neutral';
  onConfirm: () => void;
  onCancel: () => void;
}) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="w-full max-w-sm rounded-lg border p-6 shadow-xl app-panel">
        <h2 className="mb-2 text-base font-bold app-text">{title}</h2>
        <p className="mb-6 text-sm app-text-soft">{message}</p>
        <div className="flex justify-end gap-3">
          <button
            type="button"
            onClick={onCancel}
            className="rounded-lg border px-4 py-2 text-sm font-bold app-panel app-text-soft hover:bg-[var(--app-panel-soft)]"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            className={clsx(
              'rounded-lg px-4 py-2 text-sm font-bold text-white',
              tone === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700',
            )}
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

export function Badge({ children, tone = 'slate' }: { children: ReactNode; tone?: 'slate' | 'amber' | 'green' | 'red' | 'indigo' }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-600',
    amber: 'bg-amber-50 text-amber-700',
    green: 'bg-green-50 text-green-700',
    red: 'bg-red-50 text-red-700',
    indigo: 'bg-indigo-50 text-indigo-700',
  };

  return <span className={clsx('rounded-md px-2.5 py-1 text-xs font-bold uppercase tracking-wide', tones[tone])}>{children}</span>;
}

export function ThemeToggle() {
  const [dark, setDark] = useState(() => {
    if (typeof window === 'undefined') return false;

    const saved = window.localStorage.getItem('yougo-theme');
    if (saved) return saved === 'dark';

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  });

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.classList.add('theme-transition');
    window.localStorage.setItem('yougo-theme', dark ? 'dark' : 'light');
  }, [dark]);

  return (
    <button
      type="button"
      onClick={() => setDark((value) => !value)}
      className="inline-flex h-10 w-10 items-center justify-center app-text-soft"
      aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
      title={dark ? 'Light mode' : 'Dark mode'}
    >
      {dark ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
    </button>
  );
}
