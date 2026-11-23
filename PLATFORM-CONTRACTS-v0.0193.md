# FAMS Platform Contracts — v0.0193

## Platform boundary
FAMS is one authenticated platform with separate AICS and MAIFIP workspace adapters. Shared services must not erase differences in record schema, beneficiary/patient identity, office/facility assignment, or workflow policy.

## Shared runtime
`core/shared_runtime.php` owns reusable primitives for transactions/savepoints, payload fingerprints, idempotency claims, HTTP method/request parsing, JSON responses, CSRF tokens, and common input utilities.

## Workspace data boundaries
- AICS repository: `FamsAicsRecordRepository` targets `aics_assistance_records`; office-scoped methods require a record ID and office ID. District-wide read/update methods must only be called after the AICS policy layer authorizes that scope.
- MAIFIP repository: `FamsMaifipRecordRepository` targets `assistance_records`; methods require an exact facility key and compare the expected record version.
- Both adapters use field allowlists and compare-and-swap updates that increment `record_version`.
- Do not add generic identifier-only update/delete methods. Add explicit scope parameters and version conditions.

## Idempotency contract
A submission is identified by user, operation scope, and a random request key. The ledger stores a SHA-256 fingerprint of canonicalized request data. CSRF and idempotency tokens are excluded from the fingerprint; business fields and ordered list data are retained.

- Same user + same scope + same key + same payload: retry; do not repeat the business operation.
- Same user + same scope + same key + different payload: reject with `FamsIdempotencyConflict`.
- The idempotency claim and business writes must commit or roll back together.
- The `payload_hash` compatibility column is prepared before the business transaction because MySQL DDL can implicitly commit.
- Legacy claims without a fingerprint are not silently treated as matching a new payload; the user must reload/resubmit with a fresh key.

## HTTP contract primitives
`fams_shared_request_method()`, `fams_shared_require_method()`, `fams_shared_request_data()`, `fams_shared_json_success()`, and `fams_shared_json_error()` are available for endpoint-by-endpoint migration. Existing redirects and workspace-specific response behavior remain intact until an endpoint is deliberately migrated.

## Endpoint registry
`maintenance/endpoint-registry.json` inventories 80 entry points. `methods_to_verify` are candidate methods, not confirmed support. The manifest is not runtime authorization middleware. Do not use it as proof of authorization until every endpoint has exact capability, method, CSRF, ownership, and authenticated HTTP tests.

## Migration rules
- Preserve current production data and historical identifiers.
- No destructive or data-rewriting migration without backup, staging rehearsal, and rollback instructions.
- Never merge AICS and MAIFIP business tables solely for code symmetry.
- Keep audit, notifications, status history, archive, and idempotency writes in the same transaction as the primary operation.
