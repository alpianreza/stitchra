"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { api } from "@/lib/api";

interface Roll { id: number; roll_no: string; qty_buy: string; qty_meter_actual: string; status: string }
interface GrLine {
  id: number; material_id: number; qty_received: string; uom_id: number; unit_price: string; status: string;
  material?: { code: string; name: string; tracking_level: string };
  rolls?: Roll[];
}
interface GrDetail {
  id: number; doc_no: string; warehouse_id: number; status: string;
  purchase_order?: { doc_no: string; supplier?: { name: string } };
  lines: GrLine[];
}
interface GrListItem { id: number; doc_no: string; status: string; purchase_order?: { doc_no: string } }
interface Defect { id: number; code: string; name: string; severity: string }

interface LineResult {
  result: "PASS" | "FAIL";
  four_point_points: string; shrinkage_pct_actual: string; gsm_actual: string;
  shade_verdict: string; defect_id: string;
}

const emptyResult = (): LineResult => ({ result: "PASS", four_point_points: "", shrinkage_pct_actual: "", gsm_actual: "", shade_verdict: "", defect_id: "" });

/** Inward QC (FQC) — PASS → release quality hold (BR-004); FAIL → rejected */
export default function InwardInspectionPage() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const [grs, setGrs] = useState<GrListItem[]>([]);
  const [grId, setGrId] = useState(searchParams.get("gr") ?? "");
  const [gr, setGr] = useState<GrDetail | null>(null);
  const [defects, setDefects] = useState<Defect[]>([]);
  const [results, setResults] = useState<Record<number, LineResult>>({});   // per roll (fabric) / per line (lot)
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get<{ data: GrListItem[] }>("/receiving/grs?per_page=100")
      .then((r) => setGrs(r.data.filter((g) => g.status === "POSTED")))
      .catch((e) => setError(e.message));
    api.get<{ data: Defect[] }>("/master/defect-library?per_page=200").then((r) => setDefects(r.data)).catch(() => {});
  }, []);

  useEffect(() => {
    if (!grId) { setGr(null); return; }
    api.get<GrDetail>(`/receiving/grs/${grId}`).then((detail) => {
      setGr(detail);
      const init: Record<number, LineResult> = {};
      for (const line of detail.lines) {
        if (line.rolls && line.rolls.length > 0) {
          for (const roll of line.rolls) init[roll.id] = emptyResult();
        } else {
          init[line.id] = emptyResult();
        }
      }
      setResults(init);
    }).catch((e) => setError(e.message));
  }, [grId]);

  function setRes(key: number, field: keyof LineResult, value: string) {
    setResults({ ...results, [key]: { ...results[key], [field]: value } });
  }

  async function submitInspection() {
    if (!gr) return;
    setBusy(true); setError(null);
    try {
      // 1. Buat inspeksi (dokumentasi pengukuran per roll/line)
      const inspectionLines: any[] = [];
      for (const line of gr.lines) {
        if (line.rolls && line.rolls.length > 0) {
          for (const roll of line.rolls) {
            const r = results[roll.id];
            inspectionLines.push({
              gr_line_id: line.id, roll_id: roll.id,
              four_point_points: r.four_point_points ? Number(r.four_point_points) : undefined,
              shrinkage_pct_actual: r.shrinkage_pct_actual ? Number(r.shrinkage_pct_actual) : undefined,
              gsm_actual: r.gsm_actual ? Number(r.gsm_actual) : undefined,
              shade_verdict: r.shade_verdict || undefined,
              defect_id: r.defect_id ? Number(r.defect_id) : undefined,
              result: r.result,
            });
          }
        } else {
          const r = results[line.id];
          inspectionLines.push({
            gr_line_id: line.id,
            four_point_points: r.four_point_points ? Number(r.four_point_points) : undefined,
            shrinkage_pct_actual: r.shrinkage_pct_actual ? Number(r.shrinkage_pct_actual) : undefined,
            defect_id: r.defect_id ? Number(r.defect_id) : undefined,
            result: r.result,
          });
        }
      }

      const insp = await api.post<{ id: number }>(`/receiving/grs/${gr.id}/inspections`, { lines: inspectionLines });

      // 2. Finalize: release/reject per roll/line (BR-004)
      const finalizeLines: any[] = [];
      for (const line of gr.lines) {
        if (line.rolls && line.rolls.length > 0) {
          for (const roll of line.rolls) {
            finalizeLines.push({
              gr_line_id: line.id, roll_id: roll.id, result: results[roll.id].result,
              material_id: line.material_id, warehouse_id: gr.warehouse_id,
              qty: Number(roll.qty_buy), uom_id: line.uom_id,
            });
          }
        } else {
          finalizeLines.push({
            gr_line_id: line.id, result: results[line.id].result,
            material_id: line.material_id, warehouse_id: gr.warehouse_id,
            qty: Number(line.qty_received), uom_id: line.uom_id,
          });
        }
      }

      await api.post(`/receiving/inspections/${insp.id}/finalize`, { lines: finalizeLines });

      router.push("/receiving/grs?inspected=1");
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  const input = "rounded border px-2 py-1 text-xs";

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Inward QC (FQC) <span className="text-sm font-normal text-slate-500">— release quality hold (BR-004)</span></h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}

      <label className="block max-w-md text-sm">
        <span className="mb-1 block font-medium">GR (status POSTED) *</span>
        <select value={grId} onChange={(e) => setGrId(e.target.value)} className="w-full rounded border px-2 py-1.5 text-sm">
          <option value="">— pilih GR —</option>
          {grs.map((g) => <option key={g.id} value={g.id}>{g.doc_no} (PO {g.purchase_order?.doc_no})</option>)}
        </select>
      </label>

      {gr && (
        <div className="space-y-3">
          <p className="text-sm text-slate-600">GR <span className="font-mono font-medium">{gr.doc_no}</span> — {gr.purchase_order?.supplier?.name}</p>

          {gr.lines.map((line) => (
            <section key={line.id} className="rounded-xl border bg-white p-4">
              <h2 className="mb-2 text-sm font-semibold">
                <span className="font-mono">{line.material?.code}</span> {line.material?.name} — diterima {Number(line.qty_received)}
              </h2>

              {(line.rolls && line.rolls.length > 0 ? line.rolls : [null]).map((roll) => {
                const key = roll ? roll.id : line.id;
                const r = results[key];
                if (!r) return null;
                return (
                  <div key={key} className="mb-2 grid grid-cols-7 items-end gap-2 rounded-lg border bg-slate-50 p-3">
                    <div className="col-span-1 text-xs">
                      <span className="block font-medium">{roll ? `Roll ${roll.roll_no}` : "Line (lot)"}</span>
                      <span className="text-slate-500">{roll ? `${Number(roll.qty_buy)} beli / ${Number(roll.qty_meter_actual)} m` : `${Number(line.qty_received)} pcs`}</span>
                    </div>
                    <label className="text-xs">
                      <span className="mb-0.5 block">4-point</span>
                      <input type="number" step="any" min="0" value={r.four_point_points} onChange={(e) => setRes(key, "four_point_points", e.target.value)} className={input} />
                    </label>
                    <label className="text-xs">
                      <span className="mb-0.5 block">Shrinkage %</span>
                      <input type="number" step="any" value={r.shrinkage_pct_actual} onChange={(e) => setRes(key, "shrinkage_pct_actual", e.target.value)} className={input} />
                    </label>
                    <label className="text-xs">
                      <span className="mb-0.5 block">GSM aktual</span>
                      <input type="number" step="any" min="0" value={r.gsm_actual} onChange={(e) => setRes(key, "gsm_actual", e.target.value)} className={input} />
                    </label>
                    <label className="text-xs">
                      <span className="mb-0.5 block">Shade</span>
                      <select value={r.shade_verdict} onChange={(e) => setRes(key, "shade_verdict", e.target.value)} className={input}>
                        <option value="">—</option>
                        <option value="MATCH">MATCH</option>
                        <option value="DEVIATION">DEVIATION</option>
                      </select>
                    </label>
                    <label className="text-xs">
                      <span className="mb-0.5 block">Defect (bila FAIL)</span>
                      <select value={r.defect_id} onChange={(e) => setRes(key, "defect_id", e.target.value)} className={input}>
                        <option value="">—</option>
                        {defects.map((d) => <option key={d.id} value={d.id}>[{d.severity}] {d.name}</option>)}
                      </select>
                    </label>
                    <div className="flex gap-1">
                      {(["PASS", "FAIL"] as const).map((v) => (
                        <button
                          key={v}
                          type="button"
                          onClick={() => setRes(key, "result", v)}
                          className={`flex-1 rounded py-1.5 text-xs font-bold ${r.result === v ? (v === "PASS" ? "bg-green-600 text-white" : "bg-red-600 text-white") : "bg-slate-200"}`}
                        >
                          {v}
                        </button>
                      ))}
                    </div>
                  </div>
                );
              })}
            </section>
          ))}

          <button onClick={submitInspection} disabled={busy} className="rounded bg-slate-900 px-6 py-2 font-medium text-white disabled:opacity-50">
            {busy ? "Memproses…" : "Simpan Inspeksi + Finalize (release/reject)"}
          </button>
        </div>
      )}
    </div>
  );
}
