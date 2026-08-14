"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

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

export default function ProductionOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");

  useEffect(() => {
    api.get<Page>(`/production/orders${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message));
  }, [status]);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Manufacturing Order</h1>
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded border px-2 py-1.5 text-sm">
          <option value="">Semua status</option>
          {["PLANNED", "RELEASED", "CUTTING", "SEWING", "FINISHING", "QC", "PACKED", "CLOSED"].map((s) => <option key={s}>{s}</option>)}
        </select>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. MO</th>
              <th className="px-3 py-2 font-medium">SO</th>
              <th className="px-3 py-2 font-medium">Style</th>
              <th className="px-3 py-2 font-medium">Line</th>
              <th className="px-3 py-2 font-medium text-right">Qty Plan</th>
              <th className="px-3 py-2 font-medium text-right">Qty Produced</th>
              <th className="px-3 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {(page?.data ?? []).map((mo) => (
              <tr key={mo.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono">{mo.doc_no}</td>
                <td className="px-3 py-2 font-mono">{mo.sales_order?.doc_no ?? "—"}</td>
                <td className="px-3 py-2">{mo.style?.style_no ?? "—"}</td>
                <td className="px-3 py-2">{mo.line?.name ?? "—"}</td>
                <td className="px-3 py-2 text-right">{Number(mo.qty_planned).toLocaleString("id-ID")}</td>
                <td className="px-3 py-2 text-right">{Number(mo.qty_produced).toLocaleString("id-ID")}</td>
                <td className="px-3 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{mo.status}</span></td>
              </tr>
            ))}
            {page && page.data.length === 0 && (
              <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">Belum ada MO.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
