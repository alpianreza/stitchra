import { cloneElement, isValidElement, type ReactElement, type ReactNode } from "react";

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
  const errorId = `${htmlFor}-error`;
  const hintId = `${htmlFor}-hint`;

  // Sambungkan kontrol (anak tunggal) dengan pesan hint/error via aria-describedby
  // dan set aria-invalid bila ada error dan kontrol belum menandainya sendiri.
  let control = children;
  if (isValidElement(children)) {
    const child = children as ReactElement<Record<string, unknown>>;
    const describedBy =
      [child.props["aria-describedby"], hint ? hintId : null, error ? errorId : null]
        .filter((value): value is string => Boolean(value))
        .join(" ") || undefined;
    const merged: Record<string, unknown> = { "aria-describedby": describedBy };
    if (error && child.props["aria-invalid"] === undefined) {
      merged["aria-invalid"] = true;
    }
    control = cloneElement(child, merged);
  }

  return (
    <div className={className}>
      <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-semibold text-[var(--color-text)]">
        {label}
        {required && <span aria-hidden="true" className="ml-0.5 text-[var(--color-danger)]">*</span>}
        {required && <span className="sr-only"> (wajib)</span>}
      </label>
      {control}
      {error ? (
        <p id={errorId} role="alert" className="mt-1.5 text-xs font-medium text-[var(--color-danger)]">
          {error}
        </p>
      ) : hint ? (
        <p id={hintId} className="mt-1.5 text-xs text-[var(--color-text-muted)]">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

export function FieldGroup({
  legend,
  description,
  children,
  className = "",
}: {
  legend: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <fieldset className={`rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-4 shadow-xs ${className}`}>
      <legend className="px-1 text-sm font-semibold text-[var(--color-text)]">{legend}</legend>
      {description && <p className="mb-4 text-xs text-[var(--color-text-muted)]">{description}</p>}
      {children}
    </fieldset>
  );
}
