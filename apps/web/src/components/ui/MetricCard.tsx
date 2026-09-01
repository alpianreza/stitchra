import type { ReactNode } from "react";

type MetricTone = "neutral" | "info" | "warning" | "danger" | "success";

const toneClasses: Record<MetricTone, string> = {
  neutral: "border-[var(--color-border-subtle)]",
  info: "border-sky-200",
  warning: "border-amber-300",
  danger: "border-red-300",
  success: "border-green-300",
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
    <section className={`rounded-[var(--radius-surface)] border bg-[var(--color-surface)] p-4 ${toneClasses[tone]}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">{label}</p>
          <div className="mt-1 text-2xl font-bold tabular-nums text-[var(--color-text)]">{value}</div>
          {supportingText && <div className="mt-1 text-xs text-[var(--color-text-muted)]">{supportingText}</div>}
        </div>
        {action}
      </div>
    </section>
  );
}
