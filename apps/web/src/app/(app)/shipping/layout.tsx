import Link from "next/link";

export default function ShippingLayout({ children }: { children: React.ReactNode }) {
  return <div className="space-y-4">
    <nav className="flex gap-2 rounded-xl border bg-white p-2 text-sm">
      <Link href="/shipping/delivery-schedules" className="rounded px-3 py-2 font-medium hover:bg-slate-100">Delivery Schedule</Link>
      <Link href="/shipping/shipments" className="rounded px-3 py-2 font-medium hover:bg-slate-100">Shipment & FG</Link>
    </nav>
    {children}
  </div>;
}
