"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";
import { setToken, setUser } from "@/lib/auth";
import { t } from "@/lib/i18n";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const res = await api.post<{ token: string; user: unknown }>("/auth/login", { email, password });
      setToken(res.token);
      setUser(res.user);
      router.replace("/dashboard");
    } catch (err: any) {
      setError(err?.message ?? t("login.failed"));
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-100">
      <form onSubmit={onSubmit} className="w-full max-w-sm rounded-xl bg-white p-8 shadow">
        <h1 className="mb-6 text-2xl font-bold">Stitchra ERP</h1>

        <label className="mb-1 block text-sm font-medium">{t("login.email")}</label>
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
          autoFocus
          className="mb-4 w-full rounded border px-3 py-2"
        />

        <label className="mb-1 block text-sm font-medium">{t("login.password")}</label>
        <input
          type="password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          className="mb-4 w-full rounded border px-3 py-2"
        />

        {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

        <button
          type="submit"
          disabled={loading}
          className="w-full rounded bg-slate-900 py-2 font-medium text-white disabled:opacity-50"
        >
          {loading ? t("login.loading") : t("login.submit")}
        </button>
      </form>
    </main>
  );
}
