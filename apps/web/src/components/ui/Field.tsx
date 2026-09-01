import type { ReactNode } from "react";

interface FieldProps {
  label: ReactNode;
  htmlFor: string;
  children: ReactNode;
  hint?: ReactNode;
  error?: ReactNode;
  required?: boolean;
  className?: string;
}

export function Field({ label, htmlFor, children, hint, error, required = false, className = "" }: FieldProps) {
  return (
    <div className={className}>
      <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-semibold text-[var(--color-text)]">
        {label}
        {required && <span aria-hidden="true" className="ml-0.5 text-[var(--color-danger)]">*</span>}
        {required && <span className="sr-only"> (wajib)</span>}
      </label>
      {children}
      {error ? (
        <p id={`${htmlFor}-error`} role="alert" className="mt-1.5 text-xs text-[var(--color-danger)]">{error}</p>
      ) : hint ? (
        <p id={`${htmlFor}-hint`} className="mt-1.5 text-xs text-[var(--color-text-muted)]">{hint}</p>
      ) : null}
    </div>
  );
}

export function FieldGroup({ legend, description, children, className = "" }: { legend: ReactNode; description?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <fieldset className={`rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-4 ${className}`}>
      <legend className="px-1 text-sm font-semibold text-[var(--color-text)]">{legend}</legend>
      {description && <p className="mb-4 text-xs text-[var(--color-text-muted)]">{description}</p>}
      {children}
    </fieldset>
  );
}
