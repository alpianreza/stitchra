"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import {
  Button,
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
import { SourceSelect, type SourceOption } from "./source-select";

interface RfqLine {
  id: number;
  line_no: number;
  material_id: number;
  qty: string;
  uom_id: number;
  material?: { code: string; name: string };
  uom?: { code: string };
  pr_line?: { purchase_request?: { doc_no: string } };
}
interface Quote {
  id: number;
  supplier_id: number;
  supplier?: { name: string };
  quotation_no: string;
  quoted_date: string;
  valid_until: string | null;
  currency: string;
  exchange_rate: string | null;
  base_currency: string | null;
  lead_time_days: number | null;
  payment_term: string | null;
  total_amount: number;
  base_total: number | null;
  is_selected: boolean;
  lines: { rfq_line_id: number; unit_price: string }[];
}
interface Rfq {
  id: number;
  doc_no: string;
  status: string;
  deadline: string | null;
  notes: string | null;
  award_reason: string | null;
  lines_count?: number;
  quotations_count?: number;
  lines: RfqLine[];
  suppliers: { id: number; name: string }[];
  quotations: Quote[];
  comparison_base_currency: string;
  purchase_order?: { id: number; doc_no: string; status: string } | null;
}
interface Page {
  data: Rfq[];
  current_page: number;
  last_page: number;
  total: number;
}
const today = () => new Date().toISOString().slice(0, 10);
const dateText = (value?: string | null) => value?.slice(0, 10) ?? "—";
const fmt = (value: string | number) =>
  Number(value).toLocaleString("id-ID", { maximumFractionDigits: 6 });
const section =
  "space-y-4 rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-4";

function CreateRfq({
  busy,
  save,
}: {
  busy: boolean;
  save: (data: unknown) => void;
}) {
  const [deadline, setDeadline] = useState("");
  const [notes, setNotes] = useState("");
  const [supplier, setSupplier] = useState<SourceOption | null>(null);
  const [suppliers, setSuppliers] = useState<SourceOption[]>([]);
  type Line = {
    material: SourceOption | null;
    uom: SourceOption | null;
    pr: SourceOption | null;
    qty: string;
  };
  const blank = (): Line => ({ material: null, uom: null, pr: null, qty: "" });
  const [lines, setLines] = useState<Line[]>([blank()]);
  const update = (index: number, change: Partial<Line>) =>
    setLines((old) =>
      old.map((l, i) => (i === index ? { ...l, ...change } : l)),
    );
  return (
    <details className={section}>
      <summary className="cursor-pointer font-semibold">Buat RFQ</summary>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save({
            deadline: deadline || null,
            notes: notes || null,
            supplier_ids: suppliers.map((s) => s.id),
            lines: lines.map((l) => ({
              material_id: l.material?.id,
              uom_id: l.uom?.id,
              qty: Number(l.qty),
              pr_line_id: l.pr?.id ?? null,
            })),
          });
        }}
      >
        <fieldset disabled={busy} className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <Field htmlFor="rfq-deadline" label="Deadline quotation">
              <Input
                id="rfq-deadline"
                type="date"
                value={deadline}
                onChange={(e) => setDeadline(e.target.value)}
              />
            </Field>
            <Field htmlFor="rfq-notes" label="Catatan kebutuhan">
              <Input
                id="rfq-notes"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                maxLength={5000}
              />
            </Field>
          </div>
          <div className="grid gap-3 md:grid-cols-2">
            <SourceSelect
              kind="suppliers"
              label="Supplier penerima"
              value={supplier}
              onChange={setSupplier}
            />
            <div className="space-y-2">
              <Button
                disabled={
                  !supplier || suppliers.some((s) => s.id === supplier.id)
                }
                onClick={() => {
                  if (supplier) {
                    setSuppliers([...suppliers, supplier]);
                    setSupplier(null);
                  }
                }}
              >
                Tambahkan supplier
              </Button>
              {suppliers.map((s) => (
                <div
                  key={s.id}
                  className="flex items-center justify-between gap-2 rounded border p-2 text-sm"
                >
                  <span>{s.label}</span>
                  <Button
                    size="sm"
                    onClick={() =>
                      setSuppliers(suppliers.filter((x) => x.id !== s.id))
                    }
                    aria-label={`Hapus ${s.label}`}
                  >
                    Hapus
                  </Button>
                </div>
              ))}
            </div>
          </div>
          {lines.map((line, i) => (
            <fieldset key={i} className="space-y-3 rounded border p-3">
              <legend className="px-1 text-sm font-semibold">
                RFQ line {i + 1}
              </legend>
              <SourceSelect
                kind="pr_lines"
                label={`PR approved line ${i + 1} (opsional)`}
                value={line.pr}
                onChange={(pr) =>
                  update(
                    i,
                    pr
                      ? {
                          pr,
                          material: { id: pr.material_id!, label: pr.label },
                          uom: { id: pr.uom_id!, label: `UOM #${pr.uom_id}` },
                          qty: String(pr.qty),
                        }
                      : { pr: null },
                  )
                }
              />
              <div className="grid gap-3 md:grid-cols-3">
                <SourceSelect
                  kind="materials"
                  label={`Material line ${i + 1}`}
                  value={line.material}
                  required
                  disabled={Boolean(line.pr)}
                  onChange={(material) => update(i, { material })}
                />
                <SourceSelect
                  kind="uoms"
                  label={`UOM line ${i + 1}`}
                  value={line.uom}
                  required
                  disabled={Boolean(line.pr)}
                  onChange={(uom) => update(i, { uom })}
                />
                <Field
                  htmlFor={`rfq-qty-${i}`}
                  label="Quantity (UOM beli)"
                  required
                >
                  <Input
                    id={`rfq-qty-${i}`}
                    type="number"
                    min="0.0001"
                    step="0.0001"
                    max={line.pr?.qty}
                    required
                    value={line.qty}
                    onChange={(e) => update(i, { qty: e.target.value })}
                  />
                </Field>
              </div>
              <Button
                size="sm"
                disabled={lines.length === 1}
                onClick={() => setLines(lines.filter((_, n) => n !== i))}
              >
                Hapus line {i + 1}
              </Button>
            </fieldset>
          ))}
          <div className="flex flex-wrap gap-2">
            <Button
              disabled={lines.length >= 100}
              onClick={() => setLines([...lines, blank()])}
            >
              Tambah line
            </Button>
            <Button
              type="submit"
              variant="primary"
              loading={busy}
              disabled={!suppliers.length}
            >
              Simpan RFQ OPEN
            </Button>
          </div>
        </fieldset>
      </form>
    </details>
  );
}

function QuotationForm({
  rfq,
  busy,
  save,
}: {
  rfq: Rfq;
  busy: boolean;
  save: (data: unknown) => void;
}) {
  const [supplierId, setSupplierId] = useState("");
  const [currency, setCurrency] = useState<SourceOption | null>(null);
  const [number, setNumber] = useState("");
  const [date, setDate] = useState(today());
  const [validUntil, setValidUntil] = useState("");
  const [rate, setRate] = useState("");
  const [lead, setLead] = useState("");
  const [term, setTerm] = useState("");
  const [prices, setPrices] = useState<Record<number, string>>({});
  return (
    <details className={section}>
      <summary className="cursor-pointer font-semibold">
        Catat supplier quotation
      </summary>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save({
            supplier_id: Number(supplierId),
            currency_id: currency?.id,
            exchange_rate: Number(rate),
            quotation_no: number,
            quoted_date: date,
            valid_until: validUntil || null,
            lead_time_days: lead === "" ? null : Number(lead),
            payment_term: term || null,
            lines: rfq.lines.map((l) => ({
              rfq_line_id: l.id,
              unit_price: Number(prices[l.id]),
            })),
          });
        }}
      >
        <fieldset disabled={busy} className="space-y-4">
          <div className="grid gap-3 md:grid-cols-3">
            <Field htmlFor="quote-supplier" label="Supplier quotation" required>
              <Select
                id="quote-supplier"
                required
                value={supplierId}
                onChange={(e) => setSupplierId(e.target.value)}
              >
                <option value="">Pilih supplier RFQ</option>
                {rfq.suppliers.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            </Field>
            <Field
              htmlFor="quote-number"
              label="Nomor quotation supplier"
              required
            >
              <Input
                id="quote-number"
                required
                maxLength={128}
                value={number}
                onChange={(e) => setNumber(e.target.value)}
              />
            </Field>
            <Field htmlFor="quote-date" label="Tanggal quotation" required>
              <Input
                id="quote-date"
                type="date"
                required
                value={date}
                onChange={(e) => setDate(e.target.value)}
              />
            </Field>
            <Field htmlFor="quote-valid" label="Berlaku sampai">
              <Input
                id="quote-valid"
                type="date"
                min={date}
                value={validUntil}
                onChange={(e) => setValidUntil(e.target.value)}
              />
            </Field>
            <SourceSelect
              kind="currencies"
              label="Currency quotation"
              value={currency}
              required
              onChange={setCurrency}
            />
            <Field
              htmlFor="quote-rate"
              label={`Rate ke ${rfq.comparison_base_currency}`}
              hint="Snapshot: 1 unit currency quotation × rate = base currency. Rate base currency wajib 1."
              required
            >
              <Input
                id="quote-rate"
                type="number"
                required
                min="0.000000000001"
                step="0.000000000001"
                value={rate}
                onChange={(e) => setRate(e.target.value)}
              />
            </Field>
            <Field htmlFor="quote-lead" label="Lead time (hari)">
              <Input
                id="quote-lead"
                type="number"
                min="0"
                max="3650"
                step="1"
                value={lead}
                onChange={(e) => setLead(e.target.value)}
              />
            </Field>
            <Field htmlFor="quote-term" label="Payment term">
              <Input
                id="quote-term"
                maxLength={64}
                value={term}
                onChange={(e) => setTerm(e.target.value)}
              />
            </Field>
          </div>
          <p className="text-sm text-[var(--color-text-muted)]">
            Material, quantity, dan UOM mengikuti RFQ. Quotation immutable;
            catat quotation baru untuk revisi. Belum mendukung partial quotation
            atau split award.
          </p>
          {rfq.lines.map((l) => (
            <Field
              key={l.id}
              htmlFor={`quote-price-${l.id}`}
              label={`${l.material?.code ?? l.material_id} · ${fmt(l.qty)} ${l.uom?.code ?? l.uom_id} — harga satuan`}
              required
            >
              <Input
                id={`quote-price-${l.id}`}
                type="number"
                required
                min="0"
                step="0.000001"
                value={prices[l.id] ?? ""}
                onChange={(e) =>
                  setPrices({ ...prices, [l.id]: e.target.value })
                }
              />
            </Field>
          ))}
          <Button type="submit" variant="primary" loading={busy}>
            Simpan quotation
          </Button>
        </fieldset>
      </form>
    </details>
  );
}

function AwardForm({
  rfq,
  busy,
  save,
}: {
  rfq: Rfq;
  busy: boolean;
  save: (id: number, data: unknown) => void;
}) {
  const [quote, setQuote] = useState("");
  const [date, setDate] = useState(today());
  const [expected, setExpected] = useState("");
  const [reason, setReason] = useState("");
  return (
    <form
      className={section}
      onSubmit={(e) => {
        e.preventDefault();
        save(Number(quote), {
          order_date: date,
          expected_date: expected || null,
          reason,
        });
      }}
    >
      <h3 className="font-semibold">Keputusan purchasing → PO DRAFT</h3>
      <fieldset disabled={busy} className="space-y-3">
        <div className="grid gap-3 md:grid-cols-3">
          <Field htmlFor="award-quote" label="Pilih quotation" required>
            <Select
              id="award-quote"
              value={quote}
              onChange={(e) => setQuote(e.target.value)}
              required
            >
              <option value="">Pilih secara manual</option>
              {rfq.quotations.map((q) => (
                <option key={q.id} value={q.id}>
                  {q.supplier?.name} · {q.quotation_no} · {q.currency}{" "}
                  {fmt(q.total_amount)}
                </option>
              ))}
            </Select>
          </Field>
          <Field htmlFor="award-date" label="Tanggal PO" required>
            <Input
              id="award-date"
              type="date"
              required
              value={date}
              onChange={(e) => setDate(e.target.value)}
            />
          </Field>
          <Field
            htmlFor="award-expected"
            label="Expected delivery"
            hint="Kosong: tanggal PO + lead time quotation, jika tersedia."
          >
            <Input
              id="award-expected"
              type="date"
              min={date}
              value={expected}
              onChange={(e) => setExpected(e.target.value)}
            />
          </Field>
        </div>
        <Field
          htmlFor="award-reason"
          label="Alasan pemilihan supplier"
          required
        >
          <Textarea
            id="award-reason"
            required
            maxLength={5000}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </Field>
        <p className="text-sm text-[var(--color-text-muted)]">
          Satu RFQ → satu quotation terpilih → satu PO. Tidak otomatis memilih
          harga termurah atau melewati approval PO.
        </p>
        <Button
          type="submit"
          variant="primary"
          loading={busy}
          disabled={!quote || !reason.trim()}
        >
          Pilih quotation & buat PO DRAFT
        </Button>
      </fieldset>
    </form>
  );
}

export default function SourcingPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [pageNo, setPageNo] = useState(1);
  const [status, setStatus] = useState("");
  const [q, setQ] = useState("");
  const [rfqId, setRfqId] = useState<number | null>(null);
  const [rfq, setRfq] = useState<Rfq | null>(null);
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const loadList = useCallback(() => {
    setLoading(true);
    api
      .get<Page>(
        `/purchasing/rfqs?page=${pageNo}&status=${status}&q=${encodeURIComponent(q)}`,
      )
      .then(setPage)
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false));
  }, [pageNo, status, q]);
  useEffect(loadList, [loadList]);
  useEffect(() => {
    const id = Number(new URLSearchParams(window.location.search).get("rfq"));
    if (id > 0) setRfqId(id);
  }, []);
  useEffect(() => {
    if (!rfqId) return;
    let active = true;
    setDetailLoading(true);
    setRfq(null);
    api
      .get<Rfq>(`/purchasing/rfqs/${rfqId}`)
      .then((r) => {
        if (active) setRfq(r);
      })
      .catch((e: Error) => {
        if (active) setError(e.message);
      })
      .finally(() => {
        if (active) setDetailLoading(false);
      });
    return () => {
      active = false;
    };
  }, [rfqId, revision]);
  async function run(action: () => Promise<unknown>, success: string) {
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await action();
      setMessage(success);
      loadList();
      setRevision((n) => n + 1);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Operasi gagal.");
    } finally {
      setBusy(false);
    }
  }
  const columns: DataTableColumn<Rfq>[] = [
    {
      key: "doc",
      header: "RFQ",
      cell: (r) => (
        <Button
          size="sm"
          disabled={busy}
          onClick={() => {
            setRfqId(r.id);
            setError(null);
          }}
        >
          {r.doc_no}
        </Button>
      ),
    },
    { key: "deadline", header: "Deadline", cell: (r) => dateText(r.deadline) },
    {
      key: "lines",
      header: "Lines / Quotation",
      cell: (r) => `${r.lines_count ?? 0} / ${r.quotations_count ?? 0}`,
    },
    {
      key: "status",
      header: "Status",
      cell: (r) => <StatusBadge status={r.status} />,
    },
    {
      key: "po",
      header: "PO hasil award",
      cell: (r) => r.purchase_order?.doc_no ?? "—",
    },
  ];
  const quoteColumns: DataTableColumn<Quote>[] = [
    {
      key: "supplier",
      header: "Supplier / Ref",
      cell: (r) => (
        <div>
          <b>{r.supplier?.name}</b>
          <p className="font-mono text-xs">
            {r.quotation_no || `Legacy #${r.id}`}
          </p>
          {r.is_selected && <StatusBadge status="AWARDED" />}
        </div>
      ),
    },
    {
      key: "dates",
      header: "Tanggal / berlaku",
      cell: (r) => `${dateText(r.quoted_date)} → ${dateText(r.valid_until)}`,
    },
    {
      key: "total",
      header: "Total native",
      cell: (r) => `${r.currency} ${fmt(r.total_amount)}`,
    },
    {
      key: "base",
      header: `Setara ${rfq?.comparison_base_currency ?? "base"}`,
      cell: (r) =>
        r.base_total === null ? "Snapshot belum lengkap" : fmt(r.base_total),
    },
    {
      key: "terms",
      header: "Lead time / term",
      cell: (r) => `${r.lead_time_days ?? "—"} hari · ${r.payment_term ?? "—"}`,
    },
  ];
  const lineColumns: DataTableColumn<RfqLine>[] = [
    {
      key: "material",
      header: "Kebutuhan RFQ",
      cell: (l) => (
        <div>
          {l.material?.code} · {l.material?.name}
          <p className="text-xs text-slate-500">
            {fmt(l.qty)} {l.uom?.code} ·{" "}
            {l.pr_line?.purchase_request?.doc_no ?? "Manual"}
          </p>
        </div>
      ),
    },
    ...(rfq?.quotations ?? []).map((quote): DataTableColumn<RfqLine> => ({
      key: `quote-${quote.id}`,
      header: `${quote.supplier?.name ?? "Supplier"} / ${quote.quotation_no}`,
      cell: (l) => {
        const price = quote.lines.find(
          (x) => x.rfq_line_id === l.id,
        )?.unit_price;
        return price === undefined
          ? "Tidak tercakup"
          : `${quote.currency} ${fmt(price)} / unit`;
      },
    })),
  ];
  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Purchasing"
        title="RFQ & Supplier Comparison"
        description="Kebutuhan → supplier quotation → keputusan purchasing → PO DRAFT. Approval tetap melalui flow PO existing."
      />
      {error && (
        <div
          role="alert"
          className="rounded bg-red-50 p-3 text-sm text-red-700"
        >
          {error}
          <Button
            size="sm"
            className="ml-3"
            onClick={() => {
              loadList();
              setRevision((n) => n + 1);
            }}
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
      <CreateRfq
        busy={busy}
        save={(data) =>
          run(async () => {
            const created = await api.post<Rfq>("/purchasing/rfqs", data);
            setRfqId(created.id);
          }, "RFQ OPEN dibuat.")
        }
      />
      <div className={section}>
        <div className="flex flex-wrap gap-3">
          <Input
            aria-label="Cari nomor RFQ"
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPageNo(1);
            }}
            placeholder="Cari nomor RFQ…"
          />
          <Select
            aria-label="Filter status RFQ"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPageNo(1);
            }}
          >
            <option value="">Semua status</option>
            {["OPEN", "CLOSED", "AWARDED", "CANCELLED"].map((s) => (
              <option key={s}>{s}</option>
            ))}
          </Select>
        </div>
        <DataTable
          caption="Daftar RFQ"
          columns={columns}
          rows={page?.data ?? []}
          getRowKey={(r) => r.id}
          loading={loading}
          emptyTitle="Belum ada RFQ"
          minWidth="720px"
        />
        <Pagination
          page={pageNo}
          totalPages={page?.last_page ?? 1}
          totalRecords={page?.total ?? 0}
          onPageChange={setPageNo}
        />
      </div>
      {detailLoading && <p role="status">Memuat detail RFQ…</p>}
      {rfq && (
        <section className="space-y-4" aria-label={`Detail ${rfq.doc_no}`}>
          <div className={section}>
            <div className="flex flex-wrap items-center gap-3">
              <h2 className="font-mono text-lg font-bold">{rfq.doc_no}</h2>
              <StatusBadge status={rfq.status} />
              {rfq.status === "OPEN" && (
                <Button
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    run(
                      () => api.post(`/purchasing/rfqs/${rfq.id}/close`, {}),
                      "RFQ ditutup untuk quotation baru.",
                    )
                  }
                >
                  Tutup penerimaan quotation
                </Button>
              )}
            </div>
            <p className="text-sm">
              Supplier:{" "}
              {rfq.suppliers.map((s) => s.name).join(", ") ||
                "Belum ada sumber terstruktur (legacy)"}
            </p>
            {rfq.notes && <p className="text-sm">{rfq.notes}</p>}
            {rfq.purchase_order && (
              <p className="text-sm">
                PO:{" "}
                <Link
                  className="font-semibold text-blue-700 underline"
                  href="/purchasing/pos"
                >
                  {rfq.purchase_order.doc_no}
                </Link>{" "}
                · {rfq.purchase_order.status}
                <br />
                Alasan: {rfq.award_reason}
              </p>
            )}
          </div>
          {rfq.status === "OPEN" && rfq.lines.length > 0 && (
            <QuotationForm
              key={rfq.id}
              rfq={rfq}
              busy={busy}
              save={(data) =>
                run(
                  () => api.post(`/purchasing/rfqs/${rfq.id}/quotations`, data),
                  "Quotation tersimpan.",
                )
              }
            />
          )}
          <div className={section}>
            <h3 className="font-semibold">
              Comparison — tidak ada auto-winner
            </h3>
            <DataTable
              caption="Supplier quotation comparison"
              columns={quoteColumns}
              rows={rfq.quotations}
              getRowKey={(r) => r.id}
              emptyTitle="Belum ada quotation"
              minWidth="950px"
            />
            <DataTable
              caption="Perbandingan harga per material"
              columns={lineColumns}
              rows={rfq.lines}
              getRowKey={(r) => r.id}
              emptyTitle="RFQ legacy tanpa normalized line"
              minWidth="650px"
            />
          </div>
          {["OPEN", "CLOSED"].includes(rfq.status) &&
            rfq.quotations.length > 0 && (
              <AwardForm
                key={rfq.id}
                rfq={rfq}
                busy={busy}
                save={(id, data) =>
                  run(
                    () =>
                      api.post(
                        `/purchasing/rfqs/${rfq.id}/quotations/${id}/award`,
                        data,
                      ),
                    "Quotation dipilih; PO DRAFT dibuat. Lanjutkan submit/approval dari Purchase Order.",
                  )
                }
              />
            )}
        </section>
      )}
    </div>
  );
}
