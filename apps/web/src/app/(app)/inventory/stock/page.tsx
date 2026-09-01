"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { DataTable, FilterBar, FilterSelect, PageHeader, type DataTableColumn } from "@/components/ui";

interface Row { material_code: string | null; material_name: string | null; warehouse_code: string | null; lot_no: string | null; roll_id: number | null; ownership: string; on_hand: number; reserved: number; quality_hold: number; available: number; avg_cost: number | null }
interface Warehouse { id: number; code: string; name: string }

export default function StockInquiryPage() {
  const [rows, setRows] = useState<Row[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => { api.get<{ data: Warehouse[] }>("/master/warehouses?per_page=100").then((response) => setWarehouses(response.data)).catch(() => {}); }, []);
  const load = useCallback(() => {
    setLoading(true); setError(null);
    api.get<{ data: Row[] }>(`/inventory/stock${warehouseId ? `?warehouse_id=${warehouseId}` : ""}`).then((response) => setRows(response.data)).catch((requestError) => setError(requestError.message)).finally(() => setLoading(false));
  }, [warehouseId]);
  useEffect(load, [load]);

  const fmt = (value: number | null) => value === null ? "—" : Number(value).toLocaleString("id-ID", { maximumFractionDigits: 4 });
  const columns: DataTableColumn<Row>[] = [
    { key: "material", header: "Material", cell: (row) => <div><p className="font-mono font-semibold">{row.material_code ?? "—"}</p><p className="text-xs text-[var(--color-text-muted)]">{row.material_name ?? "—"}</p></div> },
    { key: "warehouse", header: "Gudang", cell: (row) => row.warehouse_code ?? "—" },
    { key: "tracking", header: "Lot / Roll", cell: (row) => row.roll_id ? `Roll #${row.roll_id}` : (row.lot_no ?? "—") },
    { key: "ownership", header: "Ownership", cell: (row) => row.ownership },
    { key: "onHand", header: "On Hand", align: "right", cell: (row) => <span className="tabular-nums">{fmt(row.on_hand)}</span> },
    { key: "reserved", header: "Reserved", align: "right", cell: (row) => <span className="font-medium tabular-nums text-[var(--color-warning)]">{fmt(row.reserved)}</span> },
    { key: "hold", header: "Quality Hold", align: "right", cell: (row) => <span className="tabular-nums text-[var(--color-text-muted)]">{fmt(row.quality_hold)}</span> },
    { key: "available", header: "Available", align: "right", cell: (row) => <strong className="tabular-nums">{fmt(row.available)}</strong> },
    { key: "cost", header: "Avg Cost", align: "right", cell: (row) => <span className="tabular-nums">{fmt(row.avg_cost)}</span> },
  ];

  return (
    <div className="space-y-4">
      <PageHeader eyebrow="Inventory" title="Inquiry Stok" description="Saldo on hand, reservation, quality hold, dan availability per material." />
      <FilterBar resultSummary={`${rows.length} saldo stok`}>
        <FilterSelect label="Gudang" value={warehouseId} onChange={(event) => setWarehouseId(event.target.value)}>
          <option value="">Semua gudang</option>
          {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} — {warehouse.name}</option>)}
        </FilterSelect>
      </FilterBar>
      <DataTable caption="Inquiry saldo stok" columns={columns} rows={rows} getRowKey={(row) => `${row.material_code}-${row.warehouse_code}-${row.lot_no}-${row.roll_id}-${row.ownership}`} loading={loading} error={error} onRetry={load} emptyTitle="Belum ada saldo stok" emptyDescription={warehouseId ? "Gudang yang dipilih belum memiliki saldo stok." : "Saldo stok akan muncul setelah transaksi inventory diposting."} minWidth="1080px" mobileCard={(row) => (
        <article className="space-y-3 p-4"><div><p className="font-mono font-semibold">{row.material_code ?? "—"}</p><p className="text-sm">{row.material_name ?? "—"}</p><p className="text-xs text-[var(--color-text-muted)]">{row.warehouse_code ?? "—"} · {row.roll_id ? `Roll #${row.roll_id}` : (row.lot_no ?? "Tanpa lot")}</p></div><dl className="grid grid-cols-2 gap-2 text-xs"><div><dt className="text-[var(--color-text-muted)]">On Hand</dt><dd className="font-semibold tabular-nums">{fmt(row.on_hand)}</dd></div><div><dt className="text-[var(--color-text-muted)]">Available</dt><dd className="font-bold tabular-nums">{fmt(row.available)}</dd></div><div><dt className="text-[var(--color-text-muted)]">Reserved</dt><dd className="font-medium tabular-nums text-[var(--color-warning)]">{fmt(row.reserved)}</dd></div><div><dt className="text-[var(--color-text-muted)]">Quality Hold</dt><dd className="tabular-nums">{fmt(row.quality_hold)}</dd></div></dl></article>
      )} />
    </div>
  );
}
