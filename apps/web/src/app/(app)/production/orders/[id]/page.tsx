"use client";

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { ConfirmDialog } from "@/components/ui";

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
  material_allocations?: {
    material_id: number; qty_required: string; qty_reserved: string; qty_issued: string; is_backflush: boolean;
    material?: { code: string; name: string; tracking_level: string; use_uom_id: number | null };
  }[];
}

interface Warehouse { id: number; code: string; name: string; type: string }
interface Roll { id: number; roll_no: string; qty_remaining_meter: string; lot_no: string | null }
interface IssueInput { qty: string; roll_id: string }
interface IssueLine { id: number; material_id?: number; qty?: string; material?: { code: string; name: string } | null; roll?: { id: number; roll_no: string } | null }
interface IssueRow { id: number; doc_no: string; mode: string; status: string; lines?: IssueLine[] }

/** Detail MO: snapshot BOM/Routing, alokasi material, release/unrelease (BR-060), issue material (BR-041) */
export default function MoDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();

  const [mo, setMo] = useState<MoDetail | null>(null);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState("");
  const [issueWarehouseId, setIssueWarehouseId] = useState("");
  const [issueInputs, setIssueInputs] = useState<Record<number, IssueInput>>({});
  const [rollsByMaterial, setRollsByMaterial] = useState<Record<number, Roll[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showUnreleaseConfirm, setShowUnreleaseConfirm] = useState(false);
  const [issues, setIssues] = useState<IssueRow[] | null>(null);
  const [returnRollId, setReturnRollId] = useState("");
  const [returnWarehouseId, setReturnWarehouseId] = useState("");
  const [returnQty, setReturnQty] = useState("");
  const [returnReason, setReturnReason] = useState("");

  function load() {
    api.get<MoDetail>(`/production/orders/${id}`).then(setMo).catch((e) => setError(e.message));
  }

  useEffect(() => {
    load();
    api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100")
      .then((r) => setWarehouses(r.data.filter((w) => w.type === "RM")))
      .catch(() => {});
    loadIssues();
  }, [id]);

  // Ambil roll RELEASED untuk material fabric yang dialokasikan
  useEffect(() => {
    if (!mo?.material_allocations) return;
    const fabricIds = mo.material_allocations
      .filter((a) => a.material?.tracking_level === "ROLL")
      .map((a) => a.material_id);

    for (const mid of [...new Set(fabricIds)]) {
      if (rollsByMaterial[mid]) continue;
      api.get<{ data: Roll[] }>(`/inventory/rolls?material_id=${mid}`)
        .then((r) => setRollsByMaterial((prev) => ({ ...prev, [mid]: r.data })))
        .catch(() => {});
    }
  }, [mo]);

  async function release() {
    if (!warehouseId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      await api.post(`/production/orders/${id}/release`, { warehouse_id: Number(warehouseId) });
      setMessage("MO RELEASED — material ter-reservasi (BR-060).");
      load();
    } catch (e: any) {
      setError(e.message);   // shortage → pesan BR-040 dari server
    } finally {
      setBusy(false);
    }
  }

  async function unrelease() {
    setBusy(true); setError(null); setMessage(null);
    try {
      await api.post(`/production/orders/${id}/unrelease`, {});
      setShowUnreleaseConfirm(false);
      setMessage("Reservasi dilepas — MO kembali PLANNED.");
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function issueMaterials() {
    if (!issueWarehouseId) { setError("Pilih gudang sumber issue dulu."); return; }
    setBusy(true); setError(null); setMessage(null);
    try {
      const allocations = mo!.material_allocations ?? [];
      const lines = allocations
        .filter((a) => {
          const inp = issueInputs[a.material_id];
          return inp && Number(inp.qty) > 0;
        })
        .map((a) => ({
          material_id: a.material_id,
          qty: Number(issueInputs[a.material_id].qty),
          uom_id: a.material?.use_uom_id ?? 1,
          roll_id: issueInputs[a.material_id].roll_id ? Number(issueInputs[a.material_id].roll_id) : undefined,
        }));

      if (lines.length === 0) { setError("Isi qty issue untuk minimal 1 material."); setBusy(false); return; }

      const res = await api.post<{ doc_no: string }>(`/production/orders/${id}/issues`, {
        warehouse_id: Number(issueWarehouseId),
        lines,
      });
      setMessage(`Material issue ${res.doc_no} diposting (BR-041/060).`);
      setIssueInputs({});
      loadIssues();
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  function loadIssues() {
    api.get<IssueRow[]>(`/production/orders/${id}/issues`)
      .then((r) => setIssues(Array.isArray(r) ? r : []))
      .catch(() => setIssues([]));
  }

  async function submitReturn() {
    if (!returnRollId || !returnWarehouseId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      await api.post(`/production/orders/${id}/rolls/${returnRollId}/return`, {
        warehouse_id: Number(returnWarehouseId),
        qty: returnQty ? Number(returnQty) : undefined,
        reason: returnReason || undefined,
      });
      setMessage("Sisa roll dikembalikan ke gudang (leftover BR-041).");
      setReturnRollId(""); setReturnQty(""); setReturnReason("");
      loadIssues();
    } catch (e: any) {
      setError(e.message);
    } finally { setBusy(false); }
  }

  if (error && !mo) return <p className="text-red-600">{error}</p>;
  if (!mo) return <p className="text-slate-500">Memuat…</p>;

  const fmt = (v: string | number) => Number(v).toLocaleString("id-ID", { maximumFractionDigits: 4 });
  const canIssue = ["RELEASED", "CUTTING", "SEWING"].includes(mo.status);
  const allocations = mo.material_allocations ?? [];
  const reservedMaterialCount = allocations.filter((allocation) => Number(allocation.qty_reserved) > Number(allocation.qty_issued)).length;
  const totalRemainingReservation = allocations.reduce((total, allocation) => total + Math.max(0, Number(allocation.qty_reserved) - Number(allocation.qty_issued)), 0);
  const returnableRolls = Array.from(
    new Map(
      (issues ?? [])
        .flatMap((iss) => (iss.lines ?? []).filter((l) => l.roll).map((l) => [l.roll!.id, { rollId: l.roll!.id, rollNo: l.roll!.roll_no }]))
    ).entries()
  ).map(([, v]) => v);

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
      {message && <p role="status" aria-live="polite" className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

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
            <button onClick={() => { setMessage(null); setShowUnreleaseConfirm(true); }} disabled={busy} className="rounded border border-amber-400 px-4 py-1.5 text-sm font-medium text-amber-700 disabled:opacity-50">
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

      {/* Alokasi material + issue (BR-060/041) */}
      <section className="overflow-x-auto rounded-xl border bg-white p-4">
        <div className="mb-2 flex items-center justify-between">
          <h2 className="font-semibold">Alokasi Material</h2>
          {canIssue && (
            <div className="flex items-center gap-2">
              <select value={issueWarehouseId} onChange={(e) => setIssueWarehouseId(e.target.value)} className="rounded border px-2 py-1 text-xs">
                <option value="">— gudang issue (RM) —</option>
                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code}</option>)}
              </select>
              <button onClick={issueMaterials} disabled={busy || !issueWarehouseId} className="rounded bg-blue-700 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50">
                {busy ? "Memproses…" : "Issue Terpilih"}
              </button>
            </div>
          )}
        </div>
        <table className="w-full min-w-[900px] text-sm">
          <thead className="border-b text-left text-xs text-slate-500">
            <tr>
              <th className="py-1">Material</th>
              <th className="py-1 text-right">Dibutuhkan</th>
              <th className="py-1 text-right">Reserved</th>
              <th className="py-1 text-right">Issued</th>
              <th className="py-1 text-right">Sisa Reservasi</th>
              <th className="py-1">Mode</th>
              {canIssue && <th className="py-1">Issue qty / roll</th>}
            </tr>
          </thead>
          <tbody>
            {allocations.map((a) => {
              const remaining = Number(a.qty_reserved) - Number(a.qty_issued);
              const isRoll = a.material?.tracking_level === "ROLL";
              const inp = issueInputs[a.material_id] ?? { qty: "", roll_id: "" };
              return (
                <tr key={a.material_id} className="border-b last:border-0">
                  <td className="py-1.5"><span className="font-mono">{a.material?.code}</span> {a.material?.name}</td>
                  <td className="py-1.5 text-right">{fmt(a.qty_required)}</td>
                  <td className="py-1.5 text-right">{fmt(a.qty_reserved)}</td>
                  <td className="py-1.5 text-right">{fmt(a.qty_issued)}</td>
                  <td className={`py-1.5 text-right ${remaining > 0 ? "font-medium" : "text-slate-400"}`}>{fmt(remaining)}</td>
                  <td className="py-1.5">{a.is_backflush ? <span className="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-700">Backflush</span> : <span className="text-xs text-slate-500">Aktual</span>}</td>
                  {canIssue && (
                    <td className="py-1.5">
                      {a.is_backflush ? (
                        <span className="text-xs text-slate-400">otomatis</span>
                      ) : remaining > 0 ? (
                        <div className="flex gap-1">
                          <input
                            type="number" step="any" min="0" max={remaining}
                            value={inp.qty}
                            onChange={(e) => setIssueInputs({ ...issueInputs, [a.material_id]: { ...inp, qty: e.target.value } })}
                            placeholder="qty"
                            className="w-24 rounded border px-2 py-1 text-xs"
                          />
                          {isRoll && (
                            <select
                              value={inp.roll_id}
                              onChange={(e) => setIssueInputs({ ...issueInputs, [a.material_id]: { ...inp, roll_id: e.target.value } })}
                              className="rounded border px-2 py-1 text-xs"
                            >
                              <option value="">roll *</option>
                              {(rollsByMaterial[a.material_id] ?? []).map((r) => (
                                <option key={r.id} value={r.id}>{r.roll_no} ({fmt(r.qty_remaining_meter)}m)</option>
                              ))}
                            </select>
                          )}
                        </div>
                      ) : (
                        <span className="text-xs text-green-600">✔ habis</span>
                      )}
                    </td>
                  )}
                </tr>
              );
            })}
            {allocations.length === 0 && (
              <tr><td colSpan={canIssue ? 7 : 6} className="py-4 text-center text-sm text-slate-500">Belum ada alokasi — release MO dulu.</td></tr>
            )}
          </tbody>
        </table>
        {canIssue && (
          <p className="mt-2 text-xs text-slate-500">
            BR-041: fabric wajib pilih roll (aktual terukur); trim backflush tidak perlu issue manual. BR-060: issue ≤ sisa reservasi.
          </p>
        )}
      </section>

      <section className="overflow-x-auto rounded-xl border bg-white p-4">
        <h2 className="mb-2 font-semibold">Riwayat Material Issue</h2>
        <table className="w-full min-w-[700px] text-sm">
          <thead className="border-b text-left text-xs text-slate-500">
            <tr><th className="py-1">Doc</th><th className="py-1">Mode</th><th className="py-1">Status</th><th className="py-1">Lines</th></tr>
          </thead>
          <tbody>
            {(issues ?? []).map((iss) => (
              <tr key={iss.id} className="border-b last:border-0">
                <td className="py-1.5 font-mono">{iss.doc_no}</td>
                <td className="py-1.5">{iss.mode}</td>
                <td className="py-1.5"><span className="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-700">{iss.status}</span></td>
                <td className="py-1.5">
                  {(iss.lines ?? []).map((l) => (
                    <div key={l.id}>{l.material?.code ?? l.material_id} - qty {l.qty ?? "?"}{l.roll ? ` (roll ${l.roll.roll_no})` : ""}</div>
                  ))}
                </td>
              </tr>
            ))}
            {issues && issues.length === 0 && <tr><td colSpan={4} className="py-3 text-center text-slate-500">Belum ada issue.</td></tr>}
          </tbody>
        </table>

        {canIssue && (
          <div className="mt-4 rounded-lg border bg-slate-50 p-3">
            <h3 className="text-sm font-semibold">Return sisa roll (leftover)</h3>
            <div className="mt-2 grid gap-2 sm:grid-cols-5">
              <select value={returnRollId} onChange={(e) => setReturnRollId(e.target.value)} className="rounded border px-2 py-1.5 text-sm" aria-label="Roll">
                <option value="">Roll *</option>
                {returnableRolls.map((r) => <option key={r.rollId} value={r.rollId}>{r.rollNo}</option>)}
              </select>
              <select value={returnWarehouseId} onChange={(e) => setReturnWarehouseId(e.target.value)} className="rounded border px-2 py-1.5 text-sm" aria-label="Gudang tujuan">
                <option value="">Gudang tujuan *</option>
                {warehouses.map((w) => <option key={w.id} value={w.id}>{w.code}</option>)}
              </select>
              <input type="number" step="any" min="0" value={returnQty} onChange={(e) => setReturnQty(e.target.value)} placeholder="Qty (kosong = semua)" className="rounded border px-2 py-1.5 text-sm" aria-label="Qty return" />
              <input value={returnReason} onChange={(e) => setReturnReason(e.target.value)} placeholder="Alasan (opsional)" className="rounded border px-2 py-1.5 text-sm" aria-label="Alasan return" />
              <button onClick={submitReturn} disabled={!returnRollId || !returnWarehouseId || busy} className="rounded bg-slate-900 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50">Return Roll</button>
            </div>
            <p className="mt-1 text-xs text-slate-500">Roll diambil dari riwayat issue MO ini. Qty kosong = kembalikan seluruh sisa roll.</p>
          </div>
        )}
      </section>

      <ConfirmDialog
        open={showUnreleaseConfirm}
        title="Unrelease Manufacturing Order?"
        description="Seluruh reservasi material yang masih aktif akan dilepas."
        confirmLabel="Unrelease MO"
        variant="danger"
        loading={busy}
        onConfirm={unrelease}
        onCancel={() => { if (!busy) setShowUnreleaseConfirm(false); }}
      >
        <div className="space-y-3">
          <dl className="grid grid-cols-2 gap-3 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-3 text-sm">
            <div><dt className="text-xs text-[var(--color-text-muted)]">Manufacturing order</dt><dd className="mt-0.5 font-mono font-semibold">{mo.doc_no}</dd></div>
            <div><dt className="text-xs text-[var(--color-text-muted)]">Status saat ini</dt><dd className="mt-0.5 font-semibold">{mo.status}</dd></div>
            <div><dt className="text-xs text-[var(--color-text-muted)]">Material dengan sisa reservasi</dt><dd className="mt-0.5 font-semibold tabular-nums">{reservedMaterialCount}</dd></div>
            <div><dt className="text-xs text-[var(--color-text-muted)]">Total sisa reservasi</dt><dd className="mt-0.5 font-semibold tabular-nums">{fmt(totalRemainingReservation)}</dd></div>
          </dl>
          <p className="text-sm text-[var(--color-danger)]">MO akan kembali ke status PLANNED dan material yang dilepas dapat tersedia untuk kebutuhan lain.</p>
        </div>
      </ConfirmDialog>
    </div>
  );
}
