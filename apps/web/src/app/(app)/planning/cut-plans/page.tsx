"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select } from "@/components/ui";

type MatrixLine = {
  colorway_id: number; size_id: number; qty_planned?: string; qty?: string;
  colorway?: { id: number; color?: { code: string; name: string }; lab_dip_ref?: string | null };
  size?: { id: number; code: string };
};
type Mo = {
  id: number; doc_no: string; qty_planned: string; matrix_source: string; matrix: MatrixLine[];
  style?: { style_no: string }; sales_order?: { doc_no: string };
};
type Ratio = { id: number; ratio_qty: string; size?: { code: string } };
type PlannedLay = {
  id: number; lay_sequence: number; layer_count: number; estimated_marker_length_m: string | null;
  colorway?: MatrixLine["colorway"]; ratios?: Ratio[];
};
type CutOrder = { id: number; doc_no: string; status: string };
type Plan = {
  id: number; doc_no: string; total_qty: string; planned_lay_count: number;
  production_order?: { doc_no: string; style?: { style_no: string }; sales_order?: { doc_no: string } };
  lays?: PlannedLay[]; cut_orders?: CutOrder[];
};
type LayDraft = { colorway_id: string; layer_count: string; estimated_marker_length_m: string; ratios: Record<string, string> };

const amount = (value: string | number) => Number(value).toLocaleString("id-ID", { maximumFractionDigits: 4 });
const colorLabel = (line: MatrixLine) => line.colorway?.color ? `${line.colorway.color.code} · ${line.colorway.color.name}` : `Colorway #${line.colorway_id}`;

export default function CutPlansPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [mos, setMos] = useState<Mo[]>([]);
  const [moId, setMoId] = useState("");
  const [lays, setLays] = useState<LayDraft[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const [planPage, options] = await Promise.all([
        api.get<{ data: Plan[] }>("/planning/cut-plans?per_page=100"),
        api.get<{ production_orders: Mo[] }>("/planning/cut-plans/options"),
      ]);
      setPlans(planPage.data);
      setMos(options.production_orders);
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal memuat Cut Plan."); }
  }, []);

  useEffect(() => { load(); }, [load]);
  const selectedMo = mos.find((mo) => String(mo.id) === moId);
  const colorways = useMemo(() => {
    const unique = new Map<number, MatrixLine>();
    for (const row of selectedMo?.matrix ?? []) if (!unique.has(row.colorway_id)) unique.set(row.colorway_id, row);
    return [...unique.values()];
  }, [selectedMo]);

  function selectMo(value: string) {
    setMoId(value);
    const mo = mos.find((row) => String(row.id) === value);
    const firstColor = mo?.matrix[0]?.colorway_id;
    setLays(firstColor ? [{ colorway_id: String(firstColor), layer_count: "1", estimated_marker_length_m: "", ratios: {} }] : []);
  }

  function updateLay(index: number, patch: Partial<LayDraft>) {
    setLays((current) => current.map((lay, rowIndex) => rowIndex === index ? { ...lay, ...patch } : lay));
  }

  function addLay() {
    const firstColor = colorways[0]?.colorway_id;
    if (!firstColor) return;
    setLays((current) => [...current, { colorway_id: String(firstColor), layer_count: "1", estimated_marker_length_m: "", ratios: {} }]);
  }

  async function savePlan() {
    if (!selectedMo) return;
    const payload = lays.map((lay) => ({
      colorway_id: Number(lay.colorway_id),
      layer_count: Number(lay.layer_count),
      estimated_marker_length_m: lay.estimated_marker_length_m ? Number(lay.estimated_marker_length_m) : null,
      ratios: Object.entries(lay.ratios).filter(([, value]) => Number(value) > 0).map(([sizeId, value]) => ({ size_id: Number(sizeId), ratio_qty: Number(value) })),
    }));
    if (payload.some((lay) => lay.ratios.length === 0)) { setError("Setiap planned lay wajib memiliki minimal satu size ratio."); return; }
    setBusy(true); setError(null); setMessage(null);
    try {
      const plan = await api.post<Plan>(`/planning/cut-plans/from-mo/${selectedMo.id}`, { lays: payload });
      setMessage(`Cut Plan ${plan.doc_no} tersimpan dari ${selectedMo.doc_no}.`);
      setMoId(""); setLays([]); await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyimpan Cut Plan."); }
    finally { setBusy(false); }
  }

  async function createCutOrder(plan: Plan) {
    setBusy(true); setError(null); setMessage(null);
    try {
      const order = await api.post<CutOrder>(`/planning/cut-plans/${plan.id}/cut-order`, {});
      setMessage(`Cut Order ${order.doc_no} dibuat dari ${plan.doc_no}. Buka Eksekusi Cutting dan gunakan ID ${order.id}.`);
      await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal membuat Cut Order."); }
    finally { setBusy(false); }
  }

  return (
    <div className="space-y-6">
      <PageHeader eyebrow="PPIC" title="Cut Plan & Planned Lays" description="Susun planned lay, colorway, layer count, marker length, dan size ratio. Matrix Cut Order diturunkan dari Cut Plan." />
      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <section className="rounded-xl border bg-white p-4">
        <h2 className="font-semibold">Buat Cut Plan dari MO RELEASED</h2>
        <div className="mt-3 max-w-xl">
          <Select className="w-full" value={moId} onChange={(e) => selectMo(e.target.value)}>
            <option value="">- pilih MO RELEASED -</option>
            {mos.map((mo) => <option key={mo.id} value={mo.id}>{mo.doc_no} · {mo.style?.style_no} · {amount(mo.qty_planned)} · {mo.matrix_source}</option>)}
          </Select>
        </div>
        {selectedMo && <p className="mt-2 text-xs text-slate-500">Source matrix: {selectedMo.matrix_source}. Cut Plan tidak mengubah snapshot MO atau historical execution.</p>}

        {lays.map((lay, index) => {
          const eligible = (selectedMo?.matrix ?? []).filter((row) => String(row.colorway_id) === lay.colorway_id);
          return (
            <div key={index} className="mt-4 rounded-lg border bg-slate-50 p-3">
              <div className="mb-3 flex items-center justify-between"><b>Planned Lay {index + 1}</b><Button type="button" variant="ghost" size="sm" onClick={() => setLays((current) => current.filter((_, i) => i !== index))}>Hapus</Button></div>
              <div className="grid gap-3 md:grid-cols-3">
                <label className="text-sm"><span className="mb-1 block font-medium">Colorway</span><Select className="w-full" value={lay.colorway_id} onChange={(e) => updateLay(index, { colorway_id: e.target.value, ratios: {} })}>{colorways.map((row) => <option key={row.colorway_id} value={row.colorway_id}>{colorLabel(row)}</option>)}</Select></label>
                <label className="text-sm"><span className="mb-1 block font-medium">Layer Count</span><Input type="number" min="1" step="1" value={lay.layer_count} onChange={(e) => updateLay(index, { layer_count: e.target.value })} /></label>
                <label className="text-sm"><span className="mb-1 block font-medium">Est. Marker Length (m)</span><Input type="number" min="0.001" step="0.001" value={lay.estimated_marker_length_m} onChange={(e) => updateLay(index, { estimated_marker_length_m: e.target.value })} placeholder="opsional" /></label>
              </div>
              <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                {eligible.map((row) => <label key={row.size_id} className="rounded border bg-white p-2 text-sm"><span className="block font-medium">Size {row.size?.code ?? row.size_id}</span><span className="block text-xs text-slate-500">MO ceiling {amount(row.qty_planned ?? row.qty ?? 0)}</span><Input className="mt-1" type="number" min="0" step="0.0001" value={lay.ratios[String(row.size_id)] ?? ""} onChange={(e) => updateLay(index, { ratios: { ...lay.ratios, [String(row.size_id)]: e.target.value } })} placeholder="ratio/layer" /></label>)}
              </div>
            </div>
          );
        })}
        <div className="mt-4 flex gap-2"><Button type="button" variant="secondary" disabled={!selectedMo} onClick={addLay}>+ Planned Lay</Button><Button loading={busy} disabled={!selectedMo || lays.length === 0} onClick={savePlan}>Simpan Cut Plan</Button></div>
      </section>

      <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 font-semibold">Cut Plan tersimpan</h2>
        <div className="space-y-3">
          {plans.map((plan) => {
            const activeOrder = (plan.cut_orders ?? []).find((order) => order.status !== "CANCELLED");
            return <article key={plan.id} className="rounded-lg border p-3"><div className="flex flex-wrap items-start justify-between gap-3"><div><b>{plan.doc_no}</b><p className="text-sm text-slate-600">MO {plan.production_order?.doc_no} · {plan.production_order?.style?.style_no} · {plan.planned_lay_count} lay · qty {amount(plan.total_qty)}</p></div>{activeOrder ? <span className="rounded bg-emerald-100 px-2 py-1 text-xs text-emerald-700">Cut Order {activeOrder.doc_no}</span> : <Button size="sm" loading={busy} onClick={() => createCutOrder(plan)}>Buat Cut Order</Button>}</div><div className="mt-2 text-sm">{(plan.lays ?? []).map((lay) => <div key={lay.id}>Lay {lay.lay_sequence} · {lay.colorway?.color?.code ?? `CW#${lay.colorway?.id}`} · {lay.layer_count} layers · {(lay.ratios ?? []).map((ratio) => `${ratio.size?.code}:${ratio.ratio_qty}`).join(" / ")}</div>)}</div></article>;
          })}
          {plans.length === 0 && <p className="text-sm text-slate-500">Belum ada Cut Plan.</p>}
        </div>
        <Link href="/production/cutting" className="mt-4 inline-block text-sm font-medium text-blue-600 hover:underline">Buka Eksekusi Cutting →</Link>
      </section>
    </div>
  );
}
