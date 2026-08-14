"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Row {
  material_code: string | null;
  material_name: string | null;
  warehouse_code: string | null;
  lot_no: string | null;
  roll_id: number | null;
  ownership: string;
  on_hand: number;
  reserved: number;
  quality_hold: number;
  available: number;
  avg_cost: number | null;
}

interface Warehouse { id: number; code: string; name: string }

/** Inquiry stok — on_hand / reserved / quality_hold / available (BR-006) */
export default function StockInquiryPage() {
  const [rows, setRows] = useState<Row[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100").then((r) => setWarehouses(r.data)).catch(() => {});
  }, []);

  useEffect(() => {
    api.get<{ data: Row[] }>(`/inventory/stock${warehouseId ? `?warehouse_id=${warehouseId}` : ""}`)
      .then((r) => setRows(r.data))
      .catch((e) => setError(e.message));
  }, [warehouseId]);

  const fmt = (n: number | null) => n === null ? "—" : Number(n).toLocaleString("id-ID", { maximumFractionDigits: 4 });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Inquiry Stok</h1>
        <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} className="rounded border px-2 py-1.5 text-sm">
          <option value="">Semua gudang</option>
          {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
        </select>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">Material</th>
              <th className="px-3 py-2 font-medium">Gudang</th>
              <th className="px-3 py-2 font-medium">Lot / Roll</th>
              <th className="px-3 py-2 font-medium">Ownership</th>
              <th className="px-3 py-2 text-right font-medium">On Hand</th>
              <th className="px-3 py-2 text-right font-medium">Reserved</th>
              <th className="px-3 py-2 text-right font-medium">Quality Hold</th>
              <th className="px-3 py-2 text-right font-medium">Available</th>
              <th className="px-3 py-2 text-right font-medium">Avg Cost</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={i} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2"><span className="font-mono">{r.material_code}</span> {r.material_name}</td>
                <td className="px-3 py-2">{r.warehouse_code}</td>
                <td className="px-3 py-2">{r.roll_id ? `Roll #${r.roll_id}` : (r.lot_no ?? "—")}</td>
                <td className="px-3 py-2">{r.ownership}</td>
                <td className="px-3 py-2 text-right">{fmt(r.on_hand)}</td>
                <td className="px-3 py-2 text-right text-amber-700">{fmt(r.reserved)}</td>
                <td className="px-3 py-2 text-right text-slate-500">{fmt(r.quality_hold)}</td>
                <td className="px-3 py-2 text-right font-bold">{fmt(r.available)}</td>
                <td className="px-3 py-2 text-right">{fmt(r.avg_cost)}</td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={9} className="px-3 py-6 text-center text-slate-500">Belum ada saldo stok.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  );
}
