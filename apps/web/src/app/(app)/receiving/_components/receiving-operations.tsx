"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import {
  Button,
  ConfirmDialog,
  DataTable,
  Field,
  Input,
  PageHeader,
  Pagination,
  Select,
  StatusBadge,
  Textarea,
  type DataTableColumn,
} from "@/components/ui";

type Mode = "putaway" | "supplier-return";
interface Gr {
  id: number;
  doc_no: string;
  status: string;
}
interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
interface Balance {
  id: number;
  warehouse: string;
  location: string | null;
  on_hand: string;
  quality_hold: string;
  reserved: string;
}
interface Unit {
  receipt_ledger_id: number;
  gr_line_id: number;
  roll_id: number | null;
  roll_no: string | null;
  material_id: number;
  material: string;
  material_code: string;
  qty: string;
  uom: string;
  uom_id: number;
  location_id: number | null;
  location: string | null;
  lot_no: string | null;
  qc_result: string | null;
  returned_qty: number;
  putaway_allocated_qty: number;
  remaining_putaway_qty: number;
  can_return: boolean;
  can_putaway: boolean;
  dimension_balances: Balance[];
}
interface OperationLine {
  id: number;
  receipt_ledger_id: number;
  gr_line_id: number;
  roll_id: number | null;
  qty: string;
  uom_id: number;
  from_location?: { code: string } | null;
  to_location?: { code: string } | null;
}
interface Operation {
  id: number;
  doc_no: string;
  status: string;
  reason?: string;
  notes?: string;
  posted_at?: string | null;
  lines: OperationLine[];
}
interface Ledger {
  id: number;
  movement_type: string;
  qty_in: string;
  qty_out: string;
  material_id: number;
  roll_id: number | null;
  lot_no: string | null;
  source_document_type: string;
  source_document_id: number;
  source_document_line_id: number;
  created_at: string;
  warehouse: string;
  location: string | null;
}
interface Trace {
  gr: Gr;
  purchase_order?: { doc_no: string };
  supplier?: { name: string };
  units: Unit[];
  locations: { id: number; code: string; name: string | null }[];
  supplier_returns: Operation[];
  putaways: Operation[];
  ledger: Page<Ledger>;
  balance_note: string;
}
interface Selection {
  qty: string;
  location: string;
}
const fmt = (n: string | number) =>
  Number(n).toLocaleString("id-ID", { maximumFractionDigits: 4 });
const section =
  "space-y-4 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-4";

export default function ReceivingOperations({ mode }: { mode: Mode }) {
  const isReturn = mode === "supplier-return";
  const title = isReturn ? "Supplier Return" : "Putaway & Trace Lokasi";
  const [grPage, setGrPage] = useState<Page<Gr> | null>(null);
  const [grPageNo, setGrPageNo] = useState(1);
  const [q, setQ] = useState("");
  const [grId, setGrId] = useState("");
  const [trace, setTrace] = useState<Trace | null>(null);
  const [ledgerPage, setLedgerPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [selected, setSelected] = useState<Record<number, Selection>>({});
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{
    id: number;
    doc: string;
    action: "post" | "cancel";
  } | null>(null);
  useEffect(() => {
    setGrId(new URLSearchParams(window.location.search).get("gr") ?? "");
  }, []);
  useEffect(() => {
    let active = true;
    api
      .get<Page<Gr>>(
        `/receiving/grs?per_page=25&page=${grPageNo}&q=${encodeURIComponent(q)}`,
      )
      .then((r) => {
        if (active) setGrPage(r);
      })
      .catch((e: Error) => {
        if (active) setError(e.message);
      });
    return () => {
      active = false;
    };
  }, [q, grPageNo, revision]);
  useEffect(() => {
    if (!grId) {
      setTrace(null);
      return;
    }
    let active = true;
    setLoading(true);
    api
      .get<Trace>(`/receiving/grs/${grId}/stock-trace?page=${ledgerPage}`)
      .then((r) => {
        if (active) {
          setTrace(r);
          setError(null);
        }
      })
      .catch((e: Error) => {
        if (active) {
          setTrace(null);
          setError(e.message);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [grId, ledgerPage, revision]);
  async function run(
    action: () => Promise<unknown>,
    success: string,
  ): Promise<boolean> {
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await action();
      setMessage(success);
      setSelected({});
      setRevision((n) => n + 1);
      return true;
    } catch (e) {
      setError(e instanceof Error ? e.message : "Operasi gagal.");
      return false;
    } finally {
      setBusy(false);
    }
  }
  const current = trace?.gr.id === Number(grId) ? trace : null;
  const eligible = (unit: Unit) =>
    isReturn ? unit.can_return : unit.can_putaway;
  function toggle(unit: Unit, checked: boolean) {
    setSelected((old) => {
      const next = { ...old };
      if (checked)
        next[unit.receipt_ledger_id] = {
          qty: String(unit.remaining_putaway_qty),
          location: "",
        };
      else delete next[unit.receipt_ledger_id];
      return next;
    });
  }
  function update(unit: Unit, fields: Partial<Selection>) {
    setSelected((old) => ({
      ...old,
      [unit.receipt_ledger_id]: { ...old[unit.receipt_ledger_id], ...fields },
    }));
  }
  async function create() {
    if (!current) return;
    const units = current.units.filter(
      (u) => selected[u.receipt_ledger_id] && eligible(u),
    );
    if (!units.length) return;
    const data = isReturn
      ? {
          reason: notes,
          lines: units.map((u) => ({
            gr_line_id: u.gr_line_id,
            roll_id: u.roll_id,
          })),
        }
      : {
          notes: notes || null,
          lines: units.map((u) => ({
            gr_line_id: u.gr_line_id,
            roll_id: u.roll_id,
            qty: Number(selected[u.receipt_ledger_id].qty),
            to_location_id: Number(selected[u.receipt_ledger_id].location),
          })),
        };
    await run(
      () =>
        api.post(
          `/receiving/grs/${current.gr.id}/${isReturn ? "supplier-returns" : "putaways"}`,
          data,
        ),
      `${isReturn ? "Supplier Return" : "Putaway"} DRAFT dibuat. Periksa detail sebelum posting stok.`,
    );
  }
  const unitColumns: DataTableColumn<Unit>[] = [
    {
      key: "select",
      header: "Pilih",
      cell: (u) => (
        <input
          type="checkbox"
          aria-label={`Pilih ${u.roll_no ?? `line ${u.gr_line_id}`}`}
          checked={Boolean(selected[u.receipt_ledger_id])}
          disabled={busy || loading || !eligible(u)}
          onChange={(e) => toggle(u, e.target.checked)}
        />
      ),
    },
    {
      key: "source",
      header: "Receipt unit",
      cell: (u) => (
        <div>
          <b>{u.material_code}</b> · {u.material}
          <p className="text-xs">
            {u.roll_no ?? `Line ${u.gr_line_id}`} · ledger #
            {u.receipt_ledger_id}
          </p>
          <p className="text-xs text-slate-500">
            Lot {u.lot_no ?? "—"} · asal {u.location ?? "Tanpa lokasi"}
          </p>
        </div>
      ),
    },
    {
      key: "qc",
      header: "QC authority",
      cell: (u) => (
        <div>
          <StatusBadge status={u.qc_result ?? "PENDING"} />
          <p className="mt-1 text-xs">
            {fmt(u.qty)} {u.uom} diterima
          </p>
        </div>
      ),
    },
    {
      key: "allocation",
      header: isReturn ? "Qty return" : "Qty putaway",
      cell: (u) =>
        isReturn ? (
          <span>
            {fmt(u.qty)} {u.uom}
            <p className="text-xs text-slate-500">
              Sudah retur: {fmt(u.returned_qty)}
            </p>
          </span>
        ) : (
          <div className="min-w-28">
            <Input
              aria-label={`Qty putaway ${u.roll_no ?? `line ${u.gr_line_id}`}`}
              type="number"
              min="0.0001"
              step="0.0001"
              max={u.remaining_putaway_qty}
              readOnly={Boolean(u.roll_id)}
              required={Boolean(selected[u.receipt_ledger_id])}
              disabled={!selected[u.receipt_ledger_id] || busy}
              value={
                selected[u.receipt_ledger_id]?.qty ??
                String(u.remaining_putaway_qty)
              }
              onChange={(e) => update(u, { qty: e.target.value })}
            />
            <p className="text-xs text-slate-500">
              {u.uom} · alokasi aktif {fmt(u.putaway_allocated_qty)}
            </p>
          </div>
        ),
    },
    ...(!isReturn
      ? [
          {
            key: "destination",
            header: "Lokasi tujuan",
            cell: (u: Unit) => (
              <Select
                aria-label={`Lokasi tujuan ${u.roll_no ?? `line ${u.gr_line_id}`}`}
                required={Boolean(selected[u.receipt_ledger_id])}
                disabled={!selected[u.receipt_ledger_id] || busy}
                value={selected[u.receipt_ledger_id]?.location ?? ""}
                onChange={(e) => update(u, { location: e.target.value })}
              >
                <option value="">Pilih lokasi</option>
                {current?.locations
                  .filter((l) => l.id !== u.location_id)
                  .map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.code} {l.name ?? ""}
                    </option>
                  ))}
              </Select>
            ),
          },
        ]
      : []),
    {
      key: "balances",
      header: "Saldo dimensi saat ini",
      cell: (u) => (
        <details>
          <summary className="cursor-pointer text-sm">
            {u.dimension_balances.length} lokasi bersaldo
          </summary>
          <ul className="mt-2 space-y-1 text-xs">
            {u.dimension_balances.map((b) => (
              <li key={b.id}>
                {b.warehouse} / {b.location ?? "Tanpa lokasi"}: {fmt(b.on_hand)}{" "}
                on-hand · hold {fmt(b.quality_hold)} · reserved{" "}
                {fmt(b.reserved)}
              </li>
            ))}
          </ul>
        </details>
      ),
    },
  ];
  const documents = current
    ? isReturn
      ? current.supplier_returns
      : current.putaways
    : [];
  const documentColumns: DataTableColumn<Operation>[] = [
    {
      key: "doc",
      header: "Dokumen",
      cell: (d) => (
        <div>
          <b className="font-mono">{d.doc_no}</b>
          <p className="max-w-xs whitespace-normal text-xs">
            {d.reason ?? d.notes}
          </p>
        </div>
      ),
    },
    {
      key: "lines",
      header: "Detail tersimpan",
      cell: (d) =>
        d.lines.length ? (
          <ul className="space-y-1 text-xs">
            {d.lines.map((l) => (
              <li key={l.id}>
                Line #{l.id} ← receipt #{l.receipt_ledger_id} · {fmt(l.qty)}{" "}
                {current?.units.find(
                  (u) => u.receipt_ledger_id === l.receipt_ledger_id,
                )?.uom ?? `UOM #${l.uom_id}`}
                {!isReturn && (
                  <>
                    {" "}
                    · {l.from_location?.code ?? "Tanpa lokasi"} →{" "}
                    {l.to_location?.code}
                  </>
                )}
              </li>
            ))}
          </ul>
        ) : (
          <span className="text-xs text-amber-800">
            Legacy tanpa normalized lines: perlu rekonsiliasi, bukan repost.
          </span>
        ),
    },
    {
      key: "status",
      header: "Status",
      cell: (d) => <StatusBadge status={d.status} />,
    },
    {
      key: "actions",
      header: "Aksi",
      cell: (d) =>
        d.status === "DRAFT" && d.lines.length > 0 ? (
          <div className="flex gap-2">
            <Button
              size="sm"
              variant="primary"
              disabled={busy || loading}
              onClick={() =>
                setConfirmation({ id: d.id, doc: d.doc_no, action: "post" })
              }
            >
              Post {d.doc_no}
            </Button>
            <Button
              size="sm"
              disabled={busy || loading}
              onClick={() =>
                setConfirmation({ id: d.id, doc: d.doc_no, action: "cancel" })
              }
            >
              Batalkan draft
            </Button>
          </div>
        ) : (
          <span className="text-xs text-slate-500">
            {d.posted_at ? "Stok sudah diposting" : "—"}
          </span>
        ),
    },
  ];
  const ledgerColumns: DataTableColumn<Ledger>[] = [
    {
      key: "id",
      header: "Ledger / movement",
      cell: (l) => (
        <div>
          #{l.id}
          <p className="text-xs font-semibold">{l.movement_type}</p>
        </div>
      ),
    },
    {
      key: "source",
      header: "Source document / line",
      cell: (l) => (
        <span className="text-xs">
          {l.source_document_type} #{l.source_document_id}
          <br />
          line #{l.source_document_line_id}
        </span>
      ),
    },
    {
      key: "dimension",
      header: "Warehouse / location / lot",
      cell: (l) =>
        `${l.warehouse} / ${l.location ?? "Tanpa lokasi"} / ${l.lot_no ?? "—"}`,
    },
    { key: "in", header: "In", cell: (l) => fmt(l.qty_in) },
    { key: "out", header: "Out", cell: (l) => fmt(l.qty_out) },
  ];
  const grChoices = (grPage?.data ?? []).filter((g) => g.status === "POSTED");
  if (current && !grChoices.some((g) => g.id === current.gr.id))
    grChoices.unshift(current.gr);
  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Receiving"
        title={title}
        description={
          isReturn
            ? "Finalized QC FAIL → draft return → posting PURCHASE_RETURN dari quality hold. Quantity dan dimensi mengikuti receipt asli."
            : "Finalized QC PASS → alokasi putaway → transfer lokasi dua sisi melalui ITS. Tidak mengubah total stok atau nilai persediaan."
        }
      />
      {error && (
        <div
          role="alert"
          className="rounded bg-red-50 p-3 text-sm text-red-700"
        >
          {error}
          <Button
            size="sm"
            className="ml-2"
            disabled={busy}
            onClick={() => setRevision((n) => n + 1)}
          >
            Muat ulang
          </Button>
        </div>
      )}
      {message && (
        <p
          role="status"
          className="rounded bg-green-50 p-3 text-sm text-green-800"
        >
          {message}
        </p>
      )}
      <section className={section}>
        <div className="grid gap-3 md:grid-cols-2">
          <Field htmlFor="receiving-gr-search" label="Cari nomor GR">
            <Input
              id="receiving-gr-search"
              value={q}
              disabled={busy}
              onChange={(e) => {
                setQ(e.target.value);
                setGrPageNo(1);
              }}
            />
          </Field>
          <Field htmlFor="receiving-gr" label="Goods Receipt POSTED" required>
            <Select
              id="receiving-gr"
              value={grId}
              disabled={busy}
              onChange={(e) => {
                setGrId(e.target.value);
                setLedgerPage(1);
                setSelected({});
                setNotes("");
                setMessage(null);
                setError(null);
              }}
            >
              <option value="">Pilih GR</option>
              {grChoices.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.doc_no}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <Pagination
          page={grPageNo}
          totalPages={grPage?.last_page ?? 1}
          totalRecords={grPage?.total ?? 0}
          onPageChange={setGrPageNo}
        />
      </section>
      {loading && (
        <p role="status">Memuat authority receipt dan trace lokasi…</p>
      )}
      {current && (
        <>
          <form
            className={section}
            onSubmit={(e) => {
              e.preventDefault();
              void create();
            }}
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-semibold">
                {current.gr.doc_no} · {current.purchase_order?.doc_no} ·{" "}
                {current.supplier?.name}
              </h2>
              <Link
                href={`/receiving/${isReturn ? "putaway" : "supplier-returns"}?gr=${current.gr.id}`}
                className="text-sm font-medium text-blue-700 underline"
              >
                {isReturn ? "Lihat putaway & lokasi" : "Lihat supplier return"}
              </Link>
            </div>
            <DataTable
              caption={
                isReturn
                  ? "Unit eligible supplier return"
                  : "Unit eligible putaway"
              }
              columns={unitColumns}
              rows={current.units}
              getRowKey={(u) => u.receipt_ledger_id}
              emptyTitle="Tidak ada receipt unit"
              minWidth="1000px"
            />
            {!current.units.some(eligible) && (
              <p className="text-sm text-amber-800">
                Tidak ada unit eligible.{" "}
                {isReturn
                  ? "Return memerlukan finalized QC FAIL dan belum pernah diretur/dialokasikan."
                  : "Putaway memerlukan finalized QC PASS dan sisa alokasi receipt."}
              </p>
            )}
            {!isReturn && current.locations.length === 0 && (
              <p role="alert" className="text-sm text-amber-800">
                Warehouse ini belum memiliki lokasi tujuan. Lengkapi master
                lokasi sebelum putaway.
              </p>
            )}
            <Field
              htmlFor="receiving-operation-notes"
              label={isReturn ? "Alasan return" : "Catatan putaway"}
              required={isReturn}
            >
              <Textarea
                id="receiving-operation-notes"
                required={isReturn}
                maxLength={5000}
                disabled={busy}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </Field>
            <Button
              type="submit"
              variant="primary"
              loading={busy}
              disabled={
                loading ||
                !Object.keys(selected).length ||
                (isReturn && !notes.trim())
              }
            >
              Buat {isReturn ? "Supplier Return" : "Putaway"} DRAFT
            </Button>
            <p className="text-xs text-[var(--color-text-muted)]">
              {isReturn
                ? "Tidak membuat credit note/AP/GL atau membuka ulang PO. Claim settlement dan approval matrix khusus return tidak ditambahkan di iterasi ini."
                : "Putaway awal dalam warehouse yang sama. Fabric satu roll utuh; non-roll boleh partial. Draft yang dibatalkan hanya melepas alokasi dokumen, bukan reversal stok."}
            </p>
          </form>
          <section className={section}>
            <h2 className="font-semibold">
              Dokumen {isReturn ? "Supplier Return" : "Putaway"}
            </h2>
            <DataTable
              caption={`Daftar ${isReturn ? "supplier return" : "putaway"}`}
              columns={documentColumns}
              rows={documents}
              getRowKey={(d) => d.id}
              emptyTitle="Belum ada dokumen"
              minWidth="850px"
            />
          </section>
          <details className={section} open>
            <summary className="cursor-pointer font-semibold">
              Trace lokasi & ledger append-only
            </summary>
            <p className="text-xs text-slate-600">{current.balance_note}</p>
            <DataTable
              caption="Ledger lokasi"
              columns={ledgerColumns}
              rows={current.ledger.data}
              getRowKey={(l) => l.id}
              emptyTitle="Belum ada movement"
              minWidth="850px"
            />
            <Pagination
              page={ledgerPage}
              totalPages={current.ledger.last_page}
              totalRecords={current.ledger.total}
              onPageChange={setLedgerPage}
            />
          </details>
        </>
      )}
      <ConfirmDialog
        open={Boolean(confirmation)}
        title={`${confirmation?.action === "post" ? "Posting" : "Batalkan"} ${confirmation?.doc ?? "dokumen"}?`}
        description={
          confirmation?.action === "cancel"
            ? "Hanya draft yang dibatalkan. Tidak ada perubahan stok; nomor dokumen tetap tercatat."
            : isReturn
              ? "Posting mengurangi quality hold dan on-hand pada lokasi receipt asli. Dokumen yang sudah diposting tidak dapat diedit atau dibatalkan."
              : "Posting memindahkan stok available dari lokasi receipt ke lokasi tujuan. Kedua sisi diposting dalam satu transaksi."
        }
        confirmLabel={
          confirmation?.action === "post"
            ? "Konfirmasi posting stok"
            : "Batalkan draft"
        }
        variant={
          isReturn || confirmation?.action === "cancel" ? "danger" : "primary"
        }
        loading={busy}
        onCancel={() => {
          if (!busy) setConfirmation(null);
        }}
        onConfirm={async () => {
          if (!confirmation) return;
          const ok = await run(
            () =>
              api.post(
                `/receiving/${isReturn ? "supplier-returns" : "putaways"}/${confirmation.id}/${confirmation.action}`,
                {},
              ),
            confirmation.action === "post"
              ? "Stok berhasil diposting dan trace diperbarui."
              : "Draft dibatalkan; alokasi dilepas.",
          );
          if (ok) setConfirmation(null);
        }}
      >
        {error && (
          <p role="alert" className="text-sm text-red-700">
            {error}
          </p>
        )}
      </ConfirmDialog>
    </div>
  );
}
