export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("stitchra_token");
}

export function setToken(token: string) {
  localStorage.setItem("stitchra_token", token);
}

export function setUser(user: unknown) {
  localStorage.setItem("stitchra_user", JSON.stringify(user));
}

export function getUser<T>(): T | null {
  if (typeof window === "undefined") return null;
  const raw = localStorage.getItem("stitchra_user");
  return raw ? (JSON.parse(raw) as T) : null;
}

export function clearAuth() {
  localStorage.removeItem("stitchra_token");
  localStorage.removeItem("stitchra_user");
  localStorage.removeItem("stitchra_company");
}
