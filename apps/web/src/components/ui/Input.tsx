import { forwardRef, type InputHTMLAttributes } from "react";

const controlClasses = "min-h-10 w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-text)] shadow-sm placeholder:text-slate-400 disabled:cursor-not-allowed disabled:bg-[var(--color-surface-subtle)] disabled:text-[var(--color-text-muted)] disabled:opacity-70 read-only:bg-[var(--color-surface-subtle)] aria-[invalid=true]:border-[var(--color-danger)]";

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ className = "", invalid, ...props }, ref) {
  return <input ref={ref} aria-invalid={invalid || undefined} className={`${controlClasses} ${className}`} {...props} />;
});
