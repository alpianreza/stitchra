"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { clearAuth, getToken, getUser } from "@/lib/auth";
import { api } from "@/lib/api";
import { t } from "@/lib/i18n";
import { masterEntities, masterMenuOrder } from "@/lib/masterMeta";

const NAV = [
  { href: "/dashboard", label: "Dasbor" },
  { href: "/approvals", label: "Approval" },
  { href: "/sales/orders", label: "Sales Order" },
  { href: "/pd/boms", label: "BOM" },
  { href: "/pd/routings", label: "Routing" },
  { href: "/pd/cost-sheets", label: "Cost Sheet" },
  { href: "/planning/mrp", label: "MRP" },
  { href: "/purchasing/prs", label: "Purchase Request" },
  { href: "/purchasing/pos", label: "Purchase Order" },
  { href: "/production/orders", label: "Manufacturing Order" },
  { href: "/shopfloor/scan", label: "Stasiun Scan" },
  { href: "/receiving/grs", label: "Goods Receipt" },
  { href: "/receiving/inspections", label: "Inward QC (FQC)" },
  { href: "/inventory/stock", label: "Inquiry Stok" },
  { href: "/qc/inspections", label: "Inspeksi QC" },
  { href: "/packing/lists", label: "Packing List" },
  { href: "/shipping/shipments", label: "Shipment" },
  { href: "/subcon/orders", label: "Subcon" },
  { href: "/finance/journals", label: "Jurnal" },
  { href: "/finance/costing", label: "Costing Aktual" },
  { href: "/finance/bep", label: "BEP" },
  { href: "/reports", label: "Laporan" },
];

const ADMIN_NAV = [
  { href: "/approvals/flows", label: "Approval Flow" },
];

export default function AppShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<{ name: string; roles?: string[] } | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (!getToken()) {
      router.replace("/login");
      return;
    }
    setUser(getUser());
    setReady(true);
  }, [router]);

  async function logout() {
    try { await api.post("/auth/logout", {}); } catch {}
    clearAuth();
    router.replace("/login");
  }

  if (!ready) return null;

  return (
    <div className="flex min-h-screen">
      <aside className="w-56 shrink-0 border-r bg-slate-900 text-slate-100">
        <div className="px-4 py-5 text-lg font-bold">Stitchra ERP</div>
        <nav className="flex max-h-[calc(100vh-80px)] flex-col gap-1 overflow-y-auto px-2 text-sm">
          {NAV.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={`rounded px-3 py-2 hover:bg-slate-800 ${pathname.startsWith(item.href.split("/").slice(0, 2).join("/")) ? "bg-slate-800 font-medium" : ""}`}
            >
              {item.label}
            </Link>
          ))}

          <div className="mt-4 px-3 text-xs uppercase tracking-wide text-slate-400">Master Data</div>
          {masterMenuOrder.map((slug) => (
            <Link
              key={slug}
              href={`/master/${slug}`}
              className={`rounded px-3 py-2 hover:bg-slate-800 ${pathname === `/master/${slug}` ? "bg-slate-800 font-medium" : ""}`}
            >
              {masterEntities[slug].title}
            </Link>
          ))}

          <div className="mt-4 px-3 text-xs uppercase tracking-wide text-slate-400">Admin</div>
          {ADMIN_NAV.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={`rounded px-3 py-2 hover:bg-slate-800 ${pathname === item.href ? "bg-slate-800 font-medium" : ""}`}
            >
              {item.label}
            </Link>
          ))}
        </nav>
      </aside>

      <div className="flex flex-1 flex-col">
        <header className="flex items-center justify-between border-b bg-white px-6 py-3">
          <span className="text-sm text-slate-500">{user?.name}</span>
          <button onClick={logout} className="rounded border px-3 py-1 text-sm">
            {t("auth.logout")}
          </button>
        </header>
        <main className="flex-1 bg-slate-50 p-6">{children}</main>
      </div>
    </div>
  );
}
