"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import {
  DataTable,
  FilterBar,
  FilterSelect,
  PageHeader,
  StatusBadge,
  type DataTableColumn,
} from "@/components/ui";

interface Mo {
  id: number;
  doc_no: string;
  status: string;
  qty_planned: string;
  qty_produced: string;
  style?: { style_no: string };
  sales_order?: { doc_no: string };
  line?: { name: string } | null;
}

interface Page { data: Mo[]; total: number }

const STATUSES = ["PLANNED", "RELEASED", "CUTTING", "SEWING", "FINISHING", "QC", "PACKED", "CLOSED"];
const formatQuantity = (value: string) => Number(value).toLocaleString("id-ID", { maximumFractionDigits: 2 });

export default function ProductionOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get<Page>(`/production/orders${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(load, [load]);

  const columns: DataTableColumn<Mo>[] = [
    {
      key: "doc_no",
      header: "No. MO",
      cell: (mo) => (
        <Link href={`/production/orders/${mo.id}`} className="font-mono font-semibold text-[var(--color-primary)] hover:underline">
          {mo.doc_no}
        </Link>
      ),
    },
    { key: "so", header: "SO", cell: (mo) => <span className="font-mono">{mo.sales_order?.doc_no ?? "—"}</span> },
    { key: "style", header: "Style", cell: (mo) => mo.style?.style_no ?? "—" },
    { key: "line", header: "Line", cell: (mo) => mo.line?.name ?? "—" },
    { key: "planned", header: "Qty Plan", align: "right", className: "tabular-nums", cell: (mo) => formatQuantity(mo.qty_planned) },
    {
      key: "produced",
      header: "qty_produced (Legacy)",
      align: "right",
      className: "tabular-nums",
      cell: (mo) => <span title="Legacy compatibility fallback — not authoritative" className="text-amber-700">{formatQuantity(mo.qty_produced)}</span>,
    },
    { key: "authority", header: "Output Authority", cell: () => <span className="rounded bg-slate-200 px-2 py-1 text-xs text-slate-700">NOT DEFINED</span> },
    { key: "status", header: "Status", cell: (mo) => <StatusBadge status={mo.status} /> },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Manufacturing"
        title="Manufacturing Order"
        description="Pantau rencana dan tahap produksi. qty_produced ditampilkan hanya sebagai legacy compatibility fallback, bukan output authority."
      />

      <FilterBar summary={page ? `${page.total.toLocaleString("id-ID")} manufacturing order` : undefined}>
        <FilterSelect label="Filter status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">Semua status</option>
          {STATUSES.map((item) => <option key={item} value={item}>{item.replaceAll("_", " ")}</option>)}
        </FilterSelect>
      </FilterBar>

      <DataTable
        caption="Daftar manufacturing order"
        columns={columns}
        rows={page?.data ?? []}
        getRowKey={(mo) => mo.id}
        loading={loading}
        error={error}
        onRetry={load}
        emptyTitle="Belum ada manufacturing order"
        emptyDescription={status ? "Tidak ada MO dengan status yang dipilih." : "Manufacturing order akan tampil setelah dibuat dari workflow produksi."}
        minWidth="1080px"
        mobileCard={(mo) => (
          <Link href={`/production/orders/${mo.id}`} className="block space-y-3 p-4 hover:bg-[var(--color-surface-subtle)]">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="font-mono font-semibold text-[var(--color-primary)]">{mo.doc_no}</p>
                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">{mo.style?.style_no ?? "Tanpa style"} · {mo.line?.name ?? "Belum ada line"}</p>
              </div>
              <StatusBadge status={mo.status} />
            </div>
            <div className="grid grid-cols-2 gap-2 text-xs tabular-nums">
              <div className="rounded bg-slate-50 p-2"><p className="text-slate-500">Qty plan</p><p className="font-semibold">{formatQuantity(mo.qty_planned)}</p></div>
              <div className="rounded bg-amber-50 p-2"><p className="text-amber-700">qty_produced (legacy)</p><p className="font-semibold text-amber-800">{formatQuantity(mo.qty_produced)}</p></div>
            </div>
            <p className="text-xs font-medium text-slate-600">⚪ Production Output Authority — NOT DEFINED</p>
          </Link>
        )}
      />
    </div>
  );
}
