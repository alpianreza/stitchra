"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, ConfirmDialog } from "@/components/ui";

interface Warehouse { id: number; code: string; name: string }
interface Material { id: number; code: string; name: string }
interface Uom { id: number; code: string }
interface OpnameLine { id: number; material_id: number; system_qty: string; material?: any }
interface Opname { id: number; doc_no: string; status: string; lines: OpnameLine[] }

type Tab = "transfer" | "adjustment" | "opname";

/** Operasi stok: transfer antar gudang, adjustment (BR-017 approval), opname (freeze→count→variance) */
export default function InventoryOpsPage() {
  const [tab, setTab] = useState<Tab>("transfer");
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [uoms, setUoms] = useState<Uom[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [lastTransfer, setLastTransfer] = useState<{ id: number; doc_no: string } | null>(null);
  const [receiveId, setReceiveId] = useState("");
  const [receiveConfirmOpen, setReceiveConfirmOpen] = useState(false);
  const [receiveBusy, setReceiveBusy] = useState(false);

  // Transfer state
  const [trf, setTrf] = useState({ from: "", to: "", notes: "" });
  const [trfLines, setTrfLines] = useState([{ material_id: "", qty: "", uom_id: "" }]);

  // Adjustment state
  const [adjReason, setAdjReason] = useState("");
  const [adjLines, setAdjLines] = useState([{ material_id: "", warehouse_id: "", qty_delta: "", unit_cost: "", uom_id: "" }]);

  // Opname state
  const [opnWarehouseId, setOpnWarehouseId] = useState("");
  const [opname, setOpname] = useState<Opname | null>(null);
  const [counts, setCounts] = useState<Record<number, string>>({});

  useEffect(() => {
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100").then((r) => setWarehouses(r.data)).catch(() => {});
    api.get<{ data: Material[] }>("/master/materials?per_page=500").then((r) => setMaterials(r.data)).catch(() => {});
    api.get<{ data: Uom[] }>("/master/uoms?per_page=100").then((r) => setUoms(r.data)).catch(() => {});
  }, []);

  const effectiveReceiveId = receiveId || (lastTransfer ? String(lastTransfer.id) : "");
  const input = "w-full rounded border px-2 py-1.5 text-sm";

  async function submitTransfer(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null);
    try {
      const t = await api.post<{ id: number; doc_no: string }>("/inventory/transfers", {
        from_warehouse_id: Number(trf.from),
        to_warehouse_id: Number(trf.to),
        notes: trf.notes || undefined,
        lines: trfLines.map((l) => ({ material_id: Number(l.material_id), qty: Number(l.qty), uom_id: Number(l.uom_id) })),
      });
      await api.post(`/inventory/transfers/${t.id}/post`, {});
      setLastTransfer({ id: t.id, doc_no: t.doc_no });
      setMessage(`Transfer ${t.doc_no} diposting — IN_TRANSIT ke gudang tujuan (terima via API receive).`);
      setTrf({ from: "", to: "", notes: "" }); setTrfLines([{ material_id: "", qty: "", uom_id: "" }]);
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function receiveTransfer() {
    if (!effectiveReceiveId) return;
    setReceiveBusy(true); setError(null); setMessage(null);
    try {
      const r = await api.post<{ doc_no?: string }>(`/inventory/transfers/${effectiveReceiveId}/receive`, {});
      setMessage(`Transfer ${r?.doc_no ?? `#${effectiveReceiveId}`} diterima - stok gudang tujuan bertambah.`);
      setLastTransfer(null); setReceiveId(""); setReceiveConfirmOpen(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal menerima transfer");
      setReceiveConfirmOpen(false);
    } finally {
      setReceiveBusy(false);
    }
  }

  async function submitAdjustment(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null);
    try {
      const adj = await api.post<{ id: number; doc_no: string }>("/inventory/adjustments", {
        reason: adjReason,
        lines: adjLines.map((l) => ({
          material_id: Number(l.material_id),
          warehouse_id: Number(l.warehouse_id),
          qty_delta: Number(l.qty_delta),
          unit_cost: l.unit_cost ? Number(l.unit_cost) : undefined,
          uom_id: Number(l.uom_id),
        })),
      });
      await api.post(`/inventory/adjustments/${adj.id}/submit`, {});
      setMessage(`Adjustment ${adj.doc_no} disubmit — stok berubah SETELAH approval (BR-017), cek menu Approval.`);
      setAdjReason(""); setAdjLines([{ material_id: "", warehouse_id: "", qty_delta: "", unit_cost: "", uom_id: "" }]);
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function startOpname(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null);
    try {
      const op = await api.post<Opname>("/inventory/opnames", { warehouse_id: Number(opnWarehouseId) });
      setOpname(op);
      const init: Record<number, string> = {};
      for (const l of op.lines) init[l.id] = String(Number(l.system_qty));
      setCounts(init);
      setMessage(`Opname ${op.doc_no} dibuat — saldo sistem di-freeze. Input hasil hitung fisik.`);
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function submitCounts() {
    if (!opname) return;
    setBusy(true); setError(null);
    try {
      const res = await api.post<Opname>(`/inventory/opnames/${opname.id}/counts`, {
        counts: Object.entries(counts).map(([lineId, qty]) => ({ line_id: Number(lineId), counted_qty: Number(qty) })),
      });
      const varianceLines = res.lines.filter((l: any) => Number(l.variance_qty ?? 0) !== 0).length;
      setMessage(`Opname ${res.doc_no} disubmit untuk approval — ${varianceLines} line bervarians. Efek stok setelah APPROVED.`);
      setOpname(null); setCounts({}); setOpnWarehouseId("");
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Operasi Stok</h1>

      <div className="flex gap-2">
        {(["transfer", "adjustment", "opname"] as Tab[]).map((k) => (
          <button key={k} onClick={() => setTab(k)} className={`rounded px-4 py-2 text-sm font-medium ${tab === k ? "bg-slate-900 text-white" : "border bg-white"}`}>
            {k === "transfer" ? "Transfer" : k === "adjustment" ? "Adjustment" : "Opname"}
          </button>
        ))}
      </div>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      {tab === "transfer" && (
        <>
        <form onSubmit={submitTransfer} className="space-y-3 rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Transfer antar gudang</h2>
          <div className="grid grid-cols-3 gap-3">
            <label className="text-sm"><span className="mb-1 block font-medium">Dari *</span>
              <select value={trf.from} onChange={(e) => setTrf({ ...trf, from: e.target.value })} required className={input}>
                <option value="">—</option>{warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
              </select>
            </label>
            <label className="text-sm"><span className="mb-1 block font-medium">Ke *</span>
              <select value={trf.to} onChange={(e) => setTrf({ ...trf, to: e.target.value })} required className={input}>
                <option value="">—</option>{warehouses.map((w) => <option key={w.id} value={w.id} disabled={String(w.id) === trf.from}>{w.code} — {w.name}</option>)}
              </select>
            </label>
            <label className="text-sm"><span className="mb-1 block font-medium">Catatan</span>
              <input value={trf.notes} onChange={(e) => setTrf({ ...trf, notes: e.target.value })} className={input} />
            </label>
          </div>
          {trfLines.map((l, i) => (
            <div key={i} className="grid grid-cols-4 items-end gap-2 rounded-lg border bg-slate-50 p-3">
              <label className="col-span-2 text-xs"><span className="mb-1 block font-medium">Material</span>
                <select value={l.material_id} onChange={(e) => { const n = [...trfLines]; n[i].material_id = e.target.value; setTrfLines(n); }} required className={input}>
                  <option value="">—</option>{materials.map((m) => <option key={m.id} value={m.id}>{m.code} — {m.name}</option>)}
                </select>
              </label>
              <label className="text-xs"><span className="mb-1 block font-medium">Qty</span>
                <input type="number" step="any" min="0.0001" value={l.qty} onChange={(e) => { const n = [...trfLines]; n[i].qty = e.target.value; setTrfLines(n); }} required className={input} />
              </label>
              <label className="text-xs"><span className="mb-1 block font-medium">UOM</span>
                <select value={l.uom_id} onChange={(e) => { const n = [...trfLines]; n[i].uom_id = e.target.value; setTrfLines(n); }} required className={input}>
                  <option value="">—</option>{uoms.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
                </select>
              </label>
            </div>
          ))}
          <div className="flex gap-2">
            <button type="button" onClick={() => setTrfLines([...trfLines, { material_id: "", qty: "", uom_id: "" }])} className="rounded border px-3 py-1.5 text-sm">+ Baris</button>
            <button disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">{busy ? "…" : "Buat + Posting Transfer"}</button>
          </div>
        </form>

        <div className="space-y-2 rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Terima transfer (IN_TRANSIT)</h2>
          <p className="text-xs text-slate-500">Transfer yang sudah diposting berstatus IN_TRANSIT. Terima di gudang tujuan agar stok gudang tujuan bertambah.</p>
          <div className="flex flex-wrap items-end gap-2">
            {lastTransfer && (
              <div className="rounded-lg border bg-slate-50 px-3 py-2 text-sm">
                Transfer terakhir: <span className="font-mono font-medium">{lastTransfer.doc_no}</span> (#{lastTransfer.id})
              </div>
            )}
            <label className="text-sm">
              <span className="mb-1 block font-medium">ID Transfer</span>
              <input value={receiveId} onChange={(e) => setReceiveId(e.target.value.replace(/\D/g, ""))} placeholder={lastTransfer ? String(lastTransfer.id) : "mis. 12"} className={`${input} w-36`} />
            </label>
            <Button size="sm" variant="success" disabled={!effectiveReceiveId} onClick={() => setReceiveConfirmOpen(true)}>Terima di Gudang Tujuan</Button>
          </div>
          <ConfirmDialog
            open={receiveConfirmOpen}
            title="Terima transfer?"
            description={`Stok gudang tujuan akan bertambah untuk transfer #${effectiveReceiveId}. Pastikan barang sudah tiba.`}
            confirmLabel="Terima"
            variant="success"
            loading={receiveBusy}
            onConfirm={receiveTransfer}
            onCancel={() => setReceiveConfirmOpen(false)}
          />
        </div>
        </>      )}

      {tab === "adjustment" && (
        <form onSubmit={submitAdjustment} className="space-y-3 rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Adjustment <span className="text-xs font-normal text-amber-600">— stok berubah setelah approval (BR-017)</span></h2>
          <label className="block text-sm"><span className="mb-1 block font-medium">Alasan *</span>
            <input value={adjReason} onChange={(e) => setAdjReason(e.target.value)} required className={input} />
          </label>
          {adjLines.map((l, i) => (
            <div key={i} className="grid grid-cols-5 items-end gap-2 rounded-lg border bg-slate-50 p-3">
              <label className="col-span-2 text-xs"><span className="mb-1 block font-medium">Material</span>
                <select value={l.material_id} onChange={(e) => { const n = [...adjLines]; n[i].material_id = e.target.value; setAdjLines(n); }} required className={input}>
                  <option value="">—</option>{materials.map((m) => <option key={m.id} value={m.id}>{m.code} — {m.name}</option>)}
                </select>
              </label>
              <label className="text-xs"><span className="mb-1 block font-medium">Gudang</span>
                <select value={l.warehouse_id} onChange={(e) => { const n = [...adjLines]; n[i].warehouse_id = e.target.value; setAdjLines(n); }} required className={input}>
                  <option value="">—</option>{warehouses.map((w) => <option key={w.id} value={w.id}>{w.code}</option>)}
                </select>
              </label>
              <label className="text-xs"><span className="mb-1 block font-medium">Qty Δ (+/−)</span>
                <input type="number" step="any" value={l.qty_delta} onChange={(e) => { const n = [...adjLines]; n[i].qty_delta = e.target.value; setAdjLines(n); }} required className={input} />
              </label>
              <label className="text-xs"><span className="mb-1 block font-medium">Unit cost (bila +)</span>
                <input type="number" step="any" min="0" value={l.unit_cost} onChange={(e) => { const n = [...adjLines]; n[i].unit_cost = e.target.value; setAdjLines(n); }} className={input} />
              </label>
              <label className="hidden">UOM
                <select value={l.uom_id} onChange={(e) => { const n = [...adjLines]; n[i].uom_id = e.target.value; setAdjLines(n); }}>
                  {uoms.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
                </select>
              </label>
            </div>
          ))}
          <p className="text-xs text-slate-500">UOM line: pilih di sini →</p>
          <div className="flex items-center gap-2">
            <select value={adjLines[0].uom_id} onChange={(e) => setAdjLines(adjLines.map((l) => ({ ...l, uom_id: e.target.value })))} required className="rounded border px-2 py-1.5 text-sm">
              <option value="">UOM *</option>{uoms.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
            </select>
            <button type="button" onClick={() => setAdjLines([...adjLines, { material_id: "", warehouse_id: "", qty_delta: "", unit_cost: "", uom_id: adjLines[0].uom_id }])} className="rounded border px-3 py-1.5 text-sm">+ Baris</button>
            <button disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">{busy ? "…" : "Buat + Submit Approval"}</button>
          </div>
        </form>
      )}

      {tab === "opname" && (
        <div className="space-y-3 rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Stock Opname <span className="text-xs font-normal text-amber-600">— freeze → hitung fisik → approval → koreksi</span></h2>
          {!opname ? (
            <form onSubmit={startOpname} className="flex items-end gap-3">
              <label className="text-sm"><span className="mb-1 block font-medium">Gudang *</span>
                <select value={opnWarehouseId} onChange={(e) => setOpnWarehouseId(e.target.value)} required className={input}>
                  <option value="">— pilih —</option>{warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
                </select>
              </label>
              <button disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">{busy ? "…" : "Freeze & Mulai Hitung"}</button>
            </form>
          ) : (
            <div className="space-y-3">
              <p className="text-sm">Opname <span className="font-mono font-medium">{opname.doc_no}</span> — input qty fisik per baris:</p>
              <table className="w-full text-sm">
                <thead className="border-b text-left text-xs text-slate-500">
                  <tr><th className="py-1">Material</th><th className="py-1 text-right">Sistem</th><th className="py-1 text-right">Fisik</th></tr>
                </thead>
                <tbody>
                  {opname.lines.map((l) => {
                    const m = materials.find((x) => x.id === l.material_id);
                    return (
                      <tr key={l.id} className="border-b last:border-0">
                        <td className="py-1.5"><span className="font-mono">{m?.code ?? l.material_id}</span> {m?.name}</td>
                        <td className="py-1.5 text-right">{Number(l.system_qty).toLocaleString("id-ID")}</td>
                        <td className="py-1.5 text-right">
                          <input type="number" step="any" min="0" value={counts[l.id] ?? ""} onChange={(e) => setCounts({ ...counts, [l.id]: e.target.value })} className="w-28 rounded border px-2 py-1 text-right text-sm" />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              <div className="flex gap-2">
                <button onClick={submitCounts} disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">{busy ? "…" : "Submit Counts → Approval"}</button>
                <button onClick={() => setOpname(null)} className="rounded border px-4 py-1.5 text-sm">Batal</button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
