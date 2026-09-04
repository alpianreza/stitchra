import type { ReactNode } from "react";

type MetricTone = "neutral" | "info" | "warning" | "danger" | "success";

const toneClasses: Record<MetricTone, string> = {
  neutral: "border-[var(--color-border-subtle)] bg-[var(--color-surface)]",
  info: "border-[var(--color-info-soft-border)] bg-[var(--color-info-soft)]/30",
  warning: "border-[var(--color-warning-soft-border)] bg-[var(--color-warning-soft)]/30",
  danger: "border-[var(--color-danger-soft-border)] bg-[var(--color-danger-soft)]/30",
  success: "border-[var(--color-success-soft-border)] bg-[var(--color-success-soft)]/30",
};

const valueClasses: Record<MetricTone, string> = {
  neutral: "text-[var(--color-text)]",
  info: "text-[var(--color-info)]",
  warning: "text-[var(--color-warning)]",
  danger: "text-[var(--color-danger)]",
  success: "text-[var(--color-success)]",
};

interface MetricCardProps {
  label: string;
  value: ReactNode;
  supportingText?: ReactNode;
  tone?: MetricTone;
  action?: ReactNode;
}

export function MetricCard({ label, value, supportingText, tone = "neutral", action }: MetricCardProps) {
  return (
    <section className={`rounded-[var(--radius-surface)] border p-4 shadow-[var(--shadow-raised)] ${toneClasses[tone]}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">{label}</p>
          <div className={`mt-1 text-2xl font-bold tabular-nums ${valueClasses[tone]}`}>{value}</div>
          {supportingText && <div className="mt-1 text-xs text-[var(--color-text-muted)]">{supportingText}</div>}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>
    </section>
  );
}
