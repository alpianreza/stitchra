import { test } from "@playwright/test";
import { rfqAwardFlow, putawayFlow, supplierReturnFlow, inwardQcRetryFlow } from "./iteration27-flows";

// Mocked API: UI contract coverage, not a substitute for MySQL/Pest or business UAT.
test("I27 RFQ comparison awards a PO draft without client price/qty overrides", async ({ page }) => rfqAwardFlow(page));
test("I27 putaway requires explicit confirmation and posts once", async ({ page }) => putawayFlow(page));
test("I27 supplier return excludes PASS and retries posting without recreating", async ({ page }) => supplierReturnFlow(page));
test("I27 QC isolates colliding roll/line ids and retries only finalization", async ({ page }) => inwardQcRetryFlow(page));
