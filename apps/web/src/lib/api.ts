const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

function headers(withAuth = true): HeadersInit {
  const h: HeadersInit = { "Content-Type": "application/json", Accept: "application/json" };
  if (withAuth && typeof window !== "undefined") {
    const token = localStorage.getItem("stitchra_token");
    if (token) h["Authorization"] = `Bearer ${token}`;
    const companyId = localStorage.getItem("stitchra_company");
    if (companyId) h["X-Company-Id"] = companyId;   // BR-011 — company scope
  }
  return h;
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const res = await fetch(`${API_URL}/api${path}`, {
    method,
    headers: headers(),
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  if (!res.ok) {
    let message = `HTTP ${res.status}`;
    try {
      const data = await res.json();
      message = data.message ?? message;
    } catch {}
    throw new Error(message);
  }

  return res.status === 204 ? (undefined as T) : res.json();
}

export const api = {
  get: <T>(path: string) => request<T>("GET", path),
  post: <T>(path: string, body: unknown) => request<T>("POST", path, body),
  put: <T>(path: string, body: unknown) => request<T>("PUT", path, body),
  delete: <T>(path: string) => request<T>("DELETE", path),
};

/**
 * Upload multipart (mis. import CSV master). Headers auth tanpa Content-Type
 * agar browser men-set multipart boundary otomatis.
 */
export async function apiUpload<T>(path: string, form: FormData): Promise<T> {
  const h: Record<string, string> = { Accept: "application/json" };
  if (typeof window !== "undefined") {
    const token = localStorage.getItem("stitchra_token");
    if (token) h["Authorization"] = `Bearer ${token}`;
    const companyId = localStorage.getItem("stitchra_company");
    if (companyId) h["X-Company-Id"] = companyId;
  }
  const res = await fetch(`${API_URL}/api${path}`, { method: "POST", headers: h, body: form });
  if (!res.ok) {
    let message = `HTTP ${res.status}`;
    try { const data = await res.json(); message = data.message ?? message; } catch {}
    throw new Error(message);
  }
  return res.status === 204 ? (undefined as T) : res.json();
}