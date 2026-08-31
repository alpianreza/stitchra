# Modul Shop Floor

Scan bundle per operasi untuk WIP real-time, finishing transition, dan controlled rework.

## Invariants

- Scan mengunci bundle dan MO sehingga concurrent duplicate tidak dapat melewati state check.
- Database unique backstop membatasi satu `IN` dan satu `OUT` per bundle×operation×stage.
- Production scan bersifat append-only.
- `OUT` membutuhkan `IN`; sewing operation berikutnya membutuhkan predecessor `OUT`.
- Finishing ditolak sampai seluruh routing operation sudah `OUT` dari sewing.
- Operation wajib berasal dari routing snapshot MO.
- Line, employee, bundle, MO, WIP, dan daily output semuanya company-scoped.
- MO hanya maju: `CUTTING → SEWING → FINISHING`.
- Rework wajib memakai active defect library, qty tidak melebihi bundle, menahan bundle pada status `REWORK`, dan membutuhkan resolve eksplisit sebelum scan berlanjut.
- Route scan/rework memakai `production.output.create`; WIP/daily output memakai `production.output.view`.

## Device/security decision

Keyboard-wedge scanner melalui browser tetap baseline. Offline queue, replay key, device enrollment, dan pemisahan browser-session versus device token masih membutuhkan desain deployment tersendiri sebelum pilot.

## Verification status

Regression tests tersedia untuk ordering, duplicate direction, finishing gate, append-only scan, tenant-scoped WIP, dan rework lifecycle. Runtime result belum dinyatakan hijau sampai lockfiles dan CI tersedia.
