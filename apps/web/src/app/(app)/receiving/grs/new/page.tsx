"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

interface PoLine {
  id: number;
  material_id: number;
  qty: string;
  received_qty: string;
  unit_price: string;
  uom_id: number;
  material?: { code: string; name: string; tracking_level: string; type: string };
}
interface Po { id: number; doc_no: string; supplier?: { name: string }; lines: PoLine[] }
interface PoListItem { id: number; doc_no: string; supplier?: { name: string } }
interface Warehouse { id: number; code: string; name: string; type: string }

interface RollInput { roll_no: string; qty_buy: string; qty_meter_actual: string; lot_no: string }
interface LineInput { qty_received: string; rolls: RollInput[] }

/** Goods Receipt — fabric wajib per roll (BR-052); masuk QUALITY_HOLD (BR-004) */
export default function NewGrPage() {
  const router = useRouter();

  const [pos, setPos] = useState<PoListItem[]>([]);
  const [poId, setPoId] = useState("");
  const [po, setPo] = useState<Po | null>(null);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState("");
  const [receivedDate, setReceivedDate] = useState(new Date().toISOString().slice(0, 10));
  const [dnNo, setDnNo] = useState("");
  const [lineInputs, setLineInputs] = useState<Record<number, LineInput>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    // PO yang bisa diterima: APPROVED / PARTIAL_RECEIVED
    Promise.all([
      api.get<{ data: PoListItem[] }>("/purchasing/pos?status=APPROVED&per_page=100"),
      api.get<{ data: PoListItem[] }>("/purchasing/pos?status=PARTIAL_RECEIVED&per_page=100"),
    ]).then(([a, b]) => setPos([...a.data, ...b.data])).catch((e) => setError(e.message));

    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100")
      .then((r) => setWarehouses(r.data.filter((w) => w.type === "RM")))
      .catch(() => {});
  }, []);

  async function selectPo(id: string) {
    setPoId(id);
    setPo(null);
    if (!id) return;
    const detail = await api.get<Po>(`/purchasing/pos/${id}`);
    setPo(detail);
    // Prefill: qty_received = sisa
    const init: Record<number, LineInput> = {};
    for (const l of detail.lines) {
      const remaining = Number(l.qty) - Number(l.received_qty);
      init[l.id] = {
        qty_received: remaining > 0 ? String(remaining) : "0",
        rolls: l.material?.tracking_level === "ROLL"
          ? [{ roll_no: "", qty_buy: String(remaining > 0 ? remaining : 0), qty_meter_actual: "", lot_no: "" }]
          : [],
      };
    }
    setLineInputs(init);
  }

  function setRoll(lineId: number, ri: number, field: keyof RollInput, value: string) {
    const next = { ...lineInputs };
    next[lineId].rolls[ri] = { ...next[lineId].rolls[ri], [field]: value };
    setLineInputs(next);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!po) return;
    setSaving(true); setError(null);
    try {
      const lines = po.lines
        .filter((l) => Number(lineInputs[l.id]?.qty_received) > 0)
        .map((l) => ({
          po_line_id: l.id,
          qty_received: Number(lineInputs[l.id].qty_received),
          uom_id: l.uom_id,
          unit_price: Number(l.unit_price),
          rolls: l.material?.tracking_level === "ROLL"
            ? lineInputs[l.id].rolls.filter((r) => r.roll_no.trim()).map((r) => ({
                roll_no: r.roll_no.trim(),
                qty_buy: Number(r.qty_buy),
                qty_meter_actual: r.qty_meter_actual ? Number(r.qty_meter_actual) : undefined,
                lot_no: r.lot_no || undefined,
              }))
            : undefined,
        }));

      const gr = await api.post<{ doc_no: string }>("/receiving/grs", {
        purchase_order_id: po.id,
        warehouse_id: Number(warehouseId),
        received_date: receivedDate,
        delivery_note_no: dnNo || undefined,
        lines,
      });

      router.push(`/receiving/grs?created=${gr.doc_no}`);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";

  return (
    <form onSubmit={save} className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">Goods Receipt Baru</h1>
        <button type="button" onClick={() => router.back()} className="rounded border px-3 py-1.5 text-sm">← Kembali</button>
      </div>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}

      <section className="grid grid-cols-2 gap-3 rounded-xl border bg-white p-4 md:grid-cols-4">
        <label className="text-sm">
          <span className="mb-1 block font-medium">PO *</span>
          <select value={poId} onChange={(e) => selectPo(e.target.value)} required className={input}>
            <option value="">— pilih PO —</option>
            {pos.map((p) => <option key={p.id} value={p.id}>{p.doc_no} — {p.supplier?.name}</option>)}
          </select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Gudang (RM) *</span>
          <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} required className={input}>
            <option value="">— pilih —</option>
            {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
          </select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">Tgl Terima *</span>
          <input type="date" value={receivedDate} onChange={(e) => setReceivedDate(e.target.value)} required className={input} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium">No. Surat Jalan</span>
          <input value={dnNo} onChange={(e) => setDnNo(e.target.value)} className={input} />
        </label>
      </section>

      {po && (
        <section className="space-y-3 rounded-xl border bg-white p-4">
          <h2 className="font-semibold">Lines PO {po.doc_no} — {po.supplier?.name}</h2>
          {po.lines.map((l) => {
            const li = lineInputs[l.id];
            const remaining = Number(l.qty) - Number(l.received_qty);
            const isRoll = l.material?.tracking_level === "ROLL";
            return (
              <div key={l.id} className="rounded-lg border bg-slate-50 p-3">
                <div className="grid grid-cols-6 items-end gap-2">
                  <div className="col-span-2 text-sm">
                    <span className="font-mono">{l.material?.code}</span> {l.material?.name}
                    <div className="text-xs text-slate-500">
                      order {Number(l.qty)} · diterima {Number(l.received_qty)} · sisa {remaining}
                      {isRoll && <span className="ml-1 rounded bg-amber-100 px-1 text-amber-700">ROLL</span>}
                    </div>
                  </div>
                  <label className="text-xs">
                    <span className="mb-1 block font-medium">Qty diterima</span>
                    <input
                      type="number" step="any" min="0" max={remaining}
                      value={li?.qty_received ?? ""}
                      onChange={(e) => setLineInputs({ ...lineInputs, [l.id]: { ...li, qty_received: e.target.value } })}
                      className={input}
                    />
                  </label>
                  {isRoll && (
                    <div className="col-span-3 text-xs">
                      <div className="mb-1 flex items-center justify-between">
                        <span className="font-medium">Rolls (BR-052 — wajib per roll)</span>
                        <button
                          type="button"
                          onClick={() => setLineInputs({ ...lineInputs, [l.id]: { ...li, rolls: [...li.rolls, { roll_no: "", qty_buy: "", qty_meter_actual: "", lot_no: "" }] } })}
                          className="rounded border px-2 py-0.5"
                        >+ Roll</button>
                      </div>
                      {li?.rolls.map((r, ri) => (
                        <div key={ri} className="mb-1 grid grid-cols-4 gap-1">
                          <input placeholder="No. roll *" value={r.roll_no} onChange={(e) => setRoll(l.id, ri, "roll_no", e.target.value)} className="rounded border px-2 py-1 font-mono" />
                          <input type="number" step="any" placeholder="Qty beli (kg/yard) *" value={r.qty_buy} onChange={(e) => setRoll(l.id, ri, "qty_buy", e.target.value)} className="rounded border px-2 py-1" />
                          <input type="number" step="any" placeholder="Meter aktual" value={r.qty_meter_actual} onChange={(e) => setRoll(l.id, ri, "qty_meter_actual", e.target.value)} className="rounded border px-2 py-1" />
                          <input placeholder="Lot" value={r.lot_no} onChange={(e) => setRoll(l.id, ri, "lot_no", e.target.value)} className="rounded border px-2 py-1" />
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </section>
      )}

      {po && (
        <button disabled={saving} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
          {saving ? "Memproses…" : "Posting GR (→ QUALITY_HOLD)"}
        </button>
      )}
    </form>
  );
}
