import type { ReactNode } from "react";

export function FilterBar({ children, summary }: { children: ReactNode; summary?: ReactNode }) {
  return (
    <div className="flex flex-col gap-3 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-raised)] sm:flex-row sm:items-center sm:justify-between">
      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">{children}</div>
      {summary && <div className="shrink-0 text-xs text-[var(--color-text-muted)]">{summary}</div>}
    </div>
  );
}

export function FilterSelect({ label, className = "", children, ...props }: React.SelectHTMLAttributes<HTMLSelectElement> & { label: string }) {
  return (
    <label className="flex min-w-44 items-center gap-2 text-sm">
      <span className="sr-only">{label}</span>
      <select
        aria-label={label}
        className={`min-h-9 w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-2.5 text-sm text-[var(--color-text)] hover:border-slate-400 disabled:cursor-not-allowed disabled:opacity-50 ${className}`}
        {...props}
      >
        {children}
      </select>
    </label>
  );
}
