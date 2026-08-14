"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

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

  useEffect(() => {
    api.get<Page>(`/purchasing/prs${source ? `?source=${source}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message));
  }, [source]);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Purchase Request</h1>
        <select value={source} onChange={(e) => setSource(e.target.value)} className="rounded border px-2 py-1.5 text-sm">
          <option value="">Semua sumber</option>
          <option value="MANUAL">Manual</option>
          <option value="MRP">Dari MRP</option>
        </select>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. PR</th>
              <th className="px-3 py-2 font-medium">Sumber</th>
              <th className="px-3 py-2 font-medium">Dibutuhkan</th>
              <th className="px-3 py-2 font-medium">Lines</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2 font-medium">Dibuat</th>
            </tr>
          </thead>
          <tbody>
            {(page?.data ?? []).map((pr) => (
              <tr key={pr.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono">{pr.doc_no}</td>
                <td className="px-3 py-2">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${pr.source === "MRP" ? "bg-blue-100 text-blue-700" : "bg-slate-100"}`}>
                    {pr.source}
                  </span>
                </td>
                <td className="px-3 py-2">{pr.needed_by ?? "—"}</td>
                <td className="px-3 py-2">{pr.lines_count}</td>
                <td className="px-3 py-2">{pr.status}</td>
                <td className="px-3 py-2">{new Date(pr.created_at).toLocaleString("id-ID")}</td>
              </tr>
            ))}
            {page && page.data.length === 0 && (
              <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada PR.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
