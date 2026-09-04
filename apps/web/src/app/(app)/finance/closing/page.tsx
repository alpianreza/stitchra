"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, StatusBadge } from "@/components/ui";

interface CloseRun { id: number; status?: string; period?: string }
interface FxRun { id: number; status?: string; period?: string }

function GenericView({ data }: { data: unknown }) {
  if (data === null || data === undefined) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
  if (Array.isArray(data)) {
    if (data.length === 0) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
    if (typeof data[0] === "object" && data[0] !== null) {
      const keys = Object.keys(data[0] as Record<string, unknown>);
      return (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                {keys.map((k) => <th key={k} className="py-1.5 pr-3">{k}</th>)}
              </tr>
            </thead>
            <tbody>
              {data.map((row, i) => (
                <tr key={i} className="border-b last:border-0">
                  {keys.map((k) => (
                    <td key={k} className="py-1.5 pr-3">{String((row as Record<string, unknown>)[k] ?? "-")}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      );
    }
    return <ul className="list-disc space-y-0.5 pl-5 text-sm">{data.map((x, i) => <li key={i}>{String(x)}</li>)}</ul>;
  }
  if (typeof data === "object") {
    return (
      <dl className="grid gap-2 text-sm sm:grid-cols-2">
        {Object.entries(data as Record<string, unknown>).map(([k, v]) => (
          <div key={k}>
            <dt className="text-xs text-[var(--color-text-muted)]">{k}</dt>
            <dd className="font-medium">{typeof v === "object" ? JSON.stringify(v) : String(v)}</dd>
          </div>
        ))}
      </dl>
    );
  }
  return <p className="text-sm">{String(data)}</p>;
}

/** Tutup buku: period close (prepare/approve/close), FX revaluation, dan trial balance. */
export default function ClosingPage() {
  const [period, setPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [backupVerified, setBackupVerified] = useState(false);
  const [taxReviewed, setTaxReviewed] = useState(false);
  const [notes, setNotes] = useState("");
  const [runId, setRunId] = useState("");
  const [runStatus, setRunStatus] = useState<string | null>(null);
  const [fxRunId, setFxRunId] = useState("");
  const [fxStatus, setFxStatus] = useState<string | null>(null);
  const [tbPeriod, setTbPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [tbData, setTbData] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  async function run(fn: () => Promise<void>) {
    setBusy(true); setError(null); setMessage(null);
    try { await fn(); } catch (e) { setError(e instanceof Error ? e.message : "Gagal"); } finally { setBusy(false); }
  }

  const prepare = () => run(async () => {
    const r = await api.post<CloseRun>("/finance/period-close/prepare", {
      period, backup_verified: backupVerified, tax_reviewed: taxReviewed, notes: notes || undefined,
    });
    setRunId(String(r.id)); setRunStatus(r.status ?? "PREPARED");
    setMessage(`Period close #${r.id} disiapkan - approve lalu close.`);
  });

  const act = (action: "approve" | "close") => run(async () => {
    const r = await api.post<CloseRun>(`/finance/period-close/${runId}/${action}`, {});
    setRunStatus(r.status ?? action.toUpperCase());
    setMessage(`Period close #${runId}: ${r.status ?? action}.`);
  });

  const fxRun = () => run(async () => {
    const r = await api.post<FxRun>("/finance/fx-revaluations", { period });
    setFxRunId(String(r.id)); setFxStatus(r.status ?? "RUN");
    setMessage(`FX revaluation #${r.id} dijalankan untuk periode ${period}.`);
  });

  const fxReverse = () => run(async () => {
    const r = await api.post<FxRun>(`/finance/fx-revaluations/${fxRunId}/reverse`, {});
    setFxStatus(r.status ?? "REVERSED");
    setMessage(`FX revaluation #${fxRunId} direversal.`);
  });

  const loadTb = () => run(async () => {
    const r = await api.get<{ period: string; data: unknown }>(`/finance/trial-balance?period=${tbPeriod}`);
    setTbData(r.data);
    setMessage(`Trial balance periode ${r.period} dimuat.`);
  });

  const periodPattern = "\\d{4}-(0[1-9]|1[0-2])";

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Finance"
        title="Tutup Buku & FX"
        description="Period close terkontrol (prepare → approve → close), revaluasi kurs, dan trial balance."
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

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Period Close</h2>
        <div className="mt-2 grid gap-3 md:grid-cols-3">
          <label className="block text-sm">
            <span className="mb-1 block font-medium">Periode (YYYY-MM) *</span>
            <Input value={period} onChange={(e) => setPeriod(e.target.value)} pattern={periodPattern} placeholder="2026-09" />
          </label>
          <label className="flex items-end gap-2 pb-2 text-sm">
            <input type="checkbox" checked={backupVerified} onChange={(e) => setBackupVerified(e.target.checked)} />
            Backup terverifikasi
          </label>
          <label className="flex items-end gap-2 pb-2 text-sm">
            <input type="checkbox" checked={taxReviewed} onChange={(e) => setTaxReviewed(e.target.checked)} />
            Pajak sudah direview
          </label>
        </div>
        <label className="mt-2 block text-sm">
          <span className="mb-1 block font-medium">Catatan</span>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="opsional" />
        </label>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Button loading={busy} disabled={!period || !backupVerified || !taxReviewed} onClick={prepare}>Prepare</Button>
          <Input value={runId} onChange={(e) => setRunId(e.target.value.replace(/\D/g, ""))} placeholder="Run ID" className="w-32" aria-label="Period close run ID" />
          <Button variant="secondary" loading={busy} disabled={!runId} onClick={() => act("approve")}>Approve</Button>
          <Button variant="danger" loading={busy} disabled={!runId} onClick={() => act("close")}>Close</Button>
          {runStatus && <StatusBadge status={runStatus} />}
        </div>
      </section>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">FX Revaluation</h2>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Input value={period} onChange={(e) => setPeriod(e.target.value)} pattern={periodPattern} placeholder="YYYY-MM" className="w-40" aria-label="Periode FX" />
          <Button loading={busy} disabled={!period} onClick={fxRun}>Run Revaluation</Button>
          <Input value={fxRunId} onChange={(e) => setFxRunId(e.target.value.replace(/\D/g, ""))} placeholder="Run ID" className="w-32" aria-label="FX run ID" />
          <Button variant="danger" loading={busy} disabled={!fxRunId} onClick={fxReverse}>Reverse</Button>
          {fxStatus && <StatusBadge status={fxStatus} />}
        </div>
      </section>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-semibold">Trial Balance</h2>
          <Input value={tbPeriod} onChange={(e) => setTbPeriod(e.target.value)} pattern={periodPattern} placeholder="YYYY-MM" className="ml-auto w-40" aria-label="Periode trial balance" />
          <Button variant="secondary" loading={busy} disabled={!tbPeriod} onClick={loadTb}>Muat</Button>
        </div>
        <div className="mt-3">{tbData !== null && <GenericView data={tbData} />}</div>
      </section>
    </div>
  );
}