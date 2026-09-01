export function ProgressBar({ value, max = 100, label }: { value: number; max?: number; label?: string }) {
  const safeMax = max > 0 ? max : 1;
  const percentage = Math.min(100, Math.max(0, (value / safeMax) * 100));

  return (
    <div className="min-w-28" aria-label={label ?? `Progress ${Math.round(percentage)}%`}>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-200">
        <div className="h-full rounded-full bg-[var(--color-primary)]" style={{ width: `${percentage}%` }} />
      </div>
      <span className="sr-only">{Math.round(percentage)}%</span>
    </div>
  );
}
