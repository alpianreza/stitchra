"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface PendingItem {
  id: number;
  doc_type: string;
  doc_id: number;
  current_step: number;
  submitted_at: string;
  step_role: string;
  submitted_by_name: string | null;
}

export default function ApprovalsPage() {
  const [items, setItems] = useState<PendingItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [acting, setActing] = useState<number | null>(null);

  function load() {
    api.get<{ data: PendingItem[] }>("/approvals/pending")
      .then((r) => setItems(r.data))
      .catch((e) => setError(e.message));
  }

  useEffect(load, []);

  async function act(id: number, action: "approve" | "reject" | "revision") {
    const note = window.prompt(
      action === "approve" ? "Catatan (opsional):" : "Alasan (wajib):",
    );
    if (action !== "approve" && !note) return;

    setActing(id);
    setError(null);
    try {
      await api.post(`/approvals/${id}/${action}`, { note: note || undefined });
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setActing(null);
    }
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Approval Menunggu Saya</h1>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">Dokumen</th>
              <th className="px-3 py-2 font-medium">Doc ID</th>
              <th className="px-3 py-2 font-medium">Step</th>
              <th className="px-3 py-2 font-medium">Role Step</th>
              <th className="px-3 py-2 font-medium">Diajukan oleh</th>
              <th className="px-3 py-2 font-medium">Waktu</th>
              <th className="px-3 py-2 font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {items.map((it) => (
              <tr key={it.id} className="border-b last:border-0">
                <td className="px-3 py-2 font-mono font-medium">{it.doc_type}</td>
                <td className="px-3 py-2 font-mono">#{it.doc_id}</td>
                <td className="px-3 py-2">{it.current_step}</td>
                <td className="px-3 py-2">{it.step_role}</td>
                <td className="px-3 py-2">{it.submitted_by_name ?? "—"}</td>
                <td className="px-3 py-2">{new Date(it.submitted_at).toLocaleString("id-ID")}</td>
                <td className="px-3 py-2">
                  <div className="flex gap-1">
                    <button
                      onClick={() => act(it.id, "approve")}
                      disabled={acting === it.id}
                      className="rounded bg-green-600 px-2 py-1 text-xs font-medium text-white disabled:opacity-50"
                    >Approve</button>
                    <button
                      onClick={() => act(it.id, "revision")}
                      disabled={acting === it.id}
                      className="rounded bg-amber-500 px-2 py-1 text-xs font-medium text-white disabled:opacity-50"
                    >Revisi</button>
                    <button
                      onClick={() => act(it.id, "reject")}
                      disabled={acting === it.id}
                      className="rounded bg-red-600 px-2 py-1 text-xs font-medium text-white disabled:opacity-50"
                    >Reject</button>
                  </div>
                </td>
              </tr>
            ))}
            {items.length === 0 && (
              <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">Tidak ada approval yang menunggu.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
