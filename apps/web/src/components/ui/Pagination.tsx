import { Button } from "./Button";

interface PaginationProps {
  page: number;
  totalPages: number;
  totalRecords?: number;
  onPageChange: (page: number) => void;
}

export function Pagination({ page, totalPages, totalRecords, onPageChange }: PaginationProps) {
  if (totalPages <= 1 && totalRecords === undefined) return null;

  return (
    <nav aria-label="Pagination" className="flex flex-col gap-2 border-t border-[var(--color-border-subtle)] px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
      <p className="text-xs text-[var(--color-text-muted)]">
        Halaman <strong className="text-[var(--color-text)]">{page}</strong> dari {Math.max(1, totalPages)}
        {totalRecords !== undefined && <> · {totalRecords.toLocaleString("id-ID")} data</>}
      </p>
      <div className="flex items-center gap-2">
        <Button size="sm" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>Sebelumnya</Button>
        <Button size="sm" disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>Berikutnya</Button>
      </div>
    </nav>
  );
}
