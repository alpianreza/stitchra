# Modul Core

Fondasi Stitchra ERP: auth, RBAC, approval engine, document numbering, audit log, settings, notifikasi.

## Services
| Service | Rule | Catatan |
|---|---|---|
| `NumberingService` | BR-010 | `next(companyId, docType)` — row lock, concurrency-safe, no reuse |
| `AuditService` | BR-016 | insert-only ke `audit_logs` |
| `ApprovalEngine` | BR-015 | submit/approve/reject/revision/delegation + events |

## Middleware
- `company` (ResolveCompany) — scope company aktif (BR-011), header `X-Company-Id`.
- `permission:` (EnsurePermission) — cek `domain.entity.action` server-side (BR-110).

## Model
Semua model tenant memakai trait `BelongsToCompany` (auto-filter & auto-fill `company_id`).
`AuditLog` append-only (`UPDATED_AT = null`).

## Testing
```bash
docker exec stitchra-api php artisan test
# atau
docker exec stitchra-api ./vendor/bin/pest
```
