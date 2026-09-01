import type { ReactNode } from "react";
import { Button } from "./Button";

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex min-h-40 flex-col items-center justify-center px-6 py-10 text-center">
      <div aria-hidden="true" className="mb-3 flex size-10 items-center justify-center rounded-full bg-[var(--color-surface-subtle)] text-[var(--color-text-muted)]">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="size-5">
          <path d="M5 4h14v16H5zM8 8h8M8 12h5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </div>
      <p className="font-semibold text-[var(--color-text)]">{title}</p>
      {description && <p className="mt-1 max-w-md text-sm text-[var(--color-text-muted)]">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({
  title = "Data tidak dapat dimuat",
  message,
  onRetry,
}: {
  title?: string;
  message?: string;
  onRetry?: () => void;
}) {
  return (
    <div role="alert" className="flex min-h-40 flex-col items-center justify-center bg-[var(--color-danger-soft)]/40 px-6 py-10 text-center">
      <div aria-hidden="true" className="mb-3 flex size-10 items-center justify-center rounded-full bg-[var(--color-danger-soft)] text-[var(--color-danger)]">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="size-5">
          <path d="M12 8v5M12 17h.01M10 3h4l8 16H2L10 3z" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </div>
      <p className="font-semibold text-[var(--color-danger)]">{title}</p>
      {message && <p className="mt-1 max-w-lg text-sm text-[var(--color-text-muted)]">{message}</p>}
      {onRetry && <Button className="mt-4" onClick={onRetry}>Coba lagi</Button>}
    </div>
  );
}

export function Skeleton({ className = "" }: { className?: string }) {
  return <span aria-hidden="true" className={`block animate-pulse rounded bg-slate-200 ${className}`} />;
}
