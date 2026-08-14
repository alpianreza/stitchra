"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

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

/** Daftar PO + aksi submit untuk approval (BR-015) */
export default function PurchaseOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");
  const [acting, setActing] = useState<number | null>(null);

  function load() {
    api.get<Page>(`/purchasing/pos${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message));
  }

  useEffect(load, [status]);

  async function submit(id: number) {
    setActing(id); setError(null);
    try {
      await api.post(`/purchasing/pos/${id}/submit`, {});
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setActing(null);
    }
  }

  const fmt = (n: string) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(Number(n));

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Purchase Order</h1>
        <div className="flex items-center gap-2">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded border px-2 py-1.5 text-sm">
            <option value="">Semua status</option>
            {["DRAFT", "SUBMITTED", "APPROVED", "PARTIAL_RECEIVED", "RECEIVED", "CLOSED"].map((s) => <option key={s}>{s}</option>)}
          </select>
          <Link href="/purchasing/pos/new" className="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white">+ Buat PO</Link>
        </div>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. PO</th>
              <th className="px-3 py-2 font-medium">Supplier</th>
              <th className="px-3 py-2 font-medium">Tgl Order</th>
              <th className="px-3 py-2 font-medium">Ekspektasi</th>
              <th className="px-3 py-2 text-right font-medium">Total</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2 font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {(page?.data ?? []).map((po) => (
              <tr key={po.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono">{po.doc_no}</td>
                <td className="px-3 py-2">{po.supplier?.name ?? "—"}</td>
                <td className="px-3 py-2">{po.order_date}</td>
                <td className="px-3 py-2">{po.expected_date ?? "—"}</td>
                <td className="px-3 py-2 text-right">{fmt(po.total_amount)}</td>
                <td className="px-3 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{po.status}</span></td>
                <td className="px-3 py-2">
                  {po.status === "DRAFT" && (
                    <button onClick={() => submit(po.id)} disabled={acting === po.id} className="rounded bg-blue-600 px-2 py-1 text-xs font-medium text-white disabled:opacity-50">
                      Submit
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {page && page.data.length === 0 && (
              <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">Belum ada PO.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
