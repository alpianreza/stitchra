import Link from "next/link";

export default function PackingLayout({ children }: { children: React.ReactNode }) {
  return <div className="space-y-4">
    <nav className="flex gap-2 rounded-xl border bg-white p-2 text-sm">
      <Link href="/packing/instructions" className="rounded px-3 py-2 font-medium hover:bg-slate-100">Packing Instructions</Link>
      <Link href="/packing/lists" className="rounded px-3 py-2 font-medium hover:bg-slate-100">Packing List & FG</Link>
    </nav>
    {children}
  </div>;
}
