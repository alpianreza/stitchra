# Iteration 28 — Product Development Completeness

Status: **IMPLEMENTED / SOURCE REVIEW ONLY / RUNTIME NOT RUN — USER REQUEST**

Date: 2026-09-07. Base: `a8d594ba45b12a50070f977cc93ce53aebf95acc`, the merge of Iteration 27 PR #1 into `main`.

The user explicitly requested continuing without tests because of execution time. No migration, API runtime, Pest, TypeScript check, Next build, Playwright, or concurrency run was performed for this iteration. No test files were added or modified. Source/diff review is not a passing runtime result.

## Authority and implemented scope

[PF-02](./ERP_GARMENT_PROCESS_FLOW.md#pf-02-product-development-style--bom--costing) defines style specs, per-size measurement charts, Tech Packs, PROTO/FIT/PP/TOP sample stages, buyer responses, and revisions as new versions. The [database blueprint](./ERP_GARMENT_DATABASE_BLUEPRINT.md) already defines their tables. Existing BOM, routing, costing, and SO confirmation authority is unchanged.

| Area | Delivered in code | Boundary |
|---|---|---|
| Style Spec | Version history, description/construction notes, revision notes, create/copy-to-new-version UI | No in-place update or new approval matrix |
| Measurement / Size Spec | Normalized POM × size lines, values, symmetric tolerance, explicit chart unit, version history and detail | No grading engine, inferred legacy unit, or automatic unit conversion |
| Tech Pack | Private binary upload/download, append-only versions, filename/MIME/size/hash metadata, upload replay key | No public URLs, file replacement, preview execution, OCR, or malware-scanning claim |
| Sample | Search/filter/list/detail, version per style/stage, explicit source-version links, revision lineage, buyer-response history | No automatic stage progression or inherited approval |
| Production gate | Explicit sample selection on a PLANNED MO; approval/revision revalidation and evidence snapshot at release | No hardcoded PP-only rule, automatic sample selection, or retroactive stock/lifecycle changes |

## Style and measurement versioning

- Writes lock the parent style before incrementing the version. The existing unique `(style_id, version)` constraints remain.
- `expected_version` rejects stale form submissions rather than overwriting another revision or silently creating a second revision on a stale retry.
- Style Spec requires description or construction notes. Historical records remain read-only through these endpoints.
- Measurements are normalized rows, not a JSON size matrix. Duplicate POM/size pairs and foreign-company sizes are rejected.
- Each new chart explicitly declares `CM`, `MM`, or `IN`; values must be positive and tolerances non-negative when supplied. Null tolerance means unspecified, not zero.
- Historical charts keep `unit = null`, displayed as unknown. Copying one into a new revision requires selecting a unit; no conversion or guess is performed.
- Existing master styles and sizes remain owned by Master Data. PD lookups do not grant master administration.

## Tech Pack storage

- New uploads store a generated UUID object name under a company/style-specific private path, then record a new version and audit entry.
- Metadata snapshots disk, original display filename, MIME type, byte size, SHA-256, uploader, and revision notes.
- Supported technical upload allowlist: PDF, PNG, JPEG, DOCX, XLSX, ZIP; file content type and extension are validated. Default maximum is 10 MiB and is configurable.
- Storage and DB failures do not intentionally leave a successful document: failure after a stored object triggers cleanup. DB retries are disabled around external file writes; a process crash or failed cleanup still requires orphan-object reconciliation.
- A stable `upload_key` makes same-payload retries return the saved upload; different payloads using that key are rejected.
- Versions are serialized with a style lock. No new unique constraint is imposed blindly over historical Tech Pack version numbers, which were not unique in the original schema.
- Downloads require authenticated `pd.techpack.view` plus company/style checks and are streamed as attachments with `nosniff`/private cache headers. File paths and disk names are hidden from API serialization.
- Legacy records without trustworthy storage metadata are not assigned a guessed disk/hash and cannot be downloaded through the new endpoint until reconciled.

### Configuration before runtime use

- Run dependency installation after review: `league/flysystem-aws-s3-v3` and its locked dependencies have been added. Only dependency resolution/lockfile writing was performed, with installation, scripts, and tests disabled.
- `PD_TECH_PACK_DISK` uses the existing `FILESYSTEM_DISK` fallback. Supported disks are private `local` or `s3`; production uses configured S3/MinIO credentials, endpoint, bucket, and a private bucket policy.
- `PD_TECH_PACK_MAX_KB` defaults to `10240`. PHP upload/post limits and the reverse-proxy request limit must exceed the file limit plus multipart overhead. One existing Nginx configuration uses a 20M request limit; the 10 MiB default leaves multipart overhead. Align deployment limits before increasing the configured file limit.
- Confirm storage persistence, access policy, backup, and malware-scanning requirements before production. None is claimed as runtime-verified here.

## Sample lifecycle and response evidence

- Each creation starts `PENDING`, uses existing `SMPL` numbering, and creates a new version for the selected style/stage under a style lock.
- A subsequent version links to the previous sample in that stage. Explicit revision requests must reference the latest sample. Existing historical version numbers are not rewritten.
- Style Spec, Measurement Chart, and Tech Pack version links are optional and explicitly selected. They must belong to the same style/company and never float to a later revision.
- Creation uses a stable `request_key`; retrying a saved request returns its original sample instead of incrementing the version again.
- Buyer responses are append-only `APPROVED`, `REJECTED`, or `COMMENTED` entries. New responses require buyer name and evidence/reference; rejection/comment also requires a comment.
- The authenticated internal user is recorded separately from the external buyer name. This records supplied buyer evidence; it is not a buyer portal, external identity verification, or a new internal approval flow.
- The latest response and `buyer_status` update atomically under style/sample locks. Response replay uses a per-sample request key and does not overwrite newer decisions.
- Superseded samples and ambiguous legacy duplicate stage/version records cannot receive new decisions; create/use the newest unambiguous revision.
- The previous controller required both route `pd.sample.submit` and internal `pd.sample.update`. This mismatch is removed: the existing endpoint's canonical `pd.sample.submit` permission governs recording buyer responses.

## Sample → MO gate

1. Planner explicitly selects an eligible sample on a `PLANNED` MO using `production.mo.update`.
2. Selection must match the MO company and style. The sample must be the newest unambiguous version **within its selected stage**, with `buyer_status = APPROVED` and the latest persisted buyer response also `APPROVED`.
3. MO release revalidates this authority while holding the MO/style locks, before the existing cost and inventory reservation work. Missing selection, pending/rejected/commented response, missing approval evidence, or a superseding revision blocks release.
4. Successful release stores the exact sample/stage/version/source references, approval ID, buyer-response evidence, internal recorder, verifier, and verification timestamp. The existing release audit includes this snapshot.
5. Failure of later cost/reservation work rolls back the new gate snapshot with the existing release transaction.
6. Sample selection cannot change on a running MO. If the existing unrelease flow returns an unissued MO to `PLANNED`, the planner may select another sample; the prior snapshot is retained in append-only audit before being replaced/cleared.
7. A later response/revision does not silently stop a running MO, reverse reservations, or change stock. Current eligibility and the evidence used at the last release are shown separately.

**Rollout impact:** all subsequent calls to the existing MO release service require this explicit sample selection, including pre-existing PLANNED MOs. Previously released/running MOs are not backfilled or automatically changed. Future regression fixtures that release MOs will need explicit approved-sample evidence; the previous iteration's passing counts cannot be carried forward.

### NEED BUSINESS DECISION — not silently invented

- Which sample stage is mandatory per buyer/style/order, whether PP is universally required, and whether earlier stages must be approved sequentially.
- Whether all three development artifacts are mandatory before sample approval or production release.
- Whether a sample must match specific BOM/routing/costing versions beyond the current explicit style/source references.
- Buyer approval expiry, waiver authority, authenticated buyer portal, and disposition of running MOs after a later rejection.

The implemented gate is **explicit approved-sample selection**, not a claim that any particular stage is business-approved for every production order. No PSO meaning, lifecycle, numbering, approval, or relations are defined in this iteration.

## API and UI

- `/pd/styles`: Style Spec, Size Spec, and Tech Pack workbench, including paginated version history and private download.
- `/pd/samples`: searchable sample workbench, source-version selection, new revisions, append-only buyer responses, and MO usage references.
- `/production/orders/{id}`: sample selection, current eligibility, and the last release evidence.
- [Permission Map](./PERMISSION_MAP.md#iteration-28-product-development-endpoints) records all new endpoint permissions; no new role or permission code was invented.

Migration: `apps/api/database/migrations/2026_09_07_000045_complete_product_development.php`. Additions are nullable for historical records, except values required by the new write services. No historical approval, unit, file metadata, or MO sample linkage is guessed or backfilled.

## Verification and deferred work

| Activity | Status |
|---|---|
| Source/readback review and diff whitespace review | Performed; not runtime validation |
| Dependency resolution and Composer lock update | Performed with `--no-install --no-scripts --no-audit` |
| Migration up/down or representative-data upgrade | **NOT RUN — user request** |
| PHP lint/Pint, Pest, TypeScript, Next build | **NOT RUN — user request** |
| Browser/API/Playwright, storage integration, concurrency | **NOT RUN — user request** |
| GitHub Actions for this commit | Requested to skip with `[skip ci]`; workflow configuration unchanged |
| Production/UAT/security/accounting approval | **NOT GRANTED** |

Deferred validation must cover tenant/permission boundaries, stale version conflicts, sample/response/upload replay, legacy duplicate versions, file allowlist/private downloads and failed-write cleanup, sample selection and stale/rejected approval at release, snapshot/reservation rollback, unrelease/reselection, and concurrent revision/approval/release. Existing API regression fixtures must be reconciled with the new release prerequisite before interpreting test results.

**Production decision remains NO-GO.** Iteration 27's historical 28 targeted passes / 301 full-suite passes / 42 baseline failures do not verify this branch. Test results for Iteration 28 are deliberately unknown.