import type { ReactNode } from "react";
import { EmptyState, ErrorState, Skeleton } from "./FeedbackStates";

export interface DataTableColumn<T> {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  align?: "left" | "center" | "right";
  className?: string;
  headerClassName?: string;
}

interface DataTableProps<T> {
  caption: string;
  columns: DataTableColumn<T>[];
  rows: T[];
  getRowKey: (row: T, index: number) => string | number;
  loading?: boolean;
  error?: string | null;
  onRetry?: () => void;
  emptyTitle: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  mobileCard?: (row: T) => ReactNode;
  minWidth?: string;
  /** Batas tinggi kontainer scroll; header tetap sticky. Pakai "none" untuk menonaktifkan. */
  maxHeight?: string;
}

const alignClasses = {
  left: "text-left",
  center: "text-center",
  right: "text-right",
};

export function DataTable<T>({
  caption,
  columns,
  rows,
  getRowKey,
  loading = false,
  error,
  onRetry,
  emptyTitle,
  emptyDescription,
  emptyAction,
  mobileCard,
  minWidth = "720px",
  maxHeight = "70vh",
}: DataTableProps<T>) {
  const scrollVertically = maxHeight !== "none";

  return (
    <section className="overflow-hidden rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] shadow-[var(--shadow-raised)]">
      {error ? (
        <ErrorState message={error} onRetry={onRetry} />
      ) : loading ? (
        <div aria-label={`Memuat ${caption}`} aria-busy="true" className="space-y-3 p-4">
          {Array.from({ length: 6 }).map((_, index) => (
            <div key={index} className="flex gap-3">
              <Skeleton className="h-5 w-28" />
              <Skeleton className="h-5 flex-1" />
              <Skeleton className="h-5 w-20" />
            </div>
          ))}
        </div>
      ) : rows.length === 0 ? (
        <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />
      ) : (
        <>
          {mobileCard && (
            <div className="divide-y divide-[var(--color-border-subtle)] md:hidden">
              {rows.map((row, index) => (
                <div key={getRowKey(row, index)}>{mobileCard(row)}</div>
              ))}
            </div>
          )}
          <div
            className={`${scrollVertically ? "overflow-auto" : "overflow-x-auto"} ${mobileCard ? "hidden md:block" : ""}`}
            style={scrollVertically ? { maxHeight } : undefined}
          >
            <table className="w-full border-collapse text-[13px]" style={{ minWidth }}>
              <caption className="sr-only">{caption}</caption>
              <thead className="sticky top-0 z-10 border-b border-[var(--color-border)] bg-[var(--color-surface-subtle)]">
                <tr>
                  {columns.map((column) => (
                    <th
                      key={column.key}
                      scope="col"
                      className={`h-9 whitespace-nowrap px-3 font-semibold text-[var(--color-text-muted)] shadow-[inset_0_-1px_0_var(--color-border)] ${alignClasses[column.align ?? "left"]} ${column.headerClassName ?? ""}`}
                    >
                      {column.header}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-[var(--color-border-subtle)]">
                {rows.map((row, index) => (
                  <tr key={getRowKey(row, index)} className="h-10 transition-colors hover:bg-[var(--color-surface-subtle)]/70">
                    {columns.map((column) => (
                      <td
                        key={column.key}
                        className={`whitespace-nowrap px-3 py-2 text-[var(--color-text)] ${alignClasses[column.align ?? "left"]} ${column.className ?? ""}`}
                      >
                        {column.cell(row)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}
