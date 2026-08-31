# Modul Cutting

Cut order → material issue roll → marker consumption → bundle.

## Invariants

- Cut order hanya untuk MO `RELEASED/CUTTING` dan dibuat di bawah lock MO.
- Colorway/size wajib berasal dari matrix SO untuk style MO.
- Total cut aktif per colorway×size tidak boleh melebihi qty SO.
- Matrix line unik per cut order; bundle generation dikunci dan hanya boleh sekali.
- Marker hanya menerima roll `RELEASED`, company yang sama, dan material fabric pada BOM snapshot MO.
- Cumulative marker consumption tidak boleh melebihi cumulative material issue untuk MO×roll.
- Completion wajib memiliki bundle lengkap dengan total sama dengan qty cut.
- Actual consumption disimpan pada `mo_material_allocations`, bukan menulis BOM `APPROVED`.

## Verification status

Regression tests tersedia untuk over-cut, marker-before-issue rejection, per-roll actual consumption, exact bundle quantity, dan duplicate generation. Runtime result belum dinyatakan hijau sampai lockfiles dan CI tersedia.
