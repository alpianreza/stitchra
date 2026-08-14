import { defineConfig, devices } from "@playwright/test";

// E2E smoke — diaktifkan setelah UI login berjalan stabil (bagian DoD Phase 1)
export default defineConfig({
  testDir: "./e2e",
  timeout: 30_000,
  retries: 0,
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost",
    trace: "on-first-retry",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
