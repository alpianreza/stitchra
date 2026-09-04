"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import {
  Button,
  ConfirmDialog,
  DataTable,
  FilterBar,
  FilterSelect,
  PageHeader,
  StatusBadge,
  type DataTableColumn,
} from "@/components/ui";

interface So {
  id: number;
  doc_no: string;
  status: string;
  order_date: string;
  ex_factory_date: string | null;
  customer?: { name: string };
  lines_count?: number;
}

interface Page { data: So[]; total: number }

const STATUSES = ["DRAFT", "SUBMITTED", "APPROVED", "CONFIRMED", "IN_PROGRESS", "CLOSED"];

type SoAction = "submit" | "confirm";

const ACTION_META: Record<SoAction, {
  title: string;
  description: (so: So) => string;
  confirmLabel: string;
  variant: "primary" | "success";
}> = {
  submit: {
    title: "Ajukan Sales Order?",
    description: (so) => `Sales order ${so.doc_no} akan berstatus SUBMITTED dan menunggu persetujuan.`,
    confirmLabel: "Ajukan",
    variant: "primary",
  },
  confirm: {
    title: "Konfirmasi Sales Order?",
    description: (so) => `Sales order ${so.doc_no} akan dikonfirmasi dan statusnya menjadi CONFIRMED.`,
    confirmLabel: "Konfirmasi",
    variant: "success",
  },
};

export default function SalesOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);
  const [pendingAction, setPendingAction] = useState<{ so: So; action: SoAction } | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get<Page>(`/sales/orders${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(load, [load]);

  const openAction = (so: So, action: SoAction) => {
    setActionError(null);
    setPendingAction({ so, action });
  };

  const runAction = useCallback(async () => {
    if (!pendingAction) return;
    setBusy(true);
    setActionError(null);
    try {
      await api.post(`/sales/orders/${pendingAction.so.id}/${pendingAction.action}`, {});
      setPendingAction(null);
      load();
    } catch (e) {
      setActionError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }, [pendingAction, load]);

  const renderActions = (so: So) => {
    if (so.status === "DRAFT") {
      return <Button size="sm" onClick={() => openAction(so, "submit")} disabled={busy}>Ajukan</Button>;
    }
    if (so.status === "SUBMITTED") {
      return <Button size="sm" variant="success" onClick={() => openAction(so, "confirm")} disabled={busy}>Konfirmasi</Button>;
    }
    return <span className="text-xs text-[var(--color-text-muted)]">—</span>;
  };

  const columns: DataTableColumn<So>[] = [
    { key: "doc_no", header: "No. SO", cell: (so) => <span className="font-mono font-semibold">{so.doc_no}</span> },
    { key: "customer", header: "Customer", cell: (so) => so.customer?.name ?? "—" },
    { key: "order_date", header: "Tanggal Order", cell: (so) => so.order_date },
    { key: "ex_factory", header: "Ex-Factory", cell: (so) => so.ex_factory_date ?? "—" },
    { key: "lines", header: "Lines", align: "right", className: "tabular-nums", cell: (so) => so.lines_count ?? "—" },
    { key: "status", header: "Status", cell: (so) => <StatusBadge status={so.status} /> },
    { key: "actions", header: "Aksi", align: "right", cell: renderActions },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Sales"
        title="Sales Order"
        description="Kelola order pelanggan, tanggal ex-factory, dan status dokumen."
        actions={
          <Link href="/sales/orders/new" className="inline-flex min-h-9 items-center rounded-[var(--radius-control)] bg-[var(--color-primary)] px-3 text-sm font-medium text-white hover:bg-[var(--color-primary-hover)]">
            Buat SO
          </Link>
        }
      />

      <FilterBar summary={page ? `${page.total.toLocaleString("id-ID")} sales order` : undefined}>
        <FilterSelect label="Filter status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">Semua status</option>
          {STATUSES.map((item) => <option key={item} value={item}>{item.replaceAll("_", " ")}</option>)}
        </FilterSelect>
      </FilterBar>

      <DataTable
        caption="Daftar sales order"
        columns={columns}
        rows={page?.data ?? []}
        getRowKey={(so) => so.id}
        loading={loading}
        error={error}
        onRetry={load}
        emptyTitle="Belum ada sales order"
        emptyDescription={status ? "Tidak ada sales order dengan status yang dipilih." : "Buat sales order pertama untuk memulai workflow order pelanggan."}
        emptyAction={
          !status ? (
            <Link href="/sales/orders/new" className="inline-flex min-h-9 items-center rounded-[var(--radius-control)] bg-[var(--color-primary)] px-3 text-sm font-medium text-white hover:bg-[var(--color-primary-hover)]">
              Buat Sales Order
            </Link>
          ) : undefined
        }
        minWidth="760px"
        mobileCard={(so) => (
          <article className="space-y-3 p-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="font-mono font-semibold text-[var(--color-text)]">{so.doc_no}</p>
                <p className="mt-0.5 text-sm text-[var(--color-text-muted)]">{so.customer?.name ?? "Customer tidak tersedia"}</p>
              </div>
              <StatusBadge status={so.status} />
            </div>
            <dl className="grid grid-cols-2 gap-3 text-xs">
              <div><dt className="text-[var(--color-text-muted)]">Order</dt><dd className="mt-0.5 font-medium">{so.order_date}</dd></div>
              <div><dt className="text-[var(--color-text-muted)]">Ex-Factory</dt><dd className="mt-0.5 font-medium">{so.ex_factory_date ?? "—"}</dd></div>
            </dl>
            {(so.status === "DRAFT" || so.status === "SUBMITTED") && (
              <div className="flex justify-end gap-1.5">{renderActions(so)}</div>
            )}
          </article>
        )}
      />

      <ConfirmDialog
        open={Boolean(pendingAction)}
        title={pendingAction ? ACTION_META[pendingAction.action].title : ""}
        description={pendingAction ? ACTION_META[pendingAction.action].description(pendingAction.so) : ""}
        confirmLabel={pendingAction ? ACTION_META[pendingAction.action].confirmLabel : "OK"}
        variant={pendingAction ? ACTION_META[pendingAction.action].variant : "primary"}
        loading={busy}
        onConfirm={runAction}
        onCancel={() => {
          if (!busy) {
            setPendingAction(null);
            setActionError(null);
          }
        }}
      >
        {actionError && (
          <p role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft-border)] bg-[var(--color-danger-soft)] p-3 text-sm text-[var(--color-danger)]">
            {actionError}
          </p>
        )}
      </ConfirmDialog>
    </div>
  );
}
