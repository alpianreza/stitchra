# Modul Cutting

Cut order → issue/dispatch roll → marker consumption → bundle → leftover return.

## Invariants

- Cut order terkunci dan tidak boleh melebihi matrix SO.
- Marker hanya menerima roll RELEASED dari fabric BOM snapshot MO.
- Kain dapat memakai basis `MTR` atau `YRD`; input dikonversi ke `use_uom_id` material.
- Marker hanya boleh mengonsumsi saldo dispatch MO×roll, bukan seluruh sisa fisik roll.
- Kuantitas input basis dan ekuivalen meter disimpan untuk audit/backward compatibility.
- Completion membutuhkan bundle lengkap dan menyimpan actual consumption pada MO allocation, bukan BOM approved.

## Verification

Regression evidence mencakup over-cut, marker-before-issue, batas dispatch, consumption, bundle, dan leftover return. Runtime belum dinyatakan hijau sampai lockfile dan CI tersedia.
