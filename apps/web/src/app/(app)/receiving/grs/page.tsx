"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { DataTable, PageHeader, StatusBadge, type DataTableColumn } from "@/components/ui";

interface Gr { id: number; doc_no: string; status: string; received_date: string; delivery_note_no: string | null; purchase_order?: { doc_no: string; supplier?: { name: string } } }
interface Page { data: Gr[]; total: number }

export default function GoodsReceiptsPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true); setError(null);
    api.get<Page>("/receiving/grs?per_page=100").then(setPage).catch((requestError) => setError(requestError.message)).finally(() => setLoading(false));
  }, []);
  useEffect(load, [load]);

  const columns: DataTableColumn<Gr>[] = [
    { key: "document", header: "No. GR", cell: (gr) => <span className="font-mono font-semibold">{gr.doc_no}</span> },
    { key: "po", header: "PO", cell: (gr) => <span className="font-mono">{gr.purchase_order?.doc_no ?? "—"}</span> },
    { key: "supplier", header: "Supplier", cell: (gr) => gr.purchase_order?.supplier?.name ?? "—" },
    { key: "received", header: "Tgl Terima", cell: (gr) => gr.received_date },
    { key: "delivery", header: "Surat Jalan", cell: (gr) => gr.delivery_note_no ?? "—" },
    { key: "status", header: "Status", cell: (gr) => <StatusBadge status={gr.status} /> },
    { key: "action", header: "Aksi", align: "right", cell: (gr) => gr.status === "POSTED" ? <Link href={`/receiving/inspections?gr=${gr.id}`} className="inline-flex min-h-8 items-center rounded-[var(--radius-control)] bg-[var(--color-warning)] px-2.5 text-xs font-medium text-white">Inspeksi FQC</Link> : "—" },
  ];

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Receiving" title="Goods Receipt" description="Pantau penerimaan barang dan antrean inward quality control." actions={<Link href="/receiving/grs/new" className="inline-flex min-h-9 items-center rounded-[var(--radius-control)] bg-[var(--color-primary)] px-3 text-sm font-medium text-white hover:bg-[var(--color-primary-hover)]">Terima Barang</Link>} />
      <DataTable caption="Daftar goods receipt" columns={columns} rows={page?.data ?? []} getRowKey={(gr) => gr.id} loading={loading} error={error} onRetry={load} emptyTitle="Belum ada goods receipt" emptyDescription="Penerimaan dari purchase order akan muncul di sini." minWidth="900px" mobileCard={(gr) => (
        <article className="space-y-3 p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-mono font-semibold">{gr.doc_no}</p><p className="text-xs text-[var(--color-text-muted)]">PO {gr.purchase_order?.doc_no ?? "—"}</p></div><StatusBadge status={gr.status} /></div><p className="text-sm font-medium">{gr.purchase_order?.supplier?.name ?? "—"}</p><div className="flex items-center justify-between text-xs text-[var(--color-text-muted)]"><span>Diterima {gr.received_date}</span><span>SJ {gr.delivery_note_no ?? "—"}</span></div>{gr.status === "POSTED" && <Link href={`/receiving/inspections?gr=${gr.id}`} className="inline-flex min-h-8 w-full items-center justify-center rounded-[var(--radius-control)] bg-[var(--color-warning)] px-2.5 text-xs font-medium text-white">Mulai Inspeksi FQC</Link>}</article>
      )} />
    </div>
  );
}
