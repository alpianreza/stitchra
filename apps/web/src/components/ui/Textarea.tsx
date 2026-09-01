import { forwardRef, type TextareaHTMLAttributes } from "react";

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  invalid?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea({ className = "", invalid, ...props }, ref) {
  return (
    <textarea
      ref={ref}
      aria-invalid={invalid || undefined}
      className={`min-h-24 w-full resize-y rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-text)] shadow-sm placeholder:text-slate-400 disabled:cursor-not-allowed disabled:bg-[var(--color-surface-subtle)] disabled:text-[var(--color-text-muted)] disabled:opacity-70 read-only:bg-[var(--color-surface-subtle)] aria-[invalid=true]:border-[var(--color-danger)] ${className}`}
      {...props}
    />
  );
});
