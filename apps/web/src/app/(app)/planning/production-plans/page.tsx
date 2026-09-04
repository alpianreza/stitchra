"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select } from "@/components/ui";

interface Style { id: number; style_no: string }
interface SoLine { style_id: number; style?: Style }
interface SalesOrder { id: number; doc_no: string; customer?: { name: string }; lines?: SoLine[] }
interface Line { id: number; code: string; name: string; capacity_std: number | null; factory?: { code: string; name: string } | null }
interface Mo { id: number; doc_no: string; sales_order_id: number; style_id: number; qty_planned: string; style?: Style; sales_order?: { doc_no: string } }
interface Loading { id: number; plan_date: string; planned_qty: string; capacity_snapshot: string | null; production_order?: Mo }
interface Plan {
  id: number; sales_order_id: number; style_id: number; line_id: number;
  period_start: string; period_end: string; target_qty: string; loadings_sum_planned_qty?: string | null;
  sales_order?: SalesOrder; style?: Style; line?: Line; loadings?: Loading[];
}
interface CapacityRow {
  line_id: number; line?: Line; plan_date: string; planned_qty: string; capacity_qty: string | null;
  variance_qty: string | null; load_pct: number | null; is_overloaded: boolean; capacity_source: string; loading_count: number;
}
interface Options { sales_orders: SalesOrder[]; lines: Line[]; production_orders: Mo[] }

const dateOnly = (value: string) => value.slice(0, 10);
const qty = (value: string | number | null | undefined) => Number(value ?? 0).toLocaleString("id-ID", { maximumFractionDigits: 2 });

export default function ProductionPlansPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [capacity, setCapacity] = useState<CapacityRow[]>([]);
  const [options, setOptions] = useState<Options>({ sales_orders: [], lines: [], production_orders: [] });
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [planForm, setPlanForm] = useState({ sales_order_id: "", style_id: "", line_id: "", period_start: "", period_end: "", target_qty: "" });
  const [loadingForm, setLoadingForm] = useState({ production_plan_id: "", production_order_id: "", plan_date: "", planned_qty: "" });

  const load = useCallback(async () => {
    setError(null);
    try {
      const [planPage, capacityPage, optionData] = await Promise.all([
        api.get<{ data: Plan[] }>("/planning/production-plans?per_page=100"),
        api.get<{ data: CapacityRow[] }>("/planning/line-loading/capacity"),
        api.get<Options>("/planning/production-plans/options"),
      ]);
      setPlans(planPage.data);
      setCapacity(capacityPage.data);
      setOptions(optionData);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal memuat Production Plan.");
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const selectedSoStyles = useMemo(() => {
    const so = options.sales_orders.find((row) => String(row.id) === planForm.sales_order_id);
    const unique = new Map<number, Style>();
    for (const line of so?.lines ?? []) if (line.style) unique.set(line.style.id, line.style);
    return [...unique.values()];
  }, [options.sales_orders, planForm.sales_order_id]);

  const selectedPlan = plans.find((row) => String(row.id) === loadingForm.production_plan_id);
  const eligibleMos = options.production_orders.filter((mo) => !selectedPlan || (mo.sales_order_id === selectedPlan.sales_order_id && mo.style_id === selectedPlan.style_id));

  async function createPlan() {
    setBusy(true); setError(null); setMessage(null);
    try {
      await api.post("/planning/production-plans", {
        sales_order_id: Number(planForm.sales_order_id), style_id: Number(planForm.style_id), line_id: Number(planForm.line_id),
        period_start: planForm.period_start, period_end: planForm.period_end, target_qty: Number(planForm.target_qty),
      });
      setMessage("Production Plan tersimpan.");
      setPlanForm({ sales_order_id: "", style_id: "", line_id: "", period_start: "", period_end: "", target_qty: "" });
      await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyimpan Production Plan."); }
    finally { setBusy(false); }
  }

  async function createLoading() {
    setBusy(true); setError(null); setMessage(null);
    try {
      await api.post(`/planning/production-plans/${loadingForm.production_plan_id}/loadings`, {
        production_order_id: Number(loadingForm.production_order_id), plan_date: loadingForm.plan_date, planned_qty: Number(loadingForm.planned_qty),
      });
      setMessage("Line Loading tersimpan dan jadwal MO diperbarui.");
      setLoadingForm((current) => ({ ...current, production_order_id: "", planned_qty: "" }));
      await load();
    } catch (e) { setError(e instanceof Error ? e.message : "Gagal menyimpan Line Loading."); }
    finally { setBusy(false); }
  }

  const planReady = Object.values(planForm).every(Boolean);
  const loadingReady = Object.values(loadingForm).every(Boolean);

  return (
    <div className="space-y-6">
      <PageHeader eyebrow="PPIC" title="Production Plan & Line Loading" description="Rencanakan target SO/style per line dan tanggal. Kapasitas memakai snapshot capacity_std; overload ditampilkan sebagai warning, bukan hard block." />
      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <div className="grid gap-4 xl:grid-cols-2">
        <section className="rounded-xl border bg-white p-4">
          <h2 className="mb-3 font-semibold">Buat Production Plan</h2>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm"><span className="mb-1 block font-medium">SO CONFIRMED</span><Select className="w-full" value={planForm.sales_order_id} onChange={(e) => setPlanForm({ ...planForm, sales_order_id: e.target.value, style_id: "" })}><option value="">- pilih SO -</option>{options.sales_orders.map((so) => <option key={so.id} value={so.id}>{so.doc_no} · {so.customer?.name}</option>)}</Select></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Style dari matrix SO</span><Select className="w-full" value={planForm.style_id} onChange={(e) => setPlanForm({ ...planForm, style_id: e.target.value })}><option value="">- pilih style -</option>{selectedSoStyles.map((style) => <option key={style.id} value={style.id}>{style.style_no}</option>)}</Select></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Line</span><Select className="w-full" value={planForm.line_id} onChange={(e) => setPlanForm({ ...planForm, line_id: e.target.value })}><option value="">- pilih line -</option>{options.lines.map((line) => <option key={line.id} value={line.id}>{line.code} · {line.name} · cap {line.capacity_std ?? "belum diisi"}</option>)}</Select></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Target Qty</span><Input type="number" min="0.0001" step="0.0001" value={planForm.target_qty} onChange={(e) => setPlanForm({ ...planForm, target_qty: e.target.value })} /></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Periode Mulai</span><Input type="date" value={planForm.period_start} onChange={(e) => setPlanForm({ ...planForm, period_start: e.target.value })} /></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Periode Selesai</span><Input type="date" value={planForm.period_end} onChange={(e) => setPlanForm({ ...planForm, period_end: e.target.value })} /></label>
          </div>
          <Button className="mt-4" loading={busy} disabled={!planReady} onClick={createPlan}>Simpan Production Plan</Button>
        </section>

        <section className="rounded-xl border bg-white p-4">
          <h2 className="mb-3 font-semibold">Tambahkan Line Loading</h2>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm sm:col-span-2"><span className="mb-1 block font-medium">Production Plan</span><Select className="w-full" value={loadingForm.production_plan_id} onChange={(e) => { const plan = plans.find((row) => String(row.id) === e.target.value); setLoadingForm({ production_plan_id: e.target.value, production_order_id: "", plan_date: plan ? dateOnly(plan.period_start) : "", planned_qty: "" }); }}><option value="">- pilih plan -</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.sales_order?.doc_no} · {plan.style?.style_no} · {plan.line?.code} · {dateOnly(plan.period_start)}–{dateOnly(plan.period_end)}</option>)}</Select></label>
            <label className="text-sm sm:col-span-2"><span className="mb-1 block font-medium">MO PLANNED yang sesuai</span><Select className="w-full" value={loadingForm.production_order_id} onChange={(e) => setLoadingForm({ ...loadingForm, production_order_id: e.target.value })}><option value="">- pilih MO -</option>{eligibleMos.map((mo) => <option key={mo.id} value={mo.id}>{mo.doc_no} · {mo.style?.style_no} · qty {qty(mo.qty_planned)}</option>)}</Select></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Tanggal Loading</span><Input type="date" min={selectedPlan ? dateOnly(selectedPlan.period_start) : undefined} max={selectedPlan ? dateOnly(selectedPlan.period_end) : undefined} value={loadingForm.plan_date} onChange={(e) => setLoadingForm({ ...loadingForm, plan_date: e.target.value })} /></label>
            <label className="text-sm"><span className="mb-1 block font-medium">Planned Qty</span><Input type="number" min="0.0001" step="0.0001" value={loadingForm.planned_qty} onChange={(e) => setLoadingForm({ ...loadingForm, planned_qty: e.target.value })} /></label>
          </div>
          <Button className="mt-4" loading={busy} disabled={!loadingReady} onClick={createLoading}>Simpan Line Loading</Button>
        </section>
      </div>

      <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-3 font-semibold">Production Plan</h2>
        <div className="overflow-x-auto"><table className="w-full min-w-[950px] text-sm"><thead className="border-b bg-slate-50 text-left"><tr><th className="px-3 py-2">SO / Style</th><th className="px-3 py-2">Line</th><th className="px-3 py-2">Periode</th><th className="px-3 py-2 text-right">Target</th><th className="px-3 py-2 text-right">Loaded</th><th className="px-3 py-2">Detail harian</th></tr></thead><tbody>{plans.map((plan) => <tr key={plan.id} className="border-b align-top"><td className="px-3 py-2"><b>{plan.sales_order?.doc_no}</b><br />{plan.style?.style_no}</td><td className="px-3 py-2">{plan.line?.code} · {plan.line?.name}</td><td className="px-3 py-2">{dateOnly(plan.period_start)} – {dateOnly(plan.period_end)}</td><td className="px-3 py-2 text-right">{qty(plan.target_qty)}</td><td className="px-3 py-2 text-right">{qty(plan.loadings_sum_planned_qty)}</td><td className="px-3 py-2">{(plan.loadings ?? []).length === 0 ? <span className="text-amber-700">Belum dijadwalkan</span> : (plan.loadings ?? []).map((row) => <div key={row.id}>{dateOnly(row.plan_date)} · {row.production_order?.doc_no} · {qty(row.planned_qty)}</div>)}</td></tr>)}{plans.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada Production Plan.</td></tr>}</tbody></table></div>
      </section>

      <section className="rounded-xl border bg-white p-4">
        <h2 className="mb-1 font-semibold">Line Loading vs Capacity</h2>
        <p className="mb-3 text-xs text-slate-500">Capacity merupakan snapshot dari master line saat loading disimpan. Overload hanya warning karena overtime, calendar kerja, dan split-line policy belum didefinisikan.</p>
        <div className="overflow-x-auto"><table className="w-full min-w-[800px] text-sm"><thead className="border-b bg-slate-50 text-left"><tr><th className="px-3 py-2">Tanggal</th><th className="px-3 py-2">Line</th><th className="px-3 py-2 text-right">Planned</th><th className="px-3 py-2 text-right">Capacity</th><th className="px-3 py-2 text-right">Load</th><th className="px-3 py-2">Status</th></tr></thead><tbody>{capacity.map((row) => <tr key={`${row.line_id}-${row.plan_date}`} className="border-b"><td className="px-3 py-2">{row.plan_date}</td><td className="px-3 py-2">{row.line?.code} · {row.line?.name}</td><td className="px-3 py-2 text-right">{qty(row.planned_qty)}</td><td className="px-3 py-2 text-right">{row.capacity_qty === null ? "Belum diisi" : qty(row.capacity_qty)}</td><td className="px-3 py-2 text-right">{row.load_pct === null ? "—" : `${row.load_pct}%`}</td><td className="px-3 py-2">{row.capacity_qty === null ? <span className="text-amber-700">Capacity belum diisi</span> : row.is_overloaded ? <span className="rounded bg-red-100 px-2 py-1 text-xs text-red-700">Overload warning</span> : <span className="rounded bg-emerald-100 px-2 py-1 text-xs text-emerald-700">Within capacity</span>}</td></tr>)}{capacity.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Belum ada Line Loading.</td></tr>}</tbody></table></div>
      </section>
    </div>
  );
}
