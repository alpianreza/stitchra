type StatusTone = "neutral" | "info" | "success" | "warning" | "danger";

const statusTones: Record<string, StatusTone> = {
  DRAFT: "neutral",
  PLANNED: "neutral",
  PENDING: "neutral",
  SUBMITTED: "info",
  POSTED: "info",
  IN_PROGRESS: "info",
  IN_TRANSIT: "info",
  CUTTING: "info",
  SEWING: "info",
  FINISHING: "info",
  QC: "info",
  SENT: "info",
  APPROVED: "success",
  CONFIRMED: "success",
  RELEASED: "success",
  PASS: "success",
  OK: "success",
  ACTIVE: "success",
  RECEIVED: "success",
  PACKED: "success",
  SHIPPED: "success",
  COMPLETED: "success",
  CLOSED: "neutral",
  REVISION: "warning",
  PARTIAL_RECEIVED: "warning",
  PARTIAL_RETURNED: "warning",
  QUALITY_HOLD: "warning",
  DEVIATION: "warning",
  REJECTED: "danger",
  FAIL: "danger",
  CANCELLED: "danger",
};

const toneClasses: Record<StatusTone, string> = {
  neutral: "border-[var(--color-border)] bg-[var(--color-surface-subtle)] text-[var(--color-text-muted)]",
  info: "border-sky-200 bg-[var(--color-info-soft)] text-[var(--color-info)]",
  success: "border-green-200 bg-[var(--color-success-soft)] text-[var(--color-success)]",
  warning: "border-amber-200 bg-[var(--color-warning-soft)] text-[var(--color-warning)]",
  danger: "border-red-200 bg-[var(--color-danger-soft)] text-[var(--color-danger)]",
};

interface StatusBadgeProps {
  status: string;
  label?: string;
  className?: string;
}

export function StatusBadge({ status, label, className = "" }: StatusBadgeProps) {
  const normalized = status.trim().toUpperCase().replaceAll(" ", "_");
  const tone = statusTones[normalized] ?? "neutral";

  return (
    <span
      className={[
        "inline-flex min-h-5 items-center rounded-full border px-2 py-0.5 text-xs font-semibold leading-none",
        toneClasses[tone],
        className,
      ].join(" ")}
      data-status={normalized}
    >
      {label ?? status.replaceAll("_", " ")}
    </span>
  );
}
