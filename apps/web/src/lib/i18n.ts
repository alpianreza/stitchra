/**
 * i18n skeleton (OBD-030) — UI siap multi-language; default Bahasa Indonesia.
 * Terjemahan lengkap menyusul; label master data tetap satu bahasa.
 */
const id: Record<string, string> = {
  "login.email": "Email",
  "login.password": "Kata sandi",
  "login.submit": "Masuk",
  "login.loading": "Memproses…",
  "login.failed": "Login gagal. Periksa email & kata sandi.",
  "dashboard.title": "Dasbor",
  "auth.logout": "Keluar",
};

const en: Record<string, string> = {
  "login.email": "Email",
  "login.password": "Password",
  "login.submit": "Sign in",
  "login.loading": "Signing in…",
  "login.failed": "Login failed. Check your email & password.",
  "dashboard.title": "Dashboard",
  "auth.logout": "Sign out",
};

const dicts: Record<string, Record<string, string>> = { id, en };

export function t(key: string, locale: string = "id"): string {
  return dicts[locale]?.[key] ?? dicts.id[key] ?? key;
}
