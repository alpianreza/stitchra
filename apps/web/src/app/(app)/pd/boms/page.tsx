"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select, StatusBadge } from "@/components/ui";

interface Style { id: number; style_no: string }
interface Material { id: number; code: string; name: string; type: string }
interface Uom { id: number; code: string }
interface Colorway { id: number; style_id: number }
interface BomVersion { id: number; version_no: number; status: string }
interface Line {
  material_id: string; uom_id: string; qty_per_pcs: string; wastage_pct: string; shrinkage_pct: string;
  consumption_estimated: string; is_backflush: boolean; backflush_stage: string; colorway_id: string;
}

const STAGES = ["CUT_OUTPUT", "SEWING_FINAL_OUT", "FINISHING_OUT", "QC_FINAL_PASS", "PACKED_QTY", "FG_RECEIVED_QTY", "SHIPPED_QTY"];
const blank = (): Line => ({
  material_id: "", uom_id: "", qty_per_pcs: "", wastage_pct: "0", shrinkage_pct: "0",
  consumption_estimated: "", is_backflush: false, backflush_stage: "", colorway_id: "",
});
const cls = "w-full";

/** BOM Editor - BR-030 / BR-066: ACTUAL/BACKFLUSH exclusive per material. */
export default function BomEditorPage() {
  const [styles, setStyles] = useState<Style[]>([]);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [uoms, setUoms] = useState<Uom[]>([]);
  const [colorways, setColorways] = useState<Colorway[]>([]);
  const [styleId, setStyleId] = useState("");
  const [lines, setLines] = useState<Line[]>([blank()]);
  const [created, setCreated] = useState<BomVersion | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [loadId, setLoadId] = useState("");
  const [editingId, setEditingId] = useState<number | null>(null);

  useEffect(() => {
    api.get<{ data: Style[] }>("/master/styles?per_page=200").then((r) => setStyles(r.data)).catch(() => {});
    api.get<{ data: Material[] }>("/master/materials?per_page=500").then((r) => setMaterials(r.data)).catch(() => {});
    api.get<{ data: Uom[] }>("/master/uoms?per_page=100").then((r) => setUoms(r.data)).catch(() => {});
    api.get<{ data: Colorway[] }>("/master/colorways?per_page=500").then((r) => setColorways(r.data)).catch(() => {});
  }, []);

  async function loadBom() {
    if (!loadId) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const v = await api.get<BomVersion & { style_id?: number; lines?: Array<Record<string, unknown>> }>(`/pd/boms/${loadId}`);
      const loaded: Line[] = (v.lines ?? []).map((raw) => {
        const l = raw as Record<string, unknown>;
        return {
          material_id: String(l.material_id ?? ""),
          uom_id: String(l.uom_id ?? ""),
          qty_per_pcs: String(l.qty_per_pcs ?? "0"),
          wastage_pct: String(l.wastage_pct ?? "0"),
          shrinkage_pct: String(l.shrinkage_pct ?? "0"),
          consumption_estimated: l.consumption_estimated === null || l.consumption_estimated === undefined ? "" : String(l.consumption_estimated),
          is_backflush: Boolean(l.is_backflush),
          backflush_stage: String(l.backflush_stage ?? ""),
          colorway_id: l.colorway_id === null || l.colorway_id === undefined ? "" : String(l.colorway_id),
        };
      });
      if (v.style_id) setStyleId(String(v.style_id));
      setLines(loaded.length > 0 ? loaded : [blank()]);
      setCreated(null);
      setEditingId(v.id);
      setMessage(`BOM #${v.id} (v${v.version_no}, ${v.status}) dimuat. Simpan perubahan lalu submit ke approval.`);
      setLoadId("");
    } catch (x) {
      setError(x instanceof Error ? x.message : "Gagal memuat BOM");
    } finally { setBusy(false); }
  }
  function setLine(i: number, k: keyof Line, v: string | boolean) {
    setLines((prev) =>
      prev.map((row, idx) => {
        if (idx !== i) return row;
        const next: Line = { ...row, [k]: v };
        if (k === "is_backflush" && !v) next.backflush_stage = "";
        return next;
      }),
    );
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      const payloadLines = lines.map((l) => ({
        material_id: Number(l.material_id),
        uom_id: Number(l.uom_id),
        qty_per_pcs: Number(l.qty_per_pcs),
        wastage_pct: Number(l.wastage_pct) || 0,
        shrinkage_pct: Number(l.shrinkage_pct) || 0,
        consumption_estimated: l.consumption_estimated ? Number(l.consumption_estimated) : undefined,
        is_backflush: l.is_backflush,
        backflush_stage: l.is_backflush ? l.backflush_stage : undefined,
        colorway_id: l.colorway_id ? Number(l.colorway_id) : undefined,
      }));
      if (editingId !== null) {
        const v = await api.put<BomVersion>(`/pd/boms/${editingId}`, { lines: payloadLines });
        setCreated(v);
        setMessage(`BOM v${v.version_no} diperbarui (DRAFT).`);
      } else {
        const v = await api.post<BomVersion>("/pd/boms", { style_id: Number(styleId), lines: payloadLines });
        setCreated(v);
        setMessage(`BOM v${v.version_no} dibuat.`);
      }
    } catch (x) {
      setError(x instanceof Error ? x.message : "Gagal menyimpan BOM");
    } finally { setBusy(false); }
  }
  async function submit() {
    if (!created) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/pd/boms/${created.id}/submit`, {});
      setMessage(`BOM v${created.version_no} masuk approval.`);
      setCreated(null); setLines([blank()]); setStyleId("");
    } catch (x) {
      setError(x instanceof Error ? x.message : "Gagal submit BOM");
    } finally { setBusy(false); }
  }

  const cw = colorways.filter((c) => c.style_id === Number(styleId));

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Product Development"
        title="BOM Editor"
        description="BR-030 / BR-066"
      />

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}
      {message && (
        <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">
          {message}
        </p>
      )}

      <div className="flex flex-wrap items-end gap-2 rounded-[var(--radius-surface)] border bg-white p-3 shadow-[var(--shadow-raised)]">
        <label className="text-sm">
          <span className="mb-1 block font-medium">Muat BOM existing (ID)</span>
          <Input type="number" min="1" value={loadId} onChange={(e) => setLoadId(e.target.value)} placeholder="mis. 3" className="w-40" />
        </label>
        <Button type="button" variant="secondary" loading={busy} disabled={!loadId} onClick={loadBom}>Muat & Edit</Button>
      </div>
      <div className="rounded-[var(--radius-surface)] border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
        <b>BR-066:</b> ACTUAL/BACKFLUSH exclusive per material; fabric wajib ACTUAL; BACKFLUSH wajib satu Named Stage.
      </div>

      <form onSubmit={save} className="space-y-4 rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <label className="block text-sm">
          <span className="mb-1 block font-medium">Style *</span>
          <Select value={styleId} onChange={(e) => setStyleId(e.target.value)} required disabled={!!created || editingId !== null} className={cls}>
            <option value="">- pilih style -</option>
            {styles.map((s) => (
              <option key={s.id} value={s.id}>{s.style_no}</option>
            ))}
          </Select>
        </label>

        <div className="flex items-center justify-between">
          <b>Lines</b>
          <Button type="button" size="sm" variant="secondary" onClick={() => setLines([...lines, blank()])} disabled={!!created}>
            + Baris
          </Button>
        </div>

        <div className="overflow-x-auto">
          <table className="min-w-[1250px] text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                <th className="py-2 pr-2">Material *</th>
                <th className="py-2 pr-2">Colorway</th>
                <th className="py-2 pr-2">Qty/pcs *</th>
                <th className="py-2 pr-2">UOM *</th>
                <th className="py-2 pr-2">Waste%</th>
                <th className="py-2 pr-2">Shrink%</th>
                <th className="py-2 pr-2">Est.</th>
                <th className="py-2 pr-2">Method</th>
                <th className="py-2 pr-2">Named Stage</th>
                <th className="py-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={i} className="border-b align-top last:border-0">
                  <td className="py-2 pr-2">
                    <Select value={l.material_id} onChange={(e) => setLine(i, "material_id", e.target.value)} required disabled={!!created || editingId !== null} className={cls}>
                      <option value="">-</option>
                      {materials.map((m) => (
                        <option key={m.id} value={m.id}>{m.code} - {m.name} ({m.type})</option>
                      ))}
                    </Select>
                  </td>
                  <td className="py-2 pr-2">
                    <Select value={l.colorway_id} onChange={(e) => setLine(i, "colorway_id", e.target.value)} disabled={!!created} className={cls}>
                      <option value="">Semua</option>
                      {cw.map((c) => (
                        <option key={c.id} value={c.id}>CW-{c.id}</option>
                      ))}
                    </Select>
                  </td>
                  <td className="py-2 pr-2">
                    <Input type="number" step="any" value={l.qty_per_pcs} onChange={(e) => setLine(i, "qty_per_pcs", e.target.value)} required className={cls} />
                  </td>
                  <td className="py-2 pr-2">
                    <Select value={l.uom_id} onChange={(e) => setLine(i, "uom_id", e.target.value)} required className={cls}>
                      <option value="">-</option>
                      {uoms.map((u) => (
                        <option key={u.id} value={u.id}>{u.code}</option>
                      ))}
                    </Select>
                  </td>
                  {(["wastage_pct", "shrinkage_pct", "consumption_estimated"] as const).map((k) => (
                    <td key={k} className="py-2 pr-2">
                      <Input type="number" step="any" value={l[k]} onChange={(e) => setLine(i, k, e.target.value)} className={cls} />
                    </td>
                  ))}
                  <td className="py-2 pr-2">
                    <label className="flex items-center gap-1.5 text-xs">
                      <input
                        type="checkbox"
                        checked={l.is_backflush}
                        onChange={(e) => setLine(i, "is_backflush", e.target.checked)}
                        disabled={!!created}
                      />
                      {l.is_backflush ? "BACKFLUSH" : "ACTUAL"}
                    </label>
                  </td>
                  <td className="py-2 pr-2">
                    <Select
                      value={l.backflush_stage}
                      onChange={(e) => setLine(i, "backflush_stage", e.target.value)}
                      required={l.is_backflush}
                      disabled={!l.is_backflush || !!created}
                      className={cls}
                    >
                      <option value="">-</option>
                      {STAGES.map((s) => (
                        <option key={s}>{s}</option>
                      ))}
                    </Select>
                  </td>
                  <td className="py-2">
                    <button
                      type="button"
                      onClick={() => setLines(lines.filter((_, x) => x !== i))}
                      disabled={lines.length === 1}
                      className="text-sm text-[var(--color-danger)] disabled:opacity-30"
                      aria-label="Hapus baris"
                    >
                      x
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {editingId !== null ? (
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-sm text-[var(--color-text-muted)]">Mode edit BOM #{editingId} - simpan perubahan lalu submit ke approval.</span>
            <Button type="button" variant="ghost" onClick={() => { setEditingId(null); setCreated(null); setLines([blank()]); }}>Keluar mode edit</Button>
          </div>
        ) : !created ? (
          <Button type="submit" loading={busy} disabled={busy || !styleId}>Simpan Versi BOM</Button>
        ) : (
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-sm text-[var(--color-text-muted)]">
              Draf BOM v{created.version_no} siap - <StatusBadge status={created.status} />
            </span>
            <Button type="button" variant="success" loading={busy} onClick={submit}>Submit ke Approval</Button>
          </div>
        )}
      </form>
    </div>
  );
}