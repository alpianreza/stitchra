"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, Input, PageHeader, Select } from "@/components/ui";

interface Device { id: number; name: string; platform?: string | null; status?: string | null; last_seen_at?: string | null }
interface Mo { id: number; doc_no: string; status: string }
interface Line { id: number; name: string; code?: string }

function GenericView({ data }: { data: unknown }) {
  if (data === null || data === undefined) {
    return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
  }
  if (Array.isArray(data)) {
    if (data.length === 0) return <p className="text-sm text-[var(--color-text-muted)]">Tidak ada data.</p>;
    if (typeof data[0] === "object" && data[0] !== null) {
      const keys = Object.keys(data[0] as Record<string, unknown>);
      return (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                {keys.map((k) => <th key={k} className="py-1.5 pr-3">{k}</th>)}
              </tr>
            </thead>
            <tbody>
              {data.map((row, i) => (
                <tr key={i} className="border-b last:border-0">
                  {keys.map((k) => (
                    <td key={k} className="py-1.5 pr-3">{String((row as Record<string, unknown>)[k] ?? "-")}</td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      );
    }
    return <ul className="list-disc space-y-0.5 pl-5 text-sm">{data.map((x, i) => <li key={i}>{String(x)}</li>)}</ul>;
  }
  if (typeof data === "object") {
    return (
      <dl className="grid gap-2 text-sm sm:grid-cols-2">
        {Object.entries(data as Record<string, unknown>).map(([k, v]) => (
          <div key={k}>
            <dt className="text-xs text-[var(--color-text-muted)]">{k}</dt>
            <dd className="font-medium">{typeof v === "object" ? JSON.stringify(v) : String(v)}</dd>
          </div>
        ))}
      </dl>
    );
  }
  return <p className="text-sm">{String(data)}</p>;
}

/** Monitor Shop Floor: perangkat scan terdaftar, WIP per stage per MO, dan output harian per line. */
export default function ShopFloorMonitorPage() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [deviceName, setDeviceName] = useState("");
  const [devicePlatform, setDevicePlatform] = useState("");
  const [deviceBusy, setDeviceBusy] = useState(false);
  const [deviceError, setDeviceError] = useState<string | null>(null);

  const [mos, setMos] = useState<Mo[]>([]);
  const [wipMo, setWipMo] = useState("");
  const [wipData, setWipData] = useState<unknown>(null);
  const [wipLoading, setWipLoading] = useState(false);
  const [wipError, setWipError] = useState<string | null>(null);

  const [lines, setLines] = useState<Line[]>([]);
  const [outLine, setOutLine] = useState("");
  const [outDate, setOutDate] = useState(new Date().toISOString().slice(0, 10));
  const [outData, setOutData] = useState<unknown>(null);
  const [outLoading, setOutLoading] = useState(false);
  const [outError, setOutError] = useState<string | null>(null);

  function loadDevices() {
    api.get<{ data: Device[] }>("/shopfloor/devices").then((r) => setDevices(r.data)).catch((e) => setDeviceError(e.message));
  }

  useEffect(() => {
    loadDevices();
    api.get<{ data: Mo[] }>("/production/orders?per_page=100").then((r) => setMos(r.data)).catch(() => {});
    api.get<{ data: Line[] }>("/master/lines?per_page=100").then((r) => setLines(r.data)).catch(() => {});
  }, []);

  async function enrollDevice() {
    if (!deviceName) return;
    setDeviceBusy(true); setDeviceError(null);
    try {
      await api.post("/shopfloor/devices", { name: deviceName, platform: devicePlatform || undefined });
      setDeviceName(""); setDevicePlatform("");
      loadDevices();
    } catch (e) {
      setDeviceError(e instanceof Error ? e.message : "Gagal mendaftarkan perangkat");
    } finally { setDeviceBusy(false); }
  }

  async function revokeDevice(id: number) {
    setDeviceBusy(true); setDeviceError(null);
    try {
      await api.delete(`/shopfloor/devices/${id}`);
      loadDevices();
    } catch (e) {
      setDeviceError(e instanceof Error ? e.message : "Gagal mencabut perangkat");
    } finally { setDeviceBusy(false); }
  }

  async function loadWip() {
    if (!wipMo) return;
    setWipLoading(true); setWipError(null);
    try {
      const r = await api.get<{ data: unknown }>(`/shopfloor/wip/${wipMo}`);
      setWipData(r.data);
    } catch (e) {
      setWipError(e instanceof Error ? e.message : "Gagal memuat WIP");
    } finally { setWipLoading(false); }
  }

  async function loadDailyOutput() {
    if (!outLine) return;
    setOutLoading(true); setOutError(null);
    try {
      const r = await api.get<{ data: unknown }>(`/shopfloor/lines/${outLine}/daily-output${outDate ? `?date=${outDate}` : ""}`);
      setOutData(r.data);
    } catch (e) {
      setOutError(e instanceof Error ? e.message : "Gagal memuat output harian");
    } finally { setOutLoading(false); }
  }

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Manufacturing"
        title="Monitor Shop Floor"
        description="Perangkat scan terdaftar, WIP per stage per MO, dan output harian per line."
      />

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Perangkat Scan</h2>
        <div className="mt-2 flex flex-wrap items-end gap-2">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Nama perangkat *</span>
            <Input value={deviceName} onChange={(e) => setDeviceName(e.target.value)} placeholder="mis. Tablet Cutting-1" className="w-56" />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Platform</span>
            <Input value={devicePlatform} onChange={(e) => setDevicePlatform(e.target.value)} placeholder="mis. android" className="w-40" />
          </label>
          <Button loading={deviceBusy} disabled={!deviceName} onClick={enrollDevice}>Daftarkan</Button>
        </div>
        {deviceError && <p role="alert" className="mt-2 text-sm text-[var(--color-danger)]">{deviceError}</p>}
        <div className="mt-3 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
                <th className="py-1.5 pr-3">Nama</th>
                <th className="py-1.5 pr-3">Platform</th>
                <th className="py-1.5 pr-3">Status</th>
                <th className="py-1.5" />
              </tr>
            </thead>
            <tbody>
              {devices.map((d) => (
                <tr key={d.id} className="border-b last:border-0">
                  <td className="py-1.5 pr-3 font-medium">{d.name}</td>
                  <td className="py-1.5 pr-3">{d.platform ?? "-"}</td>
                  <td className="py-1.5 pr-3">{d.status ?? "-"}</td>
                  <td className="py-1.5 text-right">
                    <Button size="sm" variant="danger" onClick={() => revokeDevice(d.id)}>Cabut</Button>
                  </td>
                </tr>
              ))}
              {devices.length === 0 && <tr><td colSpan={4} className="py-3 text-center text-[var(--color-text-muted)]">Belum ada perangkat terdaftar.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">WIP per Stage (per MO)</h2>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Select value={wipMo} onChange={(e) => setWipMo(e.target.value)} className="w-80">
            <option value="">- pilih MO -</option>
            {mos.map((m) => <option key={m.id} value={m.id}>{m.doc_no}</option>)}
          </Select>
          <Button variant="secondary" loading={wipLoading} disabled={!wipMo} onClick={loadWip}>Muat WIP</Button>
        </div>
        {wipError && <p role="alert" className="mt-2 text-sm text-[var(--color-danger)]">{wipError}</p>}
        <div className="mt-3">{wipData !== null && <GenericView data={wipData} />}</div>
      </section>

      <section className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
        <h2 className="font-semibold">Output Harian per Line</h2>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Select value={outLine} onChange={(e) => setOutLine(e.target.value)} className="w-72">
            <option value="">- pilih line -</option>
            {lines.map((l) => <option key={l.id} value={l.id}>{l.name ?? l.code}</option>)}
          </Select>
          <Input type="date" value={outDate} onChange={(e) => setOutDate(e.target.value)} className="w-44" />
          <Button variant="secondary" loading={outLoading} disabled={!outLine} onClick={loadDailyOutput}>Muat Output</Button>
        </div>
        {outError && <p role="alert" className="mt-2 text-sm text-[var(--color-danger)]">{outError}</p>}
        <div className="mt-3">{outData !== null && <GenericView data={outData} />}</div>
      </section>
    </div>
  );
}