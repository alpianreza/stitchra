# Phase 10A — Fabric Length UOM & Leftover Return

## Decision

Panjang kain mendukung meter dan yard. Setiap material memiliki satu `use_uom_id`; semua stock ledger untuk material tersebut memakai unit itu. Konversi panjang standar adalah `1 yard = 0.9144 meter`.

## BR-042 lifecycle

`received physical quantity → warehouse stock → dispatched to MO → consumed by marker → returned leftover`.

Returnable quantity adalah `dispatched - consumed - returned`, bukan `roll physical remaining`. Pemisahan ini mencegah bagian roll yang belum pernah keluar gudang ditambahkan kembali untuk kedua kalinya.

## Controls

- Locked MO, roll, reservation, dispatch, and stock balance.
- Unique MO×roll dispatch and return.
- Database non-negative/ceiling check.
- Same-company material/UOM/warehouse validation.
- Same-origin warehouse return.
- Full leftover close and repeated-return rejection.
- Historical meter columns retained as compatibility/audit fields.

## Deployment caveat

Migration `000015` backfills historical dispatch from posted issue, marker, and return data. Deployment must run a preflight for duplicate MO×roll returns, mixed warehouse/UOM issues, and historical `consumed + returned > dispatched`. Clean migration and regression execution remain pending lockfiles/CI.
