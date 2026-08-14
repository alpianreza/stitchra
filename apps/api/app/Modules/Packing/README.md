# Modul Packing

Packing list per karton (barcode), ratio check vs SO matrix, FG masuk gudang FG.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/packing/lists/from-so/{soId}` | `packing.list.create` | SO CONFIRMED/IN_PROGRESS |
| POST | `/api/packing/lists/{id}/cartons` | `packing.list.update` | karton + isi matrix |
| POST | `/api/packing/lists/{id}/finalize` | `packing.list.finalize` | **BR-082**: wajib QC FINAL PASS + ratio check |
| GET | `/api/packing/lists/{id}` | `packing.list.view` | detail karton |

## Aturan bisnis
- **BR-082**: finalize memposting `PRODUCTION_RECEIPT` (FG) via ITS — hanya setelah QC PASS; MO → PACKED.
- **BR-021**: packed qty per matrix tidak boleh melebihi order + toleransi buyer.
- Karton: `carton_no = {PL-doc}-{seq}` unik (barcode); gross/net weight per karton.
