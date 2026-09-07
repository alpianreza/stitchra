import { expect as assertion, type Page, type Route } from "@playwright/test";
const expect = assertion.configure({ timeout: 15000 });
const defaultUrl = process.env.E2E_BASE_URL ?? "http://localhost";
const cors = { "access-control-allow-origin": "*", "access-control-allow-headers": "*", "access-control-allow-methods": "*" };
const respond = (route: Route, body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", headers: cors, body: JSON.stringify(body) });
const paged = <T>(data: T[]) => ({ data, current_page: 1, last_page: 1, total: data.length });

async function fixtureAuth(page: Page) {
  await page.addInitScript(() => {
    localStorage.setItem("stitchra_token", "iteration27-mocked-token");
    localStorage.setItem("stitchra_company", "1");
    localStorage.setItem("stitchra_user", JSON.stringify({ name: "Iteration 27 UI Test" }));
  });
}

export async function rfqAwardFlow(page: Page, baseUrl = defaultUrl) {
  await fixtureAuth(page);
  const quote = { id: 10, supplier_id: 2, supplier: { name: "Supplier A" }, quotation_no: "Q-001", quoted_date: "2026-09-07", valid_until: "2026-09-30", currency: "USD", exchange_rate: "1", base_currency: "USD", lead_time_days: 3, payment_term: "Net 30", total_amount: 50, base_total: 50, is_selected: false, lines: [{ rfq_line_id: 5, unit_price: "5" }] };
  const rfq: Record<string, unknown> = { id: 1, doc_no: "RFQ-I27-001", status: "OPEN", deadline: "2026-09-10", notes: null, award_reason: null, lines_count: 1, quotations_count: 1, lines: [{ id: 5, line_no: 1, material_id: 3, qty: "10", uom_id: 4, material: { code: "TRIM", name: "Label" }, uom: { code: "PCS" } }], suppliers: [{ id: 2, name: "Supplier A" }], quotations: [quote], comparison_base_currency: "USD", purchase_order: null };
  const awards: unknown[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (request.method() === "OPTIONS") return route.fulfill({ status: 204, headers: cors });
    if (path.endsWith("/award")) {
      const data = request.postDataJSON(); awards.push(data);
      rfq.status = "AWARDED"; rfq.award_reason = data.reason; quote.is_selected = true;
      rfq.purchase_order = { id: 22, doc_no: "PO-I27-022", status: "DRAFT" };
      return respond(route, rfq.purchase_order);
    }
    if (path === "/api/purchasing/rfqs/1") return respond(route, rfq);
    if (path === "/api/purchasing/rfqs") return respond(route, paged([rfq]));
    return respond(route, paged([]));
  });
  await page.goto(`${baseUrl}/purchasing/rfqs?rfq=1`);
  await expect(page.getByRole("heading", { name: "RFQ & Supplier Comparison" })).toBeVisible();
  await page.getByLabel("Pilih quotation", { exact: false }).selectOption("10");
  await page.getByLabel("Tanggal PO", { exact: false }).fill("2026-09-07");
  await page.getByLabel("Alasan pemilihan supplier", { exact: false }).fill("Mutu dan lead time disetujui purchasing.");
  await page.getByRole("button", { name: "Pilih quotation & buat PO DRAFT" }).click();
  await expect(page.getByText("Quotation dipilih; PO DRAFT dibuat.", { exact: false })).toBeVisible();
  await expect(page.getByRole("link", { name: "PO-I27-022" })).toBeVisible();
  expect(awards).toEqual([{ order_date: "2026-09-07", expected_date: null, reason: "Mutu dan lead time disetujui purchasing." }]);
}

function stockTrace(fail = false) {
  const unit = { receipt_ledger_id: 100, gr_line_id: 40, roll_id: null, roll_no: null, material_id: 3, material: "Label", material_code: "TRIM", qty: "10", uom: "PCS", uom_id: 4, location_id: 1, location: "DOCK", lot_no: "LOT-A", qc_result: fail ? "FAIL" : "PASS", returned_qty: 0, putaway_allocated_qty: 0, remaining_putaway_qty: 10, can_return: fail, can_putaway: !fail, dimension_balances: [{ id: 1, warehouse: "RM", location: "DOCK", on_hand: "10", quality_hold: fail ? "10" : "0", reserved: "0" }] };
  return { gr: { id: 3, doc_no: "GR-I27-003", status: "POSTED" }, purchase_order: { doc_no: "PO-I27-022" }, supplier: { name: "Supplier A" }, units: [unit], locations: [{ id: 1, code: "DOCK", name: "Receiving" }, { id: 5, code: "RACK-A", name: "Rack A" }], supplier_returns: [] as Record<string, unknown>[], putaways: [] as Record<string, unknown>[], ledger: paged<Record<string, unknown>>([]), balance_note: "Saldo lot non-roll adalah pooled balance. Trace receipt menggunakan normalized operation lines." };
}

export async function putawayFlow(page: Page, baseUrl = defaultUrl) {
  await fixtureAuth(page); const trace = stockTrace(); const creates: unknown[] = []; let posts = 0;
  await page.route("**/api/**", async (route) => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (request.method() === "OPTIONS") return route.fulfill({ status: 204, headers: cors });
    if (path === "/api/receiving/grs") return respond(route, paged([trace.gr]));
    if (path.endsWith("/stock-trace")) return respond(route, trace);
    if (path === "/api/receiving/grs/3/putaways") {
      creates.push(request.postDataJSON()); trace.units[0].putaway_allocated_qty = 6; trace.units[0].remaining_putaway_qty = 4;
      const doc = { id: 20, doc_no: "PUT-I27-020", status: "DRAFT", lines: [{ id: 25, receipt_ledger_id: 100, gr_line_id: 40, roll_id: null, qty: "6", uom_id: 4, from_location: { code: "DOCK" }, to_location: { code: "RACK-A" } }] };
      trace.putaways = [doc]; return respond(route, doc, 201);
    }
    if (path === "/api/receiving/putaways/20/post") {
      posts++; trace.putaways[0].status = "POSTED"; trace.putaways[0].posted_at = "2026-09-07T05:00:00Z";
      return respond(route, trace.putaways[0]);
    }
    return respond(route, paged([]));
  });
  await page.goto(`${baseUrl}/receiving/putaway?gr=3`);
  await page.getByRole("checkbox", { name: "Pilih line 40" }).check();
  await page.getByLabel("Qty putaway line 40").fill("6");
  await page.getByLabel("Lokasi tujuan line 40").selectOption("5");
  await page.getByRole("button", { name: "Buat Putaway DRAFT" }).click();
  await page.getByRole("button", { name: "Post PUT-I27-020" }).click();
  await page.getByRole("button", { name: "Konfirmasi posting stok" }).click();
  await expect(page.getByText("Stok berhasil diposting dan trace diperbarui.")).toBeVisible();
  expect(creates).toEqual([{ notes: null, lines: [{ gr_line_id: 40, roll_id: null, qty: 6, to_location_id: 5 }] }]);
  expect(posts).toBe(1);
  await expect(page.getByRole("button", { name: "Post PUT-I27-020" })).toHaveCount(0);
}

export async function supplierReturnFlow(page: Page, baseUrl = defaultUrl) {
  await fixtureAuth(page); const trace = stockTrace(true); const creates: unknown[] = []; let posts = 0;
  trace.units.push({ ...trace.units[0], receipt_ledger_id: 101, gr_line_id: 41, qc_result: "PASS", can_return: false, can_putaway: true });
  await page.route("**/api/**", async (route) => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (request.method() === "OPTIONS") return route.fulfill({ status: 204, headers: cors });
    if (path === "/api/receiving/grs") return respond(route, paged([trace.gr]));
    if (path.endsWith("/stock-trace")) return respond(route, trace);
    if (path === "/api/receiving/grs/3/supplier-returns") {
      creates.push(request.postDataJSON()); trace.units[0].can_return = false;
      const doc = { id: 30, doc_no: "SR-I27-030", status: "DRAFT", reason: "Reject material", lines: [{ id: 31, receipt_ledger_id: 100, gr_line_id: 40, roll_id: null, qty: "10", uom_id: 4 }] };
      trace.supplier_returns = [doc]; return respond(route, doc, 201);
    }
    if (path === "/api/receiving/supplier-returns/30/post") {
      posts++; if (posts === 1) return respond(route, { message: "Temporary posting failure" }, 422);
      trace.supplier_returns[0].status = "SHIPPED"; trace.supplier_returns[0].posted_at = "2026-09-07T05:00:00Z";
      trace.units[0].returned_qty = 10; return respond(route, trace.supplier_returns[0]);
    }
    return respond(route, paged([]));
  });
  await page.goto(`${baseUrl}/receiving/supplier-returns?gr=3`);
  await expect(page.getByRole("checkbox", { name: "Pilih line 41" })).toBeDisabled();
  await page.getByRole("checkbox", { name: "Pilih line 40" }).check();
  await page.getByLabel("Alasan return", { exact: false }).fill("Reject material");
  await page.getByRole("button", { name: "Buat Supplier Return DRAFT" }).click();
  await page.getByRole("button", { name: "Post SR-I27-030" }).click();
  await page.getByRole("button", { name: "Konfirmasi posting stok" }).click();
  await expect(page.getByRole("dialog").getByText("Temporary posting failure")).toBeVisible();
  await page.getByRole("button", { name: "Konfirmasi posting stok" }).click();
  await expect(page.getByText("Stok berhasil diposting dan trace diperbarui.")).toBeVisible();
  expect(creates).toEqual([{ reason: "Reject material", lines: [{ gr_line_id: 40, roll_id: null }] }]);
  expect(posts).toBe(2);
}

export async function inwardQcRetryFlow(page: Page, baseUrl = defaultUrl) {
  await fixtureAuth(page); let creates = 0; let finalizes = 0; let lines: { result: string; gr_line_id: number; roll_id?: number }[] = [];
  const gr = { id: 3, doc_no: "GR-I27-QC", warehouse_id: 1, status: "POSTED", lines: [
    { id: 11, material_id: 3, qty_received: "5", uom_id: 4, status: "QUALITY_HOLD", material: { code: "FAB", name: "Fabric", tracking_level: "ROLL" }, rolls: [{ id: 12, roll_no: "ROLL-12", qty_buy: "5", qty_meter_actual: "5", status: "QUALITY_HOLD" }] },
    { id: 12, material_id: 5, qty_received: "10", uom_id: 6, status: "QUALITY_HOLD", material: { code: "TRIM", name: "Label", tracking_level: "LOT" }, rolls: [] },
  ] };
  await page.route("**/api/**", async (route) => {
    const request = route.request(); const path = new URL(request.url()).pathname;
    if (request.method() === "OPTIONS") return route.fulfill({ status: 204, headers: cors });
    if (path === "/api/receiving/inspection-grs") return respond(route, paged([gr]));
    if (path === "/api/receiving/inspection-grs/3") return respond(route, gr);
    if (path === "/api/receiving/grs/3/inspections") { creates++; lines = request.postDataJSON().lines; return respond(route, { id: 77 }, 201); }
    if (path === "/api/receiving/inspections/77/finalize") { finalizes++; return finalizes === 1 ? respond(route, { message: "Temporary finalize failure" }, 422) : respond(route, { id: 77, finalized_at: "2026-09-07" }); }
    return respond(route, paged([]));
  });
  await page.goto(`${baseUrl}/receiving/inspections?gr=3`);
  await page.getByRole("button", { name: "FAIL", exact: true }).nth(1).click();
  await page.getByRole("button", { name: "Simpan Inspeksi + Finalize", exact: false }).click();
  await expect(page.getByText("Temporary finalize failure")).toBeVisible();
  await page.getByRole("button", { name: "Coba finalize kembali" }).click();
  await expect(page.getByText("Inspeksi GR-I27-QC finalized.", { exact: false })).toBeVisible();
  expect(creates).toBe(1); expect(finalizes).toBe(2);
  expect(lines.map((l) => [l.gr_line_id, l.roll_id ?? null, l.result])).toEqual([[11, 12, "PASS"], [12, null, "FAIL"]]);
}
