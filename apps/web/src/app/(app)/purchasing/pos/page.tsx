"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, DataTable, FilterBar, FilterSelect, PageHeader, StatusBadge, type DataTableColumn } from "@/components/ui";

interface Po {
  id: number;
  doc_no: string;
  status: string;
  order_date: string;
  expected_date: string | null;
  total_amount: string;
  supplier?: { name: string };
}
interface Page { data: Po[]; total: number }

export default function PurchaseOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");
  const [acting, setActing] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true); setError(null);
    api.get<Page>(`/purchasing/pos${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((requestError) => setError(requestError.message))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(load, [load]);

  async function submit(id: number) {
    setActing(id); setError(null);
    try { await api.post(`/purchasing/pos/${id}/submit`, {}); load(); }
    catch (requestError: any) { setError(requestError.message); }
    finally { setActing(null); }
  }

  const fmt = (value: string) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(Number(value));
  const columns: DataTableColumn<Po>[] = [
    { key: "document", header: "No. PO", cell: (po) => <span className="font-mono font-semibold">{po.doc_no}</span> },
    { key: "supplier", header: "Supplier", cell: (po) => po.supplier?.name ?? "—" },
    { key: "order", header: "Tgl Order", cell: (po) => po.order_date },
    { key: "expected", header: "Ekspektasi", cell: (po) => po.expected_date ?? "—" },
    { key: "total", header: "Total", align: "right", cell: (po) => <span className="font-medium tabular-nums">{fmt(po.total_amount)}</span> },
    { key: "status", header: "Status", cell: (po) => <StatusBadge status={po.status} /> },
    { key: "action", header: "Aksi", align: "right", cell: (po) => po.status === "DRAFT" ? <Button size="sm" variant="primary" loading={acting === po.id} onClick={() => submit(po.id)}>Submit</Button> : "—" },
  ];

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Purchasing" title="Purchase Order" description="Kelola komitmen pembelian dan status penerimaan supplier." actions={<Link href="/purchasing/pos/new" className="inline-flex min-h-9 items-center rounded-[var(--radius-control)] bg-[var(--color-primary)] px-3 text-sm font-medium text-white hover:bg-[var(--color-primary-hover)]">Buat PO</Link>} />
      <FilterBar summary={page ? `${page.total} purchase order` : undefined}>
        <FilterSelect label="Status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">Semua status</option>
          {["DRAFT", "SUBMITTED", "APPROVED", "PARTIAL_RECEIVED", "RECEIVED", "CLOSED"].map((item) => <option key={item}>{item}</option>)}
        </FilterSelect>
      </FilterBar>
      <DataTable caption="Daftar purchase order" columns={columns} rows={page?.data ?? []} getRowKey={(po) => po.id} loading={loading} error={error} onRetry={load} emptyTitle="Belum ada purchase order" emptyDescription="Buat purchase order pertama untuk memulai proses pembelian." minWidth="900px" mobileCard={(po) => (
        <article className="space-y-3 p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-mono font-semibold">{po.doc_no}</p><p className="text-sm">{po.supplier?.name ?? "—"}</p></div><StatusBadge status={po.status} /></div><dl className="grid grid-cols-2 gap-2 text-xs"><div><dt className="text-[var(--color-text-muted)]">Ekspektasi</dt><dd className="font-medium">{po.expected_date ?? "—"}</dd></div><div><dt className="text-[var(--color-text-muted)]">Total</dt><dd className="font-semibold tabular-nums">{fmt(po.total_amount)}</dd></div></dl>{po.status === "DRAFT" && <Button className="w-full" size="sm" variant="primary" loading={acting === po.id} onClick={() => submit(po.id)}>Submit untuk Approval</Button>}</article>
      )} />
    </div>
  );
}
