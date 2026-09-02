"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { DataTable, FilterBar, FilterSelect, PageHeader, StatusBadge, type DataTableColumn } from "@/components/ui";

interface Pr {
  id: number;
  doc_no: string;
  source: string;
  status: string;
  needed_by: string | null;
  lines_count: number;
  created_at: string;
}

interface Page { data: Pr[]; total: number }

/** Daftar PR — termasuk yang dihasilkan dari MRP (source=MRP, BR-045/120) */
export default function PurchaseRequestsPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [source, setSource] = useState("");
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true); setError(null);
    api.get<Page>(`/purchasing/prs${source ? `?source=${source}` : ""}`)
      .then(setPage)
      .catch((requestError) => setError(requestError.message))
      .finally(() => setLoading(false));
  }, [source]);

  useEffect(load, [load]);

  const columns: DataTableColumn<Pr>[] = [
    { key: "document", header: "No. PR", cell: (pr) => <span className="font-mono font-semibold">{pr.doc_no}</span> },
    { key: "source", header: "Sumber", cell: (pr) => <StatusBadge status={pr.source} /> },
    { key: "needed", header: "Dibutuhkan", cell: (pr) => pr.needed_by ?? "—" },
    { key: "lines", header: "Lines", align: "right", cell: (pr) => <span className="tabular-nums">{pr.lines_count}</span> },
    { key: "status", header: "Status", cell: (pr) => <StatusBadge status={pr.status} /> },
    { key: "created", header: "Dibuat", cell: (pr) => new Date(pr.created_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) },
  ];

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Purchasing" title="Purchase Request" description="Pantau kebutuhan pembelian manual dan hasil perencanaan MRP." />
      <FilterBar summary={page ? `${page.total} purchase request` : undefined}>
        <FilterSelect label="Sumber" value={source} onChange={(event) => setSource(event.target.value)}>
          <option value="">Semua sumber</option>
          <option value="MANUAL">Manual</option>
          <option value="MRP">Dari MRP</option>
        </FilterSelect>
      </FilterBar>
      <DataTable
        caption="Daftar purchase request"
        columns={columns}
        rows={page?.data ?? []}
        getRowKey={(pr) => pr.id}
        loading={loading}
        error={error}
        onRetry={load}
        emptyTitle="Belum ada purchase request"
        emptyDescription={source ? "Tidak ada purchase request untuk sumber yang dipilih." : "Purchase request manual dan MRP akan muncul di sini."}
        minWidth="780px"
        mobileCard={(pr) => (
          <article className="space-y-3 p-4">
            <div className="flex items-start justify-between gap-3"><div><p className="font-mono font-semibold">{pr.doc_no}</p><p className="text-xs text-[var(--color-text-muted)]">{pr.lines_count} lines · dibutuhkan {pr.needed_by ?? "—"}</p></div><StatusBadge status={pr.status} /></div>
            <div className="flex items-center justify-between text-xs"><StatusBadge status={pr.source} /><span className="text-[var(--color-text-muted)]">{new Date(pr.created_at).toLocaleDateString("id-ID")}</span></div>
          </article>
        )}
      />
    </div>
  );
}
