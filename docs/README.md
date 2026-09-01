---
title: Stitchra Documentation Index
status: ACTIVE
version: 1.1
last_updated: 2026-09-01
authority: GOVERNANCE
---

# Stitchra Documentation

Dokumen ini adalah pintu masuk resmi dokumentasi Stitchra. Dokumen bisnis yang sudah dikunci tetap dipertahankan pada path asal agar isi, riwayat, dan tautan historis tidak rusak. Folder bernomor menyediakan hierarchy dan navigation layer tanpa menggandakan business rule. Seluruh implementation record menggunakan istilah resmi **Phase**.

## Documentation Map

| Tier | Area | Canonical document | Purpose | Status |
|---|---|---|---|---|
| 1 | Current state | [Project Status](./00-governance/PROJECT_STATUS.md) | Kondisi implementasi, blocker, konfigurasi, dan keputusan production | ACTIVE |
| 1 | Decisions | [Decision Log](./DECISION_LOG.md) | Keputusan bisnis dan arsitektur yang mengikat | ACTIVE |
| 2 | Business specification | [Business Specification](./ERP_GARMENT_BUSINESS_SPECIFICATION.md) | Scope dan perilaku bisnis yang disetujui | LOCKED v1.1 |
| 2 | Business rules | [Business Rules](./ERP_GARMENT_BUSINESS_RULES.md) | Sumber tunggal BR-xxx | LOCKED v1.2 |
| 2 | Modules | [Module Map](./ERP_GARMENT_MODULE_MAP.md) | Domain ownership dan dependency | LOCKED v1.0 |
| 2 | Processes | [Process Flow](./ERP_GARMENT_PROCESS_FLOW.md) | Alur end-to-end dan efek stok | LOCKED v1.0 |
| 2 | Roles | [Roles & Permissions](./ERP_GARMENT_ROLES_PERMISSIONS.md) | Model RBAC bisnis | LOCKED v1.1 |
| 3 | Database | [Database Blueprint](./ERP_GARMENT_DATABASE_BLUEPRINT.md) | Struktur data dan constraints | LOCKED v1.0 |
| 3 | Endpoint authorization | [Permission Map](./PERMISSION_MAP.md) | Mapping endpoint ke permission code | LOCKED |
| 3 | Blueprint review | [Blueprint Review](./BLUEPRINT_REVIEW.md) | Bukti review konsistensi blueprint | HISTORICAL |
| 4 | Planning | [Implementation Roadmap](./ERP_GARMENT_IMPLEMENTATION_ROADMAP.md) | Rencana awal Phase 01–09 | LOCKED baseline; sebagian historis |
| 5 | Discovery | [Phase 00 Business Discovery](./FASE_0_BUSINESS_DISCOVERY.md) | Discovery dan asal OBD | LOCKED historical baseline |
| 5 | Implementation history | [Phase records](./04-phases/README.md) | Catatan Phase 01–09 dan Phase 10A–10G | HISTORICAL |
| — | Deployment | [Containerization](../CONTAINERIZATION.md) | Operasi Docker dan deployment considerations | ACTIVE |

## Authority Hierarchy

Jika terdapat konflik, gunakan urutan berikut:

1. Business rule berstatus LOCKED di [Business Rules](./ERP_GARMENT_BUSINESS_RULES.md), dengan keputusan terbaru di [Decision Log](./DECISION_LOG.md).
2. [Business Specification](./ERP_GARMENT_BUSINESS_SPECIFICATION.md).
3. Architecture documents: [Module Map](./ERP_GARMENT_MODULE_MAP.md), [Process Flow](./ERP_GARMENT_PROCESS_FLOW.md), [Database Blueprint](./ERP_GARMENT_DATABASE_BLUEPRINT.md), dan [Permission Map](./PERMISSION_MAP.md).
4. [Current Project Status](./00-governance/PROJECT_STATUS.md) untuk kondisi implementasi saat ini; status tidak mengubah business rule.
5. [Implementation Roadmap](./ERP_GARMENT_IMPLEMENTATION_ROADMAP.md) sebagai baseline perencanaan.
6. Phase records sebagai bukti historis implementasi. Phase record tidak menggantikan authority bisnis.

Keputusan dengan tanggal lebih baru dapat menggantikan keputusan lama hanya jika supersession dinyatakan eksplisit. Contoh yang sudah tercatat: DEC-2026-08-13-03 menggantikan keputusan sementara OBD-024.

## How to Navigate

- Memahami scope bisnis: mulai dari [Business Specification](./ERP_GARMENT_BUSINESS_SPECIFICATION.md).
- Menemukan aturan yang mengikat: gunakan [Business Rules](./ERP_GARMENT_BUSINESS_RULES.md), lalu telusuri asal keputusan di [Decision Log](./DECISION_LOG.md).
- Memahami domain dan ownership: baca [Module Map](./ERP_GARMENT_MODULE_MAP.md).
- Memahami alur operasional: baca [Process Flow](./ERP_GARMENT_PROCESS_FLOW.md).
- Memahami arsitektur data: baca [Database Blueprint](./ERP_GARMENT_DATABASE_BLUEPRINT.md).
- Memahami permission: gunakan [Roles & Permissions](./ERP_GARMENT_ROLES_PERMISSIONS.md) untuk intent bisnis dan [Permission Map](./PERMISSION_MAP.md) untuk kontrak endpoint aktual.
- Mengetahui kondisi saat ini: baca [Project Status](./00-governance/PROJECT_STATUS.md).
- Menelusuri implementasi lampau: buka [Phase History](./04-phases/README.md).
- Menjalankan repository: baca [root README](../README.md) dan [Containerization Guide](../CONTAINERIZATION.md).

## Governance Rules

- Istilah resmi lifecycle implementasi adalah **Phase**, termasuk Phase 10A–10G.
- Business rule baru atau perubahan rule harus melalui keputusan yang dicatat di Decision Log.
- PROJECT_STATUS hanya berisi current state, configuration, open items, dan ringkasan history.
- Implementation detail fase disimpan sebagai historical phase record, bukan disalin ke status proyek.
- Dokumen lain harus menautkan Business Rules daripada menyalin daftar BR-xxx.
- Gunakan relative links.
- Status yang digunakan: LOCKED, ACTIVE, DRAFT, HISTORICAL, SUPERSEDED.
- Update `last_updated` ketika isi substantif berubah.

## Directory Navigation

- [00 — Governance](./00-governance/README.md)
- [01 — Business](./01-business/README.md)
- [02 — Architecture](./02-architecture/README.md)
- [03 — Roadmap](./03-roadmap/README.md)
- [04 — Phase History](./04-phases/README.md)
