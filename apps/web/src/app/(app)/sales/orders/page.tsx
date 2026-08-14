"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface So {
  id: number;
  doc_no: string;
  status: string;
  order_date: string;
  ex_factory_date: string | null;
  customer?: { name: string };
  lines_count?: number;
}

interface Page { data: So[]; total: number }

const STATUS_BADGE: Record<string, string> = {
  DRAFT: "bg-slate-100 text-slate-700",
  SUBMITTED: "bg-blue-100 text-blue-700",
  APPROVED: "bg-indigo-100 text-indigo-700",
  CONFIRMED: "bg-green-100 text-green-700",
  IN_PROGRESS: "bg-amber-100 text-amber-700",
  CLOSED: "bg-slate-200 text-slate-600",
  CANCELLED: "bg-red-100 text-red-700",
  REJECTED: "bg-red-100 text-red-700",
};

export default function SalesOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");

  useEffect(() => {
    api.get<Page>(`/sales/orders${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message));
  }, [status]);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Sales Order</h1>
        <div className="flex items-center gap-2">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded border px-2 py-1.5 text-sm">
            <option value="">Semua status</option>
            {["DRAFT", "SUBMITTED", "APPROVED", "CONFIRMED", "IN_PROGRESS", "CLOSED"].map((s) => <option key={s}>{s}</option>)}
          </select>
          <Link href="/sales/orders/new" className="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white">
            + Buat SO
          </Link>
        </div>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. SO</th>
              <th className="px-3 py-2 font-medium">Customer</th>
              <th className="px-3 py-2 font-medium">Tanggal Order</th>
              <th className="px-3 py-2 font-medium">Ex-Factory</th>
              <th className="px-3 py-2 font-medium">Lines</th>
              <th className="px-3 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {(page?.data ?? []).map((so) => (
              <tr key={so.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono">{so.doc_no}</td>
                <td className="px-3 py-2">{so.customer?.name ?? "—"}</td>
                <td className="px-3 py-2">{so.order_date}</td>
                <td className="px-3 py-2">{so.ex_factory_date ?? "—"}</td>
                <td className="px-3 py-2">{so.lines_count ?? "—"}</td>
                <td className="px-3 py-2">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[so.status] ?? "bg-slate-100"}`}>
                    {so.status}
                  </span>
                </td>
              </tr>
            ))}
            {page && page.data.length === 0 && (
              <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada sales order.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
