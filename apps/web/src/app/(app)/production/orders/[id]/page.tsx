"use client";

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api } from "@/lib/api";

interface MoDetail {
  id: number;
  doc_no: string;
  status: string;
  qty_planned: string;
  qty_produced: string;
  planned_start: string | null;
  planned_end: string | null;
  style?: { style_no: string };
  sales_order?: { doc_no: string; customer?: { name: string } };
  line?: { name: string } | null;
  bom_version?: { version_no: number; status: string; lines: { material?: { code: string; name: string }; qty_per_pcs: string; wastage_pct: string }[] };
  routing_version?: { version_no: number; total_sam: string; operations: { seq: number; smv: string; operation?: { name: string } }[] };
  material_allocations?: { material_id: number; qty_required: string; qty_reserved: string; qty_issued: string; is_backflush: boolean; material?: { code: string; name: string } }[];
}

interface Warehouse { id: number; code: string; name: string; type: string }

/** Detail MO: snapshot BOM/Routing, alokasi material, aksi release/unrelease (BR-060) */
export default function MoDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();

  const [mo, setMo] = useState<MoDetail | null>(null);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    api.get<MoDetail>(`/production/orders/${id}`).then(setMo).catch((e) => setError(e.message));
  }

  useEffect(() => {
    load();
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100")
      .then((r) => setWarehouses(r.data.filter((w) => w.type === "RM")))
      .catch(() => {});
  }, [id]);

  async function release() {
    if (!warehouseId) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/production/orders/${id}/release`, { warehouse_id: Number(warehouseId) });
      load();
    } catch (e: any) {
      setError(e.message);   // shortage → pesan BR-040 dari server
    } finally {
      setBusy(false);
    }
  }

  async function unrelease() {
    if (!window.confirm("Lepaskan semua reservasi MO ini?")) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/production/orders/${id}/unrelease`, {});
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !mo) return <p className="text-red-600">{error}</p>;
  if (!mo) return <p className="text-slate-500">Memuat…</p>;

  const fmt = (v: string | number) => Number(v).toLocaleString("id-ID", { maximumFractionDigits: 4 });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold font-mono">{mo.doc_no}</h1>
          <p className="text-sm text-slate-500">
            {mo.style?.style_no} · SO {mo.sales_order?.doc_no} · {mo.sales_order?.customer?.name}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <span className="rounded-full bg-slate-900 px-3 py-1 text-sm font-medium text-white">{mo.status}</span>
          <button onClick={() => router.back()} className="rounded border px-3 py-1.5 text-sm">← Kembali</button>
        </div>
      </div>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}

      {/* Aksi release (BR-060) */}
      {(mo.status === "PLANNED" || mo.status === "RELEASED") && (
        <section className="flex items-end gap-3 rounded-xl border bg-white p-4">
          {mo.status === "PLANNED" ? (
            <>
              <label className="text-sm">
                <span className="mb-1 block font-medium">Gudang sumber (RM) *</span>
                <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} className="rounded border px-2 py-1.5 text-sm">
                  <option value="">— pilih gudang —</option>
                  {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
                </select>
              </label>
              <button onClick={release} disabled={!warehouseId || busy} className="rounded bg-green-700 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                {busy ? "Memproses…" : "Release (hard reservation)"}
              </button>
            </>
          ) : (
            <button onClick={unrelease} disabled={busy} className="rounded border border-amber-400 px-4 py-1.5 text-sm font-medium text-amber-700 disabled:opacity-50">
              Unrelease (lepas reservasi)
            </button>
          )}
        </section>
      )}

      <div className="grid gap-4 md:grid-cols-2">
        {/* BOM snapshot (BR-030) */}
        <section className="rounded-xl border bg-white p-4">
          <h2 className="mb-2 font-semibold">BOM v{mo.bom_version?.version_no} (snapshot)</h2>
          <table className="w-full text-sm">
            <thead className="border-b text-left text-xs text-slate-500">
              <tr><th className="py-1">Material</th><th className="py-1 text-right">Qty/pcs</th><th className="py-1 text-right">Wastage%</th></tr>
            </thead>
            <tbody>
              {(mo.bom_version?.lines ?? []).map((l, i) => (
                <tr key={i} className="border-b last:border-0">
                  <td className="py-1.5"><span className="font-mono">{l.material?.code}</span> {l.material?.name}</td>
                  <td className="py-1.5 text-right">{fmt(l.qty_per_pcs)}</td>
                  <td className="py-1.5 text-right">{fmt(l.wastage_pct)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>

        {/* Routing snapshot */}
        <section className="rounded-xl border bg-white p-4">
          <h2 className="mb-2 font-semibold">Routing v{mo.routing_version?.version_no} — total SAM {fmt(mo.routing_version?.total_sam ?? 0)}</h2>
          <table className="w-full text-sm">
            <thead className="border-b text-left text-xs text-slate-500">
              <tr><th className="py-1">Seq</th><th className="py-1">Operasi</th><th className="py-1 text-right">SMV</th></tr>
            </thead>
            <tbody>
              {(mo.routing_version?.operations ?? []).map((op) => (
                <tr key={op.seq} className="border-b last:border-0">
                  <td className="py-1.5">{op.seq}</td>
                  <td className="py-1.5">{op.operation?.name ?? "—"}</td>
                  <td className="py-1.5 text-right">{fmt(op.smv)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      </div>

      {/* Alokasi material (BR-060) */}
      <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-2 font-semibold">Alokasi Material</h2>
        <table className="w-full text-sm">
          <thead className="border-b text-left text-xs text-slate-500">
            <tr>
              <th className="py-1">Material</th>
              <th className="py-1 text-right">Dibutuhkan</th>
              <th className="py-1 text-right">Reserved</th>
              <th className="py-1 text-right">Issued</th>
              <th className="py-1">Mode</th>
            </tr>
          </thead>
          <tbody>
            {(mo.material_allocations ?? []).map((a) => (
              <tr key={a.material_id} className="border-b last:border-0">
                <td className="py-1.5"><span className="font-mono">{a.material?.code}</span> {a.material?.name}</td>
                <td className="py-1.5 text-right">{fmt(a.qty_required)}</td>
                <td className="py-1.5 text-right">{fmt(a.qty_reserved)}</td>
                <td className="py-1.5 text-right">{fmt(a.qty_issued)}</td>
                <td className="py-1.5">{a.is_backflush ? <span className="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-700">Backflush</span> : <span className="text-xs text-slate-500">Aktual</span>}</td>
              </tr>
            ))}
            {(mo.material_allocations ?? []).length === 0 && (
              <tr><td colSpan={5} className="py-4 text-center text-sm text-slate-500">Belum ada alokasi — release MO dulu.</td></tr>
            )}
          </tbody>
        </table>
      </section>
    </div>
  );
}
