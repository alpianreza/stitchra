# PHASE 1 — CORE FOUNDATION (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 1, MODULE_MAP §2.1, DATABASE_BLUEPRINT §3.1, BUSINESS_RULES BR-010/011/015/016/110-113, DEC-2026-08-13-02/03
> **Aturan fase:** Analyze → Dependencies → Risks → Propose → (approval bila arsitektural) → Implement → Test → Review → Document.

## Objective
Menyediakan fondasi yang dipakai semua modul bisnis: auth, RBAC, approval engine, document numbering, audit log, settings, organisasi, notifikasi dasar — dengan kualitas production-grade (concurrency-safe, ter-audit, teruji).

## Current State
Blueprint LOCKED v1.x. Repo berisi `docs/` + scaffold infra. Belum ada kode aplikasi.

## Scope (dari ROADMAP)
1. Infra on-prem Docker: MySQL 8, Redis 8, Nginx, MinIO (S3), Horizon, Reverb.
2. Laravel modular monolith: `app/Modules/<Domain>` per Module Map.
3. Auth: Sanctum (SPA + token shop floor), lockout + rate limit (BR-111).
4. RBAC: roles/permissions/user_roles + middleware `can:domain.entity.action` server-side (BR-110) + company scope global (BR-011).
5. Approval engine terpusat (BR-015): flows, requests, step instances; sequential+parallel; delegation; history.
6. Numbering service (BR-010): counter per (company, prefix, tahun), concurrency-safe.
7. Audit log append-only (BR-016) via observer/interceptor.
8. Settings + Notification dasar. Seeder: 16 role dari ROLES_PERMISSIONS.

## Business Rules yang Diimplementasikan
BR-010 (numbering), BR-011 (company_id scope), BR-015 (approval), BR-016 (audit append-only), BR-110 (server-side permission), BR-111 (auth security), BR-112 (CSRF/XSS/injection), BR-113 (no secrets in repo).

## Technical Design
- `Modules/Core/Services/NumberingService`: `next(companyId, docType)` → lock baris counter (`SELECT ... FOR UPDATE` dalam DB transaction) → format `PREFIX-YYYY-NNNNNN`. Tidak pernah reuse (BR-010).
- `Modules/Core/Services/AuditService`: `record(action, model, before, after, request)` → insert-only ke `audit_logs` (BR-016). Dipasang via model observer global untuk model yang terdaftar + manual untuk aksi (submit/approve/...).
- `Modules/Core/Approval`: `ApprovalEngine::submit(document)` membuat request dari flow aktif; `approve()/reject()/delegate()` mencatat step instances; event `document.approved` dsb.
- Middleware `ResolveCompany`: dari header/user → bind company aktif; global scope Eloquent `where company_id` untuk semua model tenant (BR-011).
- Middleware `permission:` memetakan route → `domain.entity.action` (BR-110).
- DB: tabel §3.1 Database Blueprint (companies, factories, users, roles, permissions, role_permissions, user_roles, user_companies, approval_flows, approval_flow_steps, approval_requests, approval_step_instances, doc_numbering_configs, doc_number_counters, audit_logs, settings, notifications).
- Portabilitas DB (DEC-03): VARCHAR+CHECK untuk status, tanpa fitur spesifik-engine; DECIMAL untuk uang/qty.

## Files To Change (batch)
1. ✅ Scaffold: README, docker-compose, nginx, Dockerfiles, composer.json, .env.example, package.json web.
2. Bootstrap Laravel: artisan, bootstrap/app.php, config/, public/index.php.
3. Migrations + Models Core.
4. Services: Numbering/Audit/Approval + middleware company/permission.
5. Auth (Sanctum) + seeder RBAC 16 role.
6. Tests + docs modul.

## Database Changes
Hanya tabel Core (Database Blueprint §3.1). Tidak ada tabel bisnis di fase ini.

## Testing (DoD)
- **Concurrency:** 100 request paralel minta nomor dokumen → 100 nomor unik tanpa gap tak terduga (BR-010).
- **Permission:** user tanpa permission → 403 (server-side, bukan hanya UI).
- **Audit:** create/update/approve tercatat dengan before→after benar; tidak bisa dihapus via API.
- **Company scope:** user company A tidak bisa baca data company B.
- Pest (unit/feature) + Playwright smoke (login).

## Risks
| Risiko | Mitigasi |
|---|---|
| Fondasi salah → rework semua fase | Review arsitektur akhir fase + approval pemilik |
| Race di counter nomor | Lock DB + test konkurensi 100 paralel |
| Scope creep | Hanya modul Core; bisnis mulai Phase 2 |

## Open Decisions
Tidak ada (TD-01/TD-02 resolved).

## Next Step
Batch 2: bootstrap Laravel + config + migrasi Core.
