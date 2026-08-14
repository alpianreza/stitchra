"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { clearAuth, getUser } from "@/lib/auth";
import { t } from "@/lib/i18n";

interface Me {
  name: string;
  email: string;
  roles: string[];
}

export default function DashboardPage() {
  const router = useRouter();
  const [user, setUser] = useState<Me | null>(null);

  useEffect(() => {
    const cached = getUser<Me>();
    if (cached) setUser(cached);

    api.get<{ user: Me }>("/auth/me")
      .then((res) => setUser(res.user))
      .catch(() => {
        clearAuth();
        router.replace("/login");
      });
  }, [router]);

  async function logout() {
    try { await api.post("/auth/logout", {}); } catch {}
    clearAuth();
    router.replace("/login");
  }

  return (
    <main className="min-h-screen bg-slate-50 p-6">
      <header className="mb-6 flex items-center justify-between">
        <h1 className="text-xl font-bold">Stitchra — {t("dashboard.title")}</h1>
        <div className="flex items-center gap-4">
          <span className="text-sm text-slate-600">{user?.name ?? "…"}</span>
          <button onClick={logout} className="rounded border px-3 py-1 text-sm">
            {t("auth.logout")}
          </button>
        </div>
      </header>

      <section className="rounded-xl border bg-white p-6 text-sm text-slate-600">
        <p className="font-medium text-slate-900">Phase 1 — Core Foundation ✅</p>
        <p className="mt-2">
          Login, RBAC, approval engine, document numbering, dan audit log sudah aktif di API.
          Modul bisnis dimulai Phase 2 (Master Data).
        </p>
        <p className="mt-2">
          Role Anda: <span className="font-mono">{user?.roles?.join(", ") || "-"}</span>
        </p>
      </section>
    </main>
  );
}
