"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { getToken } from "@/lib/auth";

/** Root: redirect ke dashboard bila sudah login, else login */
export default function Home() {
  const router = useRouter();

  useEffect(() => {
    router.replace(getToken() ? "/dashboard" : "/login");
  }, [router]);

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-100">
      <p className="text-slate-500">Mengalihkan…</p>
    </main>
  );
}
