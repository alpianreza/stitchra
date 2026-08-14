"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Pl { id: number; doc_no: string; sales_order?: { doc_no: string } }
interface Shipment {
  id: number; doc_no: string; status: string; ship_date: string;
  tolerance_check: string; over_tolerance_approved: boolean;
  forwarder: string | null; container_no: string | null;
  sales_order?: { doc_no: string; customer?: { name: string } };
}
interface Warehouse { id: number; code: string; name: string; type: string }

/** Shipment — cek toleransi buyer (BR-021); ship menurunkan FG via ITS */
export default function ShipmentsPage() {
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [approvedPls, setApprovedPls] = useState<Pl[]>([]);
  const [plId, setPlId] = useState("");
  const [shipDate, setShipDate] = useState(new Date().toISOString().slice(0, 10));
  const [forwarder, setForwarder] = useState("");
  const [containerNo, setContainerNo] = useState("");
  const [fgWarehouses, setFgWarehouses] = useState<Warehouse[]>([]);
  const [fgWarehouseId, setFgWarehouseId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    api.get<{ data: Shipment[] }>("/shipping/shipments?per_page=100").then((r) => setShipments(r.data)).catch((e) => setError(e.message));
    api.get<{ data: Pl[] }>("/packing/lists?status=APPROVED&per_page=100").then((r) => setApprovedPls(r.data)).catch(() => {});
  }

  useEffect(() => {
    load();
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100")
      .then((r) => setFgWarehouses(r.data.filter((w) => w.type === "FG")))
      .catch(() => {});
  }, []);

  async function createShipment() {
    if (!plId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const s = await api.post<Shipment>(`/shipping/shipments/from-pl/${plId}`, {
        ship_date: shipDate, forwarder: forwarder || undefined, container_no: containerNo || undefined,
      });
      setMessage(`Shipment ${s.doc_no} dibuat — tolerance check: ${s.tolerance_check}.`);
      setPlId(""); setForwarder(""); setContainerNo("");
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function approveTolerance(id: number) {
    if (!window.confirm("Approve shipment di luar toleransi buyer? (tercatat audit)")) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/shipping/shipments/${id}/approve-over-tolerance`, {});
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function ship(id: number) {
    if (!fgWarehouseId) { setError("Pilih gudang FG dulu (dropdown di atas tabel)."); return; }
    setBusy(true); setError(null); setMessage(null);
    try {
      const s = await api.post<Shipment>(`/shipping/shipments/${id}/ship`, { fg_warehouse_id: Number(fgWarehouseId) });
      setMessage(`Shipment ${s.doc_no} SHIPPED — FG keluar dari gudang.`);
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  const toleranceBadge = (s: Shipment) => {
    if (s.tolerance_check === "OK") return <span className="rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">OK</span>;
    if (s.tolerance_check === "PENDING") return <span className="rounded bg-slate-100 px-2 py-0.5 text-xs">PENDING</span>;
    const color = s.over_tolerance_approved ? "bg-amber-100 text-amber-700" : "bg-red-100 text-red-700";
    return <span className={`rounded px-2 py-0.5 text-xs font-medium ${color}`}>{s.tolerance_check}{s.over_tolerance_approved ? " ✓approved" : ""}</span>;
  };

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Shipment</h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <section className="grid grid-cols-2 gap-3 rounded-xl border bg-white p-4 md:grid-cols-5">
        <label className="text-sm">
          <span className="mb-1 block font-medium">Packing List (APPROVED) *</span>
          <select value={plId} onChange={(e) => setPlId(e.target.value)} className="w-full rounded border px-2 py-1.5 text-sm">
            <option value="">— pilih PL —</option>
            {approvedPls.map((p) => <option key={p.id} value={p.id}>{p.doc_no} (SO {p.sales_order?.doc_no})</option>)}
          </select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Tgl Kirim *</span>
          <input type="date" value={shipDate} onChange={(e) => setShipDate(e.target.value)} className="w-full rounded border px-2 py-1.5 text-sm" />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Forwarder</span>
          <input value={forwarder} onChange={(e) => setForwarder(e.target.value)} className="w-full rounded border px-2 py-1.5 text-sm" />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">No. Kontainer</span>
          <input value={containerNo} onChange={(e) => setContainerNo(e.target.value)} className="w-full rounded border px-2 py-1.5 text-sm" />
        </label>
        <div className="flex items-end">
          <button onClick={createShipment} disabled={!plId || busy} className="w-full rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
            Buat Shipment
          </button>
        </div>
      </section>

      <section className="rounded-xl border bg-white">
        <div className="flex items-center gap-3 border-b p-3">
          <span className="text-sm font-medium">Gudang FG untuk ship:</span>
          <select value={fgWarehouseId} onChange={(e) => setFgWarehouseId(e.target.value)} className="rounded border px-2 py-1 text-sm">
            <option value="">— pilih —</option>
            {fgWarehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
          </select>
        </div>
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">No. Shipment</th>
              <th className="px-3 py-2 font-medium">SO</th>
              <th className="px-3 py-2 font-medium">Customer</th>
              <th className="px-3 py-2 font-medium">Tgl Kirim</th>
              <th className="px-3 py-2 font-medium">Toleransi</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2 font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {shipments.map((s) => (
              <tr key={s.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono">{s.doc_no}</td>
                <td className="px-3 py-2 font-mono">{s.sales_order?.doc_no}</td>
                <td className="px-3 py-2">{s.sales_order?.customer?.name}</td>
                <td className="px-3 py-2">{s.ship_date}</td>
                <td className="px-3 py-2">{toleranceBadge(s)}</td>
                <td className="px-3 py-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium">{s.status}</span></td>
                <td className="px-3 py-2">
                  <div className="flex gap-1">
                    {s.tolerance_check !== "OK" && !s.over_tolerance_approved && s.status !== "SHIPPED" && (
                      <button onClick={() => approveTolerance(s.id)} disabled={busy} className="rounded bg-amber-500 px-2 py-1 text-xs font-medium text-white disabled:opacity-50">
                        Approve Toleransi
                      </button>
                    )}
                    {s.status !== "SHIPPED" && (
                      <button onClick={() => ship(s.id)} disabled={busy} className="rounded bg-green-700 px-2 py-1 text-xs font-medium text-white disabled:opacity-50">
                        Ship
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
            {shipments.length === 0 && <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">Belum ada shipment.</td></tr>}
          </tbody>
        </table>
      </section>
    </div>
  );
}
