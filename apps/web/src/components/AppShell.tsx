"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { clearAuth, getToken, getUser } from "@/lib/auth";
import { api } from "@/lib/api";
import { t } from "@/lib/i18n";
import { masterEntities, masterMenuOrder } from "@/lib/masterMeta";
import { Button } from "@/components/ui";
import { NavigationIcon, type NavigationIconName } from "@/components/ui/NavigationIcon";

interface NavItem {
  href: string;
  label: string;
  icon: NavigationIconName;
}

interface NavGroup {
  id: string;
  label: string;
  items: NavItem[];
}

const NAV_GROUPS: NavGroup[] = [
  {
    id: "workspace",
    label: "Workspace",
    items: [
      { href: "/dashboard", label: "Dasbor", icon: "dashboard" },
      { href: "/approvals", label: "Approval", icon: "approval" },
    ],
  },
  {
    id: "sales",
    label: "Sales",
    items: [{ href: "/sales/orders", label: "Sales Order", icon: "sales" }],
  },
  {
    id: "product-development",
    label: "Product Development",
    items: [
      { href: "/pd/boms", label: "BOM", icon: "product" },
      { href: "/pd/routings", label: "Routing", icon: "product" },
      { href: "/pd/cost-sheets", label: "Cost Sheet", icon: "finance" },
      { href: "/pd/samples", label: "Sample Request", icon: "product" },
    ],
  },
  {
    id: "planning",
    label: "Planning",
    items: [{ href: "/planning/mrp", label: "MRP", icon: "planning" }],
  },
  {
    id: "purchasing",
    label: "Purchasing",
    items: [
      { href: "/purchasing/prs", label: "Purchase Request", icon: "purchasing" },
      { href: "/purchasing/pos", label: "Purchase Order", icon: "purchasing" },
    ],
  },
  {
    id: "receiving",
    label: "Receiving",
    items: [
      { href: "/receiving/grs", label: "Goods Receipt", icon: "receiving" },
      { href: "/receiving/inspections", label: "Inward QC (FQC)", icon: "quality" },
    ],
  },
  {
    id: "warehouse",
    label: "Warehouse & Inventory",
    items: [
      { href: "/inventory/stock", label: "Inquiry Stok", icon: "inventory" },
      { href: "/inventory/ops", label: "Operasi Stok", icon: "inventory" },
    ],
  },
  {
    id: "manufacturing-orders",
    label: "Manufacturing Order",
    items: [{ href: "/production/orders", label: "Manufacturing Order", icon: "production" }],
  },
  {
    id: "cutting",
    label: "Cutting",
    items: [{ href: "/production/cutting", label: "Eksekusi Cutting", icon: "production" }],
  },
  {
    id: "sewing",
    label: "Sewing",
    items: [
      { href: "/shopfloor/scan?stage=SEWING", label: "Sewing Scan", icon: "scan" },
      { href: "/shopfloor/monitor", label: "Monitor Sewing & WIP", icon: "scan" },
    ],
  },
  {
    id: "finishing",
    label: "Finishing",
    items: [{ href: "/shopfloor/scan?stage=FINISHING", label: "Finishing Scan", icon: "scan" }],
  },
  {
    id: "quality-control",
    label: "Quality Control",
    items: [
      { href: "/qc/inspections", label: "Inspeksi QC", icon: "quality" },
      { href: "/qc/ncrs", label: "NCR & Disposition", icon: "quality" },
    ],
  },
  {
    id: "packing-fg",
    label: "Packing & FG",
    items: [{ href: "/packing/lists", label: "Packing List & FG Receipt", icon: "packing" }],
  },
  {
    id: "shipping",
    label: "Shipping",
    items: [{ href: "/shipping/shipments", label: "Shipment", icon: "shipping" }],
  },
  {
    id: "subcontracting",
    label: "Subcontracting",
    items: [{ href: "/subcon/orders", label: "Subcontracting Order", icon: "subcon" }],
  },
  {
    id: "costing",
    label: "Costing",
    items: [
      { href: "/finance/costing", label: "Costing Aktual", icon: "finance" },
      { href: "/finance/costing/valuation", label: "Valuasi Produksi", icon: "finance" },
      { href: "/finance/bep", label: "BEP", icon: "finance" },
      { href: "/shipping/shipments/valuation", label: "Valuasi Shipment", icon: "inventory" },
    ],
  },
  {
    id: "finance",
    label: "Finance",
    items: [
      { href: "/finance/ar-ap", label: "AR / AP", icon: "finance" },
      { href: "/finance/currencies", label: "Currency & Kurs", icon: "finance" },
      { href: "/finance/closing", label: "Tutup Buku & FX", icon: "finance" },
      { href: "/finance/bank-recon", label: "Bank Reconciliation", icon: "finance" },
      { href: "/finance/valuation", label: "Valuasi Manufaktur", icon: "finance" },
      { href: "/finance/tax-mappings", label: "Pajak & Mapping", icon: "admin" },
      { href: "/finance/journals", label: "Jurnal", icon: "finance" },
      { href: "/finance/cogs", label: "Shipment COGS", icon: "finance" },
      { href: "/finance/corrections", label: "Koreksi Akuntansi", icon: "finance" },
    ],
  },
  {
    id: "reports",
    label: "Reports",
    items: [{ href: "/reports", label: "Laporan", icon: "reports" }],
  },
  {
    id: "master-data",
    label: "Master Data",
    items: masterMenuOrder.map((slug) => ({
      href: `/master/${slug}`,
      label: masterEntities[slug].title,
      icon: "master" as const,
    })),
  },
  {
    id: "administration",
    label: "Administration",
    items: [{ href: "/approvals/flows", label: "Approval Flow", icon: "admin" }],
  },
];

const ALL_ITEMS = NAV_GROUPS.flatMap((group) => group.items);

function matchesPath(pathname: string, search: string, href: string) {
  const [hrefPath, hrefQuery = ""] = href.split("?");
  if (pathname !== hrefPath && !pathname.startsWith(`${hrefPath}/`)) return false;
  if (!hrefQuery) return true;

  const expected = new URLSearchParams(hrefQuery);
  const current = new URLSearchParams(search);
  return Array.from(expected.entries()).every(([key, value]) => current.get(key) === value);
}

function useActiveItem(pathname: string, search: string) {
  return useMemo(
    () => ALL_ITEMS.filter((item) => matchesPath(pathname, search, item.href)).sort((a, b) => b.href.length - a.href.length)[0],
    [pathname, search],
  );
}

function SidebarContent({
  pathname,
  search,
  collapsed,
  openGroups,
  onToggleGroup,
  onNavigate,
}: {
  pathname: string;
  search: string;
  collapsed: boolean;
  openGroups: Record<string, boolean>;
  onToggleGroup: (id: string) => void;
  onNavigate?: () => void;
}) {
  const activeItem = useActiveItem(pathname, search);

  return (
    <nav aria-label="Navigasi utama" className="flex-1 overflow-y-auto px-2 pb-4">
      {NAV_GROUPS.map((group) => {
        const isOpen = collapsed || openGroups[group.id] !== false;
        return (
          <section key={group.id} className="mt-3 first:mt-1">
            {!collapsed && (
              <button
                type="button"
                onClick={() => onToggleGroup(group.id)}
                aria-expanded={isOpen}
                className="flex min-h-8 w-full items-center justify-between rounded px-2 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-400 hover:bg-slate-800 hover:text-slate-200"
              >
                <span>{group.label}</span>
                <NavigationIcon name="chevron" className={`size-3.5 transition-transform ${isOpen ? "rotate-90" : ""}`} />
              </button>
            )}
            {isOpen && (
              <div className={collapsed ? "space-y-1" : "mt-1 space-y-1"}>
                {group.items.map((item) => {
                  const active = activeItem?.href === item.href;
                  return (
                    <Link
                      key={item.href}
                      href={item.href}
                      onClick={onNavigate}
                      aria-current={active ? "page" : undefined}
                      title={collapsed ? item.label : undefined}
                      className={[
                        "group relative flex min-h-9 items-center gap-2.5 rounded-[var(--radius-control)] px-2.5 text-sm transition-colors",
                        collapsed ? "justify-center" : "",
                        active
                          ? "bg-blue-500/15 font-semibold text-blue-200"
                          : "text-slate-300 hover:bg-slate-800 hover:text-white",
                      ].join(" ")}
                    >
                      {active && <span className="absolute inset-y-1 left-0 w-0.5 rounded-full bg-blue-400" />}
                      <NavigationIcon name={item.icon} />
                      {!collapsed && <span className="truncate">{item.label}</span>}
                    </Link>
                  );
                })}
              </div>
            )}
          </section>
        );
      })}
    </nav>
  );
}

export default function AppShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const search = useSearchParams().toString();
  const activeItem = useActiveItem(pathname, search);
  const [user, setUser] = useState<{ name: string; roles?: string[] } | null>(null);
  const [ready, setReady] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});
  const [companyId, setCompanyId] = useState<string | null>(null);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }
    setUser(getUser());
    setCompanyId(localStorage.getItem("stitchra_company"));
    setCollapsed(localStorage.getItem("stitchra_sidebar_collapsed") === "true");
    setReady(true);
  }, [router]);

  useEffect(() => {
    setMobileOpen(false);
  }, [pathname, search]);

  function toggleCollapsed() {
    setCollapsed((current) => {
      const next = !current;
      localStorage.setItem("stitchra_sidebar_collapsed", String(next));
      return next;
    });
  }

  function toggleGroup(id: string) {
    setOpenGroups((current) => ({ ...current, [id]: current[id] === false }));
  }

  async function logout() {
    try {
      await api.post("/auth/logout", {});
    } catch {}
    clearAuth();
    router.replace("/login");
  }

  if (!ready) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-[var(--color-background)]" aria-label="Memuat aplikasi">
        <div className="size-6 animate-spin rounded-full border-2 border-[var(--color-border)] border-t-[var(--color-primary)]" />
      </main>
    );
  }

  const userInitial = user?.name?.trim().charAt(0).toUpperCase() || "U";

  return (
    <div className="flex min-h-screen bg-[var(--color-background)]">
      <a href="#main-content" className="fixed left-3 top-3 z-[70] -translate-y-20 rounded bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow focus:translate-y-0">
        Lewati ke konten utama
      </a>

      {mobileOpen && (
        <button
          type="button"
          aria-label="Tutup navigasi"
          className="fixed inset-0 z-40 bg-slate-950/50 lg:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      <aside
        className={[
          "fixed inset-y-0 left-0 z-50 flex flex-col border-r border-slate-800 bg-slate-950 text-slate-100 transition-[width,transform] duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0",
          collapsed ? "w-16" : "w-64",
          mobileOpen ? "translate-x-0" : "-translate-x-full",
          "max-lg:w-72",
        ].join(" ")}
      >
        <div className="flex h-14 shrink-0 items-center border-b border-slate-800 px-3">
          <Link href="/dashboard" className="flex min-w-0 flex-1 items-center gap-2.5 rounded focus-visible:outline-blue-400">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-blue-600 text-sm font-bold text-white">S</span>
            {(!collapsed || mobileOpen) && (
              <span className="min-w-0">
                <span className="block truncate text-sm font-bold tracking-wide">Stitchra ERP</span>
                <span className="block truncate text-[10px] uppercase tracking-wider text-slate-400">Manufacturing platform</span>
              </span>
            )}
          </Link>
          <button type="button" onClick={() => setMobileOpen(false)} aria-label="Tutup sidebar" className="rounded p-2 text-slate-400 hover:bg-slate-800 hover:text-white lg:hidden">
            <NavigationIcon name="collapse" />
          </button>
        </div>

        <SidebarContent
          pathname={pathname}
          search={search}
          collapsed={collapsed && !mobileOpen}
          openGroups={openGroups}
          onToggleGroup={toggleGroup}
          onNavigate={() => setMobileOpen(false)}
        />

        <button
          type="button"
          onClick={toggleCollapsed}
          aria-label={collapsed ? "Perluas sidebar" : "Ringkas sidebar"}
          className="hidden min-h-11 items-center justify-center gap-2 border-t border-slate-800 text-xs font-medium text-slate-400 hover:bg-slate-900 hover:text-white lg:flex"
        >
          <NavigationIcon name="collapse" className={collapsed ? "rotate-180" : ""} />
          {!collapsed && <span>Ringkas sidebar</span>}
        </button>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between border-b border-[var(--color-border-subtle)] bg-[var(--color-surface)]/95 px-4 backdrop-blur sm:px-5 lg:px-6">
          <div className="flex min-w-0 items-center gap-3">
            <button
              type="button"
              onClick={() => setMobileOpen(true)}
              aria-label="Buka navigasi"
              aria-expanded={mobileOpen}
              className="inline-flex size-9 items-center justify-center rounded-[var(--radius-control)] border border-[var(--color-border)] text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] lg:hidden"
            >
              <NavigationIcon name="menu" />
            </button>
            <div className="min-w-0">
              <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-[var(--color-text-muted)]">
                {NAV_GROUPS.find((group) => group.items.some((item) => item.href === activeItem?.href))?.label ?? "Stitchra"}
              </p>
              <p className="truncate text-sm font-semibold text-[var(--color-text)]">{activeItem?.label ?? "Workspace"}</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <div className="hidden items-center gap-2 border-r border-[var(--color-border-subtle)] pr-3 sm:flex">
              {companyId && (
                <span className="hidden rounded-full border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] px-2.5 py-1 text-xs font-medium tabular-nums text-[var(--color-text-muted)] lg:inline-flex">
                  Company #{companyId}
                </span>
              )}
              <span className="flex size-8 items-center justify-center rounded-full bg-[var(--color-primary-soft)] text-xs font-bold text-[var(--color-primary)]">
                {userInitial}
              </span>
              <div className="max-w-40">
                <p className="truncate text-sm font-medium text-[var(--color-text)]">{user?.name}</p>
                {user?.roles?.[0] && <p className="truncate text-xs text-[var(--color-text-muted)]">{user.roles[0]}</p>}
              </div>
            </div>
            <Button variant="ghost" size="sm" onClick={logout} leadingIcon={<NavigationIcon name="logout" />}>
              <span className="hidden sm:inline">{t("auth.logout")}</span>
              <span className="sm:hidden">Keluar</span>
            </Button>
          </div>
        </header>

        <main id="main-content" tabIndex={-1} className="min-w-0 flex-1 p-4 sm:p-5 lg:p-6">
          <div className="mx-auto w-full max-w-[1600px]">{children}</div>
        </main>
      </div>
    </div>
  );
}
