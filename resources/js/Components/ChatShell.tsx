import { ReactNode, RefObject } from 'react';
import { MessageCircle } from 'lucide-react';

type ChatShellProps = {
  title: string;
  statusLabel: string;
  action?: ReactNode;
  children: ReactNode;
  footer: ReactNode;
  bodyRef?: RefObject<HTMLDivElement | null>;
  className?: string;
  heightClassName?: string;
  headerClassName?: string;
  bodyClassName?: string;
  footerClassName?: string;
};

export function ChatShell({
  title,
  statusLabel,
  action,
  children,
  footer,
  bodyRef,
  className = '',
  heightClassName = 'h-[500px]',
  headerClassName = '',
  bodyClassName = '',
  footerClassName = '',
}: ChatShellProps) {
  return (
    <div className={`flex ${heightClassName} w-full max-w-[500px] flex-col overflow-hidden rounded-2xl border ${className}`}>
      <div className={`flex items-center gap-3 border-b p-4 ${headerClassName}`}>
        <div className="flex h-11 w-11 items-center justify-center rounded-full bg-blue-600 text-white">
          <MessageCircle className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h4 className="truncate text-sm font-bold text-white">{title}</h4>
          <div className="flex items-center gap-1.5 text-xs font-bold text-green-400">
            <span className="h-1.5 w-1.5 rounded-full bg-green-400" />
            {statusLabel}
          </div>
        </div>
        {action}
      </div>

      <div ref={bodyRef} className={bodyClassName}>{children}</div>
      <div className={footerClassName}>{footer}</div>
    </div>
  );
}
