"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select, StatusBadge } from "@/components/ui";

interface Style { id: number; style_no: string }
interface Sample { id: number; doc_no: string; style_id: number; stage: string; version: number; buyer_status: string }
interface Approval { id: number; status: string; comment?: string | null; by_name?: string | null }

const STAGES = ["PROTO", "FIT", "PP", "TOP"];

/** Sample request & persetujuan buyer (PD). */
export default function SamplesPage() {
  const [styles, setStyles] = useState<Style[]>([]);
  const [styleId, setStyleId] = useState("");
  const [stage, setStage] = useState("PROTO");
  const [sample, setSample] = useState<Sample | null>(null);
  const [approvalStatus, setApprovalStatus] = useState("APPROVED");
  const [comment, setComment] = useState("");
  const [byName, setByName] = useState("");
  const [loadId, setLoadId] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ data: Style[] }>("/master/styles?per_page=200").then((r) => setStyles(r.data)).catch(() => {});
  }, []);

  async function create(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null);
    try {
      const s = await api.post<Sample>("/pd/samples", { style_id: Number(styleId), stage });
      setSample(s); setMessage(`Sample ${s.doc_no} (v${s.version}) dibuat - status buyer PENDING.`);
    } catch (x) { setError(x instanceof Error ? x.message : "Gagal membuat sample"); }
    finally { setBusy(false); }
  }

  async function loadById() {
    if (!loadId) return;
    setBusy(true); setError(null); setMessage(null);
    try { setSample(await api.get<Sample>(`/pd/samples/${loadId}`)); }
    catch (x) { setError(x instanceof Error ? x.message : "Gagal memuat sample"); }
    finally { setBusy(false); }
  }

  async function addApproval() {
    if (!sample) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const a = await api.post<Approval>(`/pd/samples/${sample.id}/approvals`, {
        status: approvalStatus, comment: comment || undefined, by_name: byName || undefined,
      });
      setSample({ ...sample, buyer_status: a.status });
      setComment("");
      setMessage(`Approval ${a.status} dicatat pada ${sample.doc_no}.`);
    } catch (x) { setError(x instanceof Error ? x.message : "Gagal mencatat approval"); }
    finally { setBusy(false); }
  }

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <PageHeader eyebrow="Product Development" title="Sample Request" description="Buat sample per stage (PROTO/FIT/PP/TOP) dan catat keputusan buyer." />

      {error && <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">{error}</div>}
      {message && <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">{message}</p>}

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Buat Sample</h2>
        <form onSubmit={create} className="mt-2 flex flex-wrap items-end gap-2">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Style *</span>
            <Select value={styleId} onChange={(e) => setStyleId(e.target.value)} required className="w-64">
              <option value="">- pilih style -</option>
              {styles.map((s) => <option key={s.id} value={s.id}>{s.style_no}</option>)}
            </Select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Stage *</span>
            <Select value={stage} onChange={(e) => setStage(e.target.value)} className="w-40">
              {STAGES.map((s) => <option key={s}>{s}</option>)}
            </Select>
          </label>
          <Button type="submit" loading={busy} disabled={!styleId}>Buat Sample</Button>
        </form>
        <div className="mt-3 flex flex-wrap items-end gap-2 border-t pt-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Muat sample by ID</span>
            <Input type="number" min="1" value={loadId} onChange={(e) => setLoadId(e.target.value)} className="w-36" />
          </label>
          <Button variant="secondary" loading={busy} disabled={!loadId} onClick={loadById}>Muat</Button>
        </div>
      </section>

      {sample && (
        <section className="space-y-3 rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <div className="flex items-center justify-between gap-2">
            <div>
              <p className="font-mono text-lg font-bold">{sample.doc_no}</p>
              <p className="text-xs text-[var(--color-text-muted)]">v{sample.version} - stage {sample.stage}</p>
            </div>
            <StatusBadge status={sample.buyer_status} />
          </div>
          <h3 className="text-sm font-semibold">Catat keputusan buyer</h3>
          <div className="grid gap-2 sm:grid-cols-3">
            <Select value={approvalStatus} onChange={(e) => setApprovalStatus(e.target.value)}>
              <option value="APPROVED">APPROVED</option>
              <option value="REJECTED">REJECTED</option>
              <option value="COMMENTED">COMMENTED</option>
            </Select>
            <Input value={byName} onChange={(e) => setByName(e.target.value)} placeholder="Nama buyer (opsional)" />
            <Input value={comment} onChange={(e) => setComment(e.target.value)} placeholder="Komentar (opsional)" />
          </div>
          <Button variant="success" loading={busy} onClick={addApproval}>Simpan Approval</Button>
        </section>
      )}
    </div>
  );
}