"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { api } from "@/lib/api";
import { Button, ConfirmDialog, Input, PageHeader, StatusBadge } from "@/components/ui";

type Lay = {
  id: number;
  lay_no: string;
  status: string;
  shade_validation_enabled: boolean;
  shade_group_id: number | null;
  rolls?: Array<{ id: number; qty_used: string; shade_override: boolean; fabric_roll?: { roll_no: string; shade_group_id: number | null } }>;
  cut_outputs?: Array<{ id: number; qty_cut: string; cut_order_line?: { id: number; qty_cut: string }; bundles?: Array<{ bundle_no: string; qty: string }> }>;
};
type FieldState = {
  layId: string; rollId: string; qty: string; lineId: string; outputQty: string;
  outputId: string; bundleSize: string; reason: string; overrideId: string; layers: string;
};

/** Eksekusi cutting - BR-031: Lay Roll adalah sole actual fabric-consumption authority. */
export default function CuttingExecutionPage() {
  const [cutOrder, setCutOrder] = useState("");
  const [lays, setLays] = useState<Lay[]>([]);
  const [message, setMessage] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [completeOpen, setCompleteOpen] = useState(false);
  const [field, setField] = useState<FieldState>({
    layId: "", rollId: "", qty: "", lineId: "", outputQty: "", outputId: "",
    bundleSize: "10", reason: "", overrideId: "", layers: "1",
  });

  const set = (k: keyof FieldState, v: string) => setField((x) => ({ ...x, [k]: v }));

  async function load() {
    if (!cutOrder) return;
    setBusy(true); setError(null);
    try {
      const r = await api.get<{ data: Lay[] }>(`/cutting/orders/${cutOrder}/lays`);
      setLays(r.data);
      setMessage("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal memuat lays");
    } finally { setBusy(false); }
  }

  async function execute(fn: () => Promise<unknown>) {
    setBusy(true); setError(null);
    try {
      await fn();
      setMessage("Tersimpan");
      if (cutOrder) await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal");
    } finally { setBusy(false); }
  }

  function submit(e: FormEvent, fn: () => Promise<unknown>) {
    e.preventDefault();
    execute(fn);
  }

  async function completeCutOrder() {
    if (!cutOrder) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/cutting/orders/${cutOrder}/complete`, {});
      setMessage(`Cut Order #${cutOrder} selesai (completed).`);
      setCompleteOpen(false);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal complete cut order");
      setCompleteOpen(false);
    } finally { setBusy(false); }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        eyebrow="Cutting"
        title="Lay, Shade & Cut Output"
        description="BR-031: Lay Roll adalah sole actual fabric-consumption authority untuk eksekusi baru. Marker tetap legacy read-only."
      />

      <div className="rounded-[var(--radius-surface)] border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
        Alur: Cut Plan → Cut Order → Lay Roll → Cut Output → Bundle. Tidak ada stock movement baru; consumption tetap memakai dispatch balance dan physical Fabric Roll.
      </div>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Sumber Eksekusi Cutting</h2>
        <p className="mt-1 text-sm text-[var(--color-text-muted)]">Untuk eksekusi baru, susun planned lay dan size ratio lalu buat Cut Order dari workbench Cut Plan. Endpoint direct MO lama tetap dipertahankan hanya untuk kompatibilitas.</p>
        <Link href="/planning/cut-plans" className="mt-3 inline-flex min-h-9 items-center rounded-[var(--radius-control)] bg-[var(--color-primary)] px-3 text-sm font-medium text-white hover:bg-[var(--color-primary-hover)]">
          Buka Cut Plan & Planned Lays
        </Link>
      </section>

      <div className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Cut Order aktif</h2>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Input type="number" min="1" value={cutOrder} onChange={(e) => setCutOrder(e.target.value)} placeholder="Cut Order ID" className="w-44" />
          <Button variant="secondary" loading={busy} disabled={!cutOrder} onClick={load}>Muat</Button>
          <Button variant="danger" disabled={!cutOrder || lays.length === 0} onClick={() => setCompleteOpen(true)}>
            Complete Cut Order
          </Button>
        </div>
      </div>

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}
      {message && <p className="rounded-[var(--radius-surface)] bg-[var(--color-surface-subtle)] p-3 text-sm">{message}</p>}

      <div className="grid gap-4 lg:grid-cols-2">
        <form className="space-y-2 rounded-[var(--radius-surface)] border bg-white p-4" onSubmit={(e) => submit(e, () => api.post(`/cutting/orders/${cutOrder}/lays`, { layer_count: Number(field.layers) }))}>
          <h2 className="font-semibold">1. Buat Lay</h2>
          <Input value={field.layers} onChange={(e) => set("layers", e.target.value)} placeholder="Layer count" required />
          <Button type="submit" loading={busy}>Buat Lay</Button>
        </form>

        <form className="space-y-2 rounded-[var(--radius-surface)] border bg-white p-4" onSubmit={(e) => submit(e, () => api.post(`/cutting/lays/${field.layId}/rolls`, { fabric_roll_id: Number(field.rollId), qty_used: Number(field.qty) }))}>
          <h2 className="font-semibold">2. Consume Lay Roll</h2>
          <Input value={field.layId} onChange={(e) => set("layId", e.target.value)} placeholder="Lay ID" required />
          <Input value={field.rollId} onChange={(e) => set("rollId", e.target.value)} placeholder="Fabric Roll ID" required />
          <Input value={field.qty} onChange={(e) => set("qty", e.target.value)} placeholder="Qty dipakai" required />
          <Button type="submit" loading={busy}>Validasi & Consume</Button>
        </form>

        <form className="space-y-2 rounded-[var(--radius-surface)] border bg-white p-4" onSubmit={(e) => submit(e, () => api.post(`/cutting/lays/${field.layId}/shade-overrides`, { fabric_roll_id: Number(field.rollId), qty_used: Number(field.qty), reason: field.reason }))}>
          <h2 className="font-semibold">Controlled Override</h2>
          <Input value={field.reason} onChange={(e) => set("reason", e.target.value)} placeholder="Reason wajib" required />
          <Button type="submit" loading={busy}>Kirim Approval</Button>
          <Input value={field.overrideId} onChange={(e) => set("overrideId", e.target.value)} placeholder="Override request ID" />
          <Button type="button" variant="secondary" loading={busy} disabled={!field.overrideId} onClick={() => execute(() => api.post(`/cutting/shade-overrides/${field.overrideId}/apply`, {}))}>
            Apply APPROVED
          </Button>
        </form>

        <form className="space-y-2 rounded-[var(--radius-surface)] border bg-white p-4" onSubmit={(e) => submit(e, () => api.post(`/cutting/lays/${field.layId}/outputs`, { cut_order_line_id: Number(field.lineId), qty_cut: Number(field.outputQty) }))}>
          <h2 className="font-semibold">3. Cut Output</h2>
          <Input value={field.lineId} onChange={(e) => set("lineId", e.target.value)} placeholder="Cut Order Line ID" required />
          <Input value={field.outputQty} onChange={(e) => set("outputQty", e.target.value)} placeholder="Qty output" required />
          <Button type="submit" loading={busy}>Catat Output</Button>
        </form>

        <form className="space-y-2 rounded-[var(--radius-surface)] border bg-white p-4" onSubmit={(e) => submit(e, () => api.post(`/cutting/outputs/${field.outputId}/bundles`, { bundle_size: Number(field.bundleSize) }))}>
          <h2 className="font-semibold">4. Bundle</h2>
          <Input value={field.outputId} onChange={(e) => set("outputId", e.target.value)} placeholder="Cut Output ID" required />
          <Input value={field.bundleSize} onChange={(e) => set("bundleSize", e.target.value)} placeholder="Bundle size" required />
          <Button type="submit" loading={busy}>Generate Bundle</Button>
        </form>

        <form className="space-y-2 rounded-[var(--radius-surface)] border bg-white p-4" onSubmit={(e) => submit(e, () => api.post(`/cutting/lays/${field.layId}/complete`, {}))}>
          <h2 className="font-semibold">5. Complete Lay</h2>
          <Input value={field.layId} onChange={(e) => set("layId", e.target.value)} placeholder="Lay ID" required />
          <Button type="submit" loading={busy}>Complete Lay</Button>
        </form>
      </div>

      {lays.map((l) => (
        <article key={l.id} className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <div className="flex items-center justify-between gap-2">
            <b className="font-mono">{l.lay_no}</b>
            <StatusBadge status={l.status} />
          </div>
          {l.rolls && l.rolls.length > 0 && (
            <div className="mt-2 text-sm text-[var(--color-text-muted)]">
              <p className="text-xs font-semibold uppercase tracking-wider">Rolls</p>
              <ul className="mt-1 space-y-0.5">
                {l.rolls.map((r) => (
                  <li key={r.id}>
                    {r.fabric_roll?.roll_no ?? `Roll #${r.id}`} - {r.qty_used}
                    {r.shade_override ? " (shade override)" : ""}
                  </li>
                ))}
              </ul>
            </div>
          )}
          {l.cut_outputs && l.cut_outputs.length > 0 && (
            <div className="mt-2 text-sm text-[var(--color-text-muted)]">
              <p className="text-xs font-semibold uppercase tracking-wider">Cut Outputs</p>
              <ul className="mt-1 space-y-0.5">
                {l.cut_outputs.map((o) => (
                  <li key={o.id}>
                    Output #{o.id} - qty {o.qty_cut} - {o.bundles?.length ?? 0} bundle
                  </li>
                ))}
              </ul>
            </div>
          )}
        </article>
      ))}

      <ConfirmDialog
        open={completeOpen}
        title="Complete Cut Order?"
        description={`Cut Order #${cutOrder} akan ditandai selesai. Pastikan seluruh lay sudah complete.`}
        confirmLabel="Complete"
        variant="danger"
        loading={busy}
        onConfirm={completeCutOrder}
        onCancel={() => setCompleteOpen(false)}
      />
    </div>
  );
}
