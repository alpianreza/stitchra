import { test, expect } from "@playwright/test";

// Smoke test: halaman login tampil
test("login page renders", async ({ page }) => {
  await page.goto("/login");
  await expect(page.getByRole("heading", { name: "Stitchra ERP" })).toBeVisible();
  await expect(page.getByLabel(/email/i)).toBeVisible();
});
