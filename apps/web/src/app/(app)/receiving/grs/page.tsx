"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Gr {
  id: number;
  doc_no: string;
  status: string;
  received_date: string;
  delivery_note_no: string | null;
  purchase_order?: { doc_no: string; supplier?: { name: string } };
}

interface Page { data: Gr[]; total: number }

/** Daftar Goods Receipt — GR POSTED menunggu inward QC untuk release hold (BR-004) */
export default function GoodsReceiptsPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.get<Page>("/receiving/grs?per_page=100").then(setPage).catch((e) => setError(e.message));
  }, []);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Goods Receipt</h1>
        <Link href="/receiving/grs/new" className="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white">+ Terima Barang</Link>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. GR</th>
              <th className="px-3 py-2 font-medium">PO</th>
              <th className="px-3 py-2 font-medium">Supplier</th>
              <th className="px-3 py-2 font-medium">Tgl Terima</th>
              <th className="px-3 py-2 font-medium">Surat Jalan</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2 font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {(page?.data ?? []).map((gr) => (
              <tr key={gr.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono">{gr.doc_no}</td>
                <td className="px-3 py-2 font-mono">{gr.purchase_order?.doc_no}</td>
                <td className="px-3 py-2">{gr.purchase_order?.supplier?.name}</td>
                <td className="px-3 py-2">{gr.received_date}</td>
                <td className="px-3 py-2">{gr.delivery_note_no ?? "—"}</td>
                <td className="px-3 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{gr.status}</span></td>
                <td className="px-3 py-2">
                  {gr.status === "POSTED" && (
                    <Link href={`/receiving/inspections?gr=${gr.id}`} className="rounded bg-amber-600 px-2 py-1 text-xs font-medium text-white">
                      Inspeksi (FQC)
                    </Link>
                  )}
                </td>
              </tr>
            ))}
            {page && page.data.length === 0 && (
              <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">Belum ada GR.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
