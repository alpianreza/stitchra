"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import {
  Button,
  DataTable,
  Modal,
  PageHeader,
  StatusBadge,
  type DataTableColumn,
} from "@/components/ui";

interface PendingItem {
  id: number;
  doc_type: string;
  doc_id: number;
  current_step: number;
  submitted_at: string;
  step_role: string;
  submitted_by_name: string | null;
}

type Decision = "approve" | "reject" | "revision";

const decisionLabels: Record<Decision, string> = {
  approve: "Approve",
  reject: "Reject",
  revision: "Minta Revisi",
};

const decisionDescriptions: Record<Decision, string> = {
  approve: "Dokumen akan dilanjutkan sesuai approval flow yang berlaku.",
  reject: "Dokumen akan ditolak. Alasan penolakan wajib diberikan.",
  revision: "Dokumen akan dikembalikan untuk direvisi. Alasan revisi wajib diberikan.",
};

export default function ApprovalsPage() {
  const [items, setItems] = useState<PendingItem[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedItem, setSelectedItem] = useState<PendingItem | null>(null);
  const [decision, setDecision] = useState<Decision | null>(null);
  const [note, setNote] = useState("");
  const [decisionError, setDecisionError] = useState<string | null>(null);
  const [acting, setActing] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setLoadError(null);
    api.get<{ data: PendingItem[] }>("/approvals/pending")
      .then((response) => setItems(response.data))
      .catch((error) => setLoadError(error.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  function openDecision(item: PendingItem, nextDecision: Decision) {
    setSelectedItem(item);
    setDecision(nextDecision);
    setNote("");
    setDecisionError(null);
    setMessage(null);
  }

  function closeDecision() {
    if (acting) return;
    setSelectedItem(null);
    setDecision(null);
    setNote("");
    setDecisionError(null);
  }

  async function submitDecision() {
    if (!selectedItem || !decision) return;
    const trimmedNote = note.trim();
    if (decision !== "approve" && !trimmedNote) {
      setDecisionError(decision === "reject" ? "Alasan penolakan wajib diisi." : "Alasan revisi wajib diisi.");
      return;
    }

    setActing(true);
    setDecisionError(null);
    try {
      await api.post(`/approvals/${selectedItem.id}/${decision}`, { note: trimmedNote || undefined });
      const completedLabel = decisionLabels[decision];
      const documentLabel = `${selectedItem.doc_type} #${selectedItem.doc_id}`;
      closeDecisionAfterSuccess();
      setMessage(`${documentLabel} berhasil diproses: ${completedLabel}.`);
      load();
    } catch (error: any) {
      setDecisionError(error.message);
    } finally {
      setActing(false);
    }
  }

  function closeDecisionAfterSuccess() {
    setSelectedItem(null);
    setDecision(null);
    setNote("");
    setDecisionError(null);
  }

  const columns: DataTableColumn<PendingItem>[] = [
    {
      key: "document",
      header: "Dokumen",
      cell: (item) => (
        <div>
          <p className="font-mono font-semibold">{item.doc_type} #{item.doc_id}</p>
          <p className="text-xs text-[var(--color-text-muted)]">Approval step {item.current_step}</p>
        </div>
      ),
    },
    { key: "role", header: "Role Step", cell: (item) => item.step_role },
    { key: "submitter", header: "Diajukan oleh", cell: (item) => item.submitted_by_name ?? "—" },
    {
      key: "time",
      header: "Waktu",
      cell: (item) => new Date(item.submitted_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }),
    },
    {
      key: "actions",
      header: "Keputusan",
      headerClassName: "text-right",
      className: "text-right",
      cell: (item) => (
        <div className="flex justify-end gap-1.5">
          <Button size="sm" variant="success" onClick={() => openDecision(item, "approve")}>Approve</Button>
          <Button size="sm" onClick={() => openDecision(item, "revision")}>Revisi</Button>
          <Button size="sm" variant="danger" onClick={() => openDecision(item, "reject")}>Reject</Button>
        </div>
      ),
    },
  ];

  const decisionVariant = decision === "approve" ? "success" : decision === "reject" ? "danger" : "primary";
  const noteRequired = decision !== null && decision !== "approve";

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Workspace"
        title="Approval Menunggu Saya"
        description="Tinjau konteks dokumen dan berikan keputusan sesuai role approval aktif."
      />

      {message && (
        <div role="status" aria-live="polite" className="rounded-[var(--radius-surface)] border border-green-200 bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">
          {message}
        </div>
      )}

      <DataTable
        caption="Daftar approval menunggu"
        columns={columns}
        rows={items}
        getRowKey={(item) => item.id}
        loading={loading}
        error={loadError}
        onRetry={load}
        emptyTitle="Tidak ada approval menunggu"
        emptyDescription="Semua dokumen yang menjadi tanggung jawab Anda sudah diproses."
        minWidth="860px"
        mobileCard={(item) => (
          <article className="space-y-3 p-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="font-mono font-semibold">{item.doc_type} #{item.doc_id}</p>
                <p className="mt-0.5 text-xs text-[var(--color-text-muted)]">Step {item.current_step} · {item.step_role}</p>
              </div>
              <StatusBadge status="PENDING" label="Menunggu" />
            </div>
            <dl className="grid grid-cols-2 gap-3 text-xs">
              <div><dt className="text-[var(--color-text-muted)]">Diajukan oleh</dt><dd className="mt-0.5 font-medium">{item.submitted_by_name ?? "—"}</dd></div>
              <div><dt className="text-[var(--color-text-muted)]">Waktu</dt><dd className="mt-0.5 font-medium">{new Date(item.submitted_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })}</dd></div>
            </dl>
            <div className="grid grid-cols-3 gap-2">
              <Button size="sm" variant="success" onClick={() => openDecision(item, "approve")}>Approve</Button>
              <Button size="sm" onClick={() => openDecision(item, "revision")}>Revisi</Button>
              <Button size="sm" variant="danger" onClick={() => openDecision(item, "reject")}>Reject</Button>
            </div>
          </article>
        )}
      />

      <Modal
        open={Boolean(selectedItem && decision)}
        onClose={closeDecision}
        closeDisabled={acting}
        title={decision ? `${decisionLabels[decision]} Dokumen` : "Keputusan Approval"}
        description={decision ? decisionDescriptions[decision] : undefined}
        footer={
          <>
            <Button onClick={closeDecision} disabled={acting}>Batal</Button>
            <Button variant={decisionVariant} onClick={submitDecision} loading={acting}>
              Konfirmasi {decision ? decisionLabels[decision] : "Keputusan"}
            </Button>
          </>
        }
      >
        {selectedItem && (
          <div className="space-y-4">
            <section className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">Dokumen</p>
                  <p className="mt-1 font-mono text-lg font-bold">{selectedItem.doc_type} #{selectedItem.doc_id}</p>
                </div>
                <StatusBadge status="PENDING" label={`Step ${selectedItem.current_step}`} />
              </div>
              <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt className="text-xs text-[var(--color-text-muted)]">Role approver</dt><dd className="mt-0.5 font-medium">{selectedItem.step_role}</dd></div>
                <div><dt className="text-xs text-[var(--color-text-muted)]">Diajukan oleh</dt><dd className="mt-0.5 font-medium">{selectedItem.submitted_by_name ?? "—"}</dd></div>
                <div className="sm:col-span-2"><dt className="text-xs text-[var(--color-text-muted)]">Waktu pengajuan</dt><dd className="mt-0.5 font-medium">{new Date(selectedItem.submitted_at).toLocaleString("id-ID", { dateStyle: "full", timeStyle: "short" })}</dd></div>
              </dl>
            </section>

            <label className="block text-sm">
              <span className="mb-1 block font-semibold">
                {noteRequired ? "Alasan" : "Catatan"}
                {noteRequired && <span className="text-[var(--color-danger)]"> *</span>}
              </span>
              <textarea
                value={note}
                onChange={(event) => {
                  setNote(event.target.value);
                  if (decisionError) setDecisionError(null);
                }}
                required={noteRequired}
                rows={4}
                aria-invalid={Boolean(decisionError)}
                aria-describedby={decisionError ? "decision-error" : "decision-hint"}
                placeholder={decision === "approve" ? "Tambahkan catatan bila diperlukan…" : "Jelaskan alasan keputusan secara spesifik…"}
                className="w-full resize-y rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-text)] placeholder:text-slate-400 disabled:opacity-50"
                disabled={acting}
              />
              {decisionError ? (
                <p id="decision-error" role="alert" className="mt-1 text-xs text-[var(--color-danger)]">{decisionError}</p>
              ) : (
                <p id="decision-hint" className="mt-1 text-xs text-[var(--color-text-muted)]">
                  {noteRequired ? "Alasan wajib dan akan dikirim bersama keputusan." : "Catatan bersifat opsional."}
                </p>
              )}
            </label>
          </div>
        )}
      </Modal>
    </div>
  );
}
