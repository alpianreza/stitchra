/**
 * Metadata master data untuk UI generik — kolom tabel + field form.
 * Selaras dengan MasterDataRegistry di API (slug → endpoint /api/master/{slug}).
 */
export interface FieldMeta {
  name: string;
  label: string;
  type: "text" | "number" | "select";
  required?: boolean;
  options?: string[];
}

export interface EntityMeta {
  slug: string;
  title: string;
  listColumns: { key: string; label: string }[];
  fields: FieldMeta[];
}

export const masterEntities: Record<string, EntityMeta> = {
  customers: {
    slug: "customers",
    title: "Customer",
    listColumns: [
      { key: "code", label: "Kode" },
      { key: "name", label: "Nama" },
      { key: "brand", label: "Brand" },
      { key: "country", label: "Negara" },
      { key: "currency", label: "Kurs" },
      { key: "is_active", label: "Aktif" },
    ],
    fields: [
      { name: "code", label: "Kode", type: "text", required: true },
      { name: "name", label: "Nama", type: "text", required: true },
      { name: "brand", label: "Brand", type: "text" },
      { name: "country", label: "Negara (ISO 2)", type: "text" },
      { name: "currency", label: "Kurs", type: "text" },
      { name: "incoterm", label: "Incoterm", type: "select", options: ["FOB", "CIF", "EXW", "FCA"] },
      { name: "shipment_tolerance_pct", label: "Toleransi Shipment %", type: "number" },
    ],
  },
  suppliers: {
    slug: "suppliers",
    title: "Supplier",
    listColumns: [
      { key: "code", label: "Kode" },
      { key: "name", label: "Nama" },
      { key: "type", label: "Tipe" },
      { key: "lead_time_days", label: "Lead Time (hari)" },
      { key: "is_active", label: "Aktif" },
    ],
    fields: [
      { name: "code", label: "Kode", type: "text", required: true },
      { name: "name", label: "Nama", type: "text", required: true },
      { name: "type", label: "Tipe", type: "select", required: true, options: ["FABRIC", "TRIM", "PACKAGING", "SUBCON"] },
      { name: "lead_time_days", label: "Lead Time (hari)", type: "number" },
      { name: "currency", label: "Kurs", type: "text" },
      { name: "payment_term", label: "Termin Pembayaran", type: "text" },
    ],
  },
  materials: {
    slug: "materials",
    title: "Material",
    listColumns: [
      { key: "code", label: "Kode" },
      { key: "name", label: "Nama" },
      { key: "type", label: "Tipe" },
      { key: "gsm", label: "GSM" },
      { key: "width_cm", label: "Lebar (cm)" },
      { key: "tracking_level", label: "Tracking" },
    ],
    fields: [
      { name: "code", label: "Kode", type: "text", required: true },
      { name: "name", label: "Nama", type: "text", required: true },
      { name: "type", label: "Tipe", type: "select", required: true, options: ["FABRIC", "TRIM", "PACKAGING"] },
      { name: "composition", label: "Komposisi", type: "text" },
      { name: "construction", label: "Konstruksi", type: "text" },
      { name: "gsm", label: "GSM", type: "number" },
      { name: "width_cm", label: "Lebar (cm)", type: "number" },
      { name: "shrinkage_std_pct", label: "Shrinkage Std %", type: "number" },
      { name: "tracking_level", label: "Tracking", type: "select", options: ["ROLL", "LOT"] },
      { name: "safety_stock_qty", label: "Safety Stock", type: "number" },
    ],
  },
  styles: {
    slug: "styles",
    title: "Style",
    listColumns: [
      { key: "style_no", label: "Style No" },
      { key: "category", label: "Kategori" },
      { key: "season", label: "Season" },
      { key: "lifecycle", label: "Lifecycle" },
    ],
    fields: [
      { name: "style_no", label: "Style No", type: "text", required: true },
      { name: "buyer_style_ref", label: "Ref Buyer", type: "text" },
      { name: "season", label: "Season", type: "text" },
      { name: "category", label: "Kategori", type: "select", required: true, options: ["WOVEN", "KNIT", "OTHER"] },
      { name: "lifecycle", label: "Lifecycle", type: "select", options: ["DEVELOPMENT", "ACTIVE", "DISCONTINUED"] },
    ],
  },
  warehouses: {
    slug: "warehouses",
    title: "Warehouse",
    listColumns: [
      { key: "code", label: "Kode" },
      { key: "name", label: "Nama" },
      { key: "type", label: "Tipe" },
    ],
    fields: [
      { name: "code", label: "Kode", type: "text", required: true },
      { name: "name", label: "Nama", type: "text", required: true },
      { name: "type", label: "Tipe", type: "select", required: true, options: ["RM", "WIP", "FG", "TRIM", "SUBCON_VIRTUAL"] },
    ],
  },
  employees: {
    slug: "employees",
    title: "Karyawan",
    listColumns: [
      { key: "nik", label: "NIK" },
      { key: "name", label: "Nama" },
      { key: "section", label: "Section" },
      { key: "is_operator", label: "Operator" },
    ],
    fields: [
      { name: "nik", label: "NIK", type: "text", required: true },
      { name: "name", label: "Nama", type: "text", required: true },
      { name: "section", label: "Section", type: "select", options: ["cutting", "sewing", "finishing", "packing", "warehouse", "qc"] },
      { name: "skill", label: "Skill", type: "text" },
    ],
  },
};

export const masterMenuOrder = ["customers", "suppliers", "materials", "styles", "warehouses", "employees"];
