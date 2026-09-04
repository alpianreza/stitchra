"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import { Button, ConfirmDialog, Input, MetricCard, PageHeader, StatusBadge } from "@/components/ui";

type Correction = {
  id: number;
  status: string;
  original_journal_id: number;
  original_amount: string;
  corrected_amount: string;
  adjustment_amount: string;
  currency: string;
  original_gl_period: string;
  period_state: string;
  correction_mode: string;
  reason: string;
  correction_version: number;
  reversal_journal_id: number | null;
  corrected_journal_id: number | null;
  adjustment_journal_id: number | null;
  approval_request?: { status: string };
};

type Result = { correction: Correction };
type ActionName = "approve" | "reject" | "post";

const ACTION_META: Record<
  ActionName,
  { label: string; title: string; description: string; variant: "primary" | "success" | "danger" }
> = {
  approve: {
    label: "Approve",
    title: "Approve koreksi?",
    description: "Koreksi disetujui dan dapat diposting setelahnya.",
    variant: "primary",
  },
  reject: {
    label: "Reject",
    title: "Reject koreksi?",
    description: "Koreksi akan ditolak. Tindakan ini tercatat dalam audit.",
    variant: "danger",
  },
  post: {
    label: "Post",
    title: "Post koreksi?",
    description: "Adjustment prospektif akan diposting ke GL.",
    variant: "success",
  },
};

const fmtAmt = (v: string) => Number(v).toLocaleString("id-ID", { minimumFractionDigits: 2, maximumFractionDigits: 4 });

export default function AccountingCorrectionPage() {
  const [journal, setJournal] = useState("");
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [version, setVersion] = useState("1");
  const [data, setData] = useState<Result | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmAction, setConfirmAction] = useState<ActionName | null>(null);

  async function request() {
    setBusy(true); setError(null);
    try {
      const r = await api.post<{ correction: Correction }>(
        `/finance/accounting-corrections/journals/${journal}`,
        { corrected_amount: Number(amount), reason, correction_version: Number(version) },
      );
      setData({ correction: r.correction });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Request failed");
    } finally { setBusy(false); }
  }

  async function runAction(name: ActionName) {
    if (!data) return;
    setBusy(true); setError(null);
    try {
      const r = await api.post<{ correction?: Correction }>(
        `/finance/accounting-corrections/${data.correction.id}/${name}`,
        {},
      );
      setData({ correction: r.correction ?? (r as unknown as Correction) });
      setConfirmAction(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Action failed");
      setConfirmAction(null);
    } finally { setBusy(false); }
  }

  const c = data?.correction;

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Finance"
        title="Accounting Correction"
        description="BR-109: OPEN = reversal + corrected repost; CLOSED = approved prospective adjustment."
      />

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <div className="grid gap-3 md:grid-cols-4">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Original Journal ID *</span>
            <Input value={journal} onChange={(e) => setJournal(e.target.value)} placeholder="mis. 42" />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Corrected Amount *</span>
            <Input type="number" min="0" step="0.0001" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="0.0000" />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Alasan *</span>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="alasan koreksi" />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Versi</span>
            <Input type="number" min="1" value={version} onChange={(e) => setVersion(e.target.value)} />
          </label>
        </div>
        <div className="mt-3">
          <Button onClick={request} loading={busy} disabled={busy || !journal || !amount || !reason}>Request Correction</Button>
        </div>
      </section>

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}

      {c && (
        <section className="space-y-3 rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <div className="flex items-center gap-2">
            <h2 className="font-semibold">Hasil koreksi</h2>
            <StatusBadge status={c.status} />
          </div>
          <div className="grid gap-3 md:grid-cols-4">
            <MetricCard label="Status" value={c.status} />
            <MetricCard
              label="Mode"
              value={c.correction_mode === "CLOSED_ADJUST" ? "PROSPECTIVE ADJUSTMENT" : "REVERSAL + CORRECTED REPOST"}
            />
            <MetricCard label="Original" value={`${c.currency} ${fmtAmt(c.original_amount)}`} />
            <MetricCard
              label="Corrected / Difference"
              value={`${c.currency} ${fmtAmt(c.corrected_amount)} / ${fmtAmt(c.adjustment_amount)}`}
            />
            <MetricCard label="Original Period" value={`${c.original_gl_period} - ${c.period_state}`} />
            <MetricCard
              label="Approval"
              value={c.approval_request?.status ?? (c.status === "NO_CHANGE" ? "NOT REQUIRED" : "PENDING")}
            />
            <MetricCard label="Version" value={`v${c.correction_version}`} />
            <MetricCard label="Reason" value={c.reason} />
          </div>
          <p className="text-sm text-[var(--color-text-muted)]">
            Original #{c.original_journal_id} - Reversal #{c.reversal_journal_id ?? "-"} - Corrected #
            {c.corrected_journal_id ?? "-"} - Adjustment #{c.adjustment_journal_id ?? "-"}
          </p>
          <div className="flex gap-2">
            <Button size="sm" disabled={busy || c.status !== "REQUESTED"} onClick={() => setConfirmAction("approve")}>
              Approve
            </Button>
            <Button size="sm" variant="danger" disabled={busy || c.status !== "APPROVED"} onClick={() => setConfirmAction("reject")}>
              Reject
            </Button>
            <Button size="sm" variant="success" disabled={busy || c.status !== "APPROVED"} onClick={() => setConfirmAction("post")}>
              Post
            </Button>
          </div>
          <ConfirmDialog
            open={confirmAction !== null}
            title={confirmAction ? ACTION_META[confirmAction].title : ""}
            description={confirmAction ? `Koreksi #${c.id} (${c.status}). ${ACTION_META[confirmAction].description}` : ""}
            confirmLabel={confirmAction ? ACTION_META[confirmAction].label : "OK"}
            variant={confirmAction ? ACTION_META[confirmAction].variant : "primary"}
            loading={busy}
            onConfirm={() => {
              if (confirmAction) runAction(confirmAction);
            }}
            onCancel={() => setConfirmAction(null)}
          />
        </section>
      )}
    </div>
  );
}