-- FAMS v0.0215 — 2026 opening-balance classification
-- REVIEW BEFORE RUNNING. Back up the database first. This script is NOT run
-- automatically by the application and must be applied manually.
--
-- Policy:
--   * The 2026 ledger has one opening balance effective 2026-01-01.
--   * A row is treated as a 2025 carryover only when fund_source explicitly
--     identifies "2025 REMAINING BALANCE".
--   * All other rows labelled BEGINNING BALANCE from 2026-01-01 onward are
--     ordinary fund receipts and are reclassified as FUND DOWNLOAD #<id>.
--   * If a fund/facility has no identified 2025 carryover, insert a zero anchor.
--   * Older pre-2026 historical opening rows are retained for historical
--     reference; the 2026 ledger calculation does not roll them forward.
--
-- Run the preflight SELECTs first. If more than one 2025 carryover is listed
-- for a workspace/facility, resolve the duplicate manually before continuing.

-- PRE-FLIGHT: AICS records that will be reclassified.
SELECT id, batch_no, downloaded_amount, date_downloaded, fund_source
FROM aics_fund_downloads
WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'
  AND date_downloaded >= '2026-01-01'
  AND UPPER(TRIM(COALESCE(fund_source,''))) NOT LIKE '%2025%REMAINING BALANCE%'
ORDER BY date_downloaded, id;

-- PRE-FLIGHT: MAIFIP records that will be reclassified.
SELECT id, facility, batch_no, downloaded_amount, date_downloaded, fund_source
FROM fund_downloads
WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'
  AND date_downloaded >= '2026-01-01'
  AND UPPER(TRIM(COALESCE(fund_source,''))) NOT LIKE '%2025%REMAINING BALANCE%'
ORDER BY facility, date_downloaded, id;

-- PRE-FLIGHT: More than one explicitly identified 2025 carryover per ledger?
SELECT 'AICS' AS workspace, COUNT(*) AS carryover_count
FROM aics_fund_downloads
WHERE UPPER(TRIM(COALESCE(fund_source,''))) LIKE '%2025%REMAINING BALANCE%'
UNION ALL
SELECT CONCAT('MAIFIP: ', facility) AS workspace, COUNT(*) AS carryover_count
FROM fund_downloads
WHERE UPPER(TRIM(COALESCE(fund_source,''))) LIKE '%2025%REMAINING BALANCE%'
GROUP BY facility;

START TRANSACTION;

-- Normalize explicitly identified 2025 carryovers to the 2026 opening date.
UPDATE aics_fund_downloads
SET date_downloaded='2026-01-01', batch_no='BEGINNING BALANCE'
WHERE UPPER(TRIM(COALESCE(fund_source,''))) LIKE '%2025%REMAINING BALANCE%'
  AND date_downloaded <= '2025-12-31';

UPDATE fund_downloads
SET date_downloaded='2026-01-01', batch_no='BEGINNING BALANCE'
WHERE UPPER(TRIM(COALESCE(fund_source,''))) LIKE '%2025%REMAINING BALANCE%'
  AND date_downloaded <= '2025-12-31';

-- Reclassify all other 2026+ BEGINNING BALANCE rows as ordinary additions.
-- The row ID is retained in the label so each converted row remains distinct.
UPDATE aics_fund_downloads
SET batch_no=CONCAT('FUND DOWNLOAD #', id)
WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'
  AND date_downloaded >= '2026-01-01'
  AND UPPER(TRIM(COALESCE(fund_source,''))) NOT LIKE '%2025%REMAINING BALANCE%';

UPDATE fund_downloads
SET batch_no=CONCAT('FUND DOWNLOAD #', id)
WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'
  AND date_downloaded >= '2026-01-01'
  AND UPPER(TRIM(COALESCE(fund_source,''))) NOT LIKE '%2025%REMAINING BALANCE%';

-- Add an explicit zero opening balance where no 2025 carryover was identified.
-- created_by is omitted so the table's default/null behavior is respected.
INSERT INTO aics_fund_downloads (batch_no, downloaded_amount, date_downloaded, fund_source)
SELECT 'BEGINNING BALANCE', 0.00, '2026-01-01', '2025 REMAINING BALANCE - NO CARRYOVER; ZERO OPENING BALANCE'
WHERE NOT EXISTS (
    SELECT 1 FROM aics_fund_downloads
    WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'
      AND date_downloaded='2026-01-01'
      AND UPPER(TRIM(COALESCE(fund_source,''))) LIKE '%2025%REMAINING BALANCE%'
);

-- MAIFIP: include facilities appearing in either fund downloads or assistance
-- records. A zero anchor is inserted only when that facility has no carryover.
INSERT INTO fund_downloads (facility, batch_no, downloaded_amount, date_downloaded, fund_source)
SELECT facilities.facility, 'BEGINNING BALANCE', 0.00, '2026-01-01',
       '2025 REMAINING BALANCE - NO CARRYOVER; ZERO OPENING BALANCE'
FROM (
    SELECT DISTINCT TRIM(facility) AS facility FROM fund_downloads WHERE TRIM(COALESCE(facility,''))<>''
    UNION
    SELECT DISTINCT TRIM(facility) AS facility FROM assistance_records WHERE TRIM(COALESCE(facility,''))<>''
) AS facilities
WHERE NOT EXISTS (
    SELECT 1 FROM fund_downloads existing
    WHERE UPPER(TRIM(existing.facility))=UPPER(TRIM(facilities.facility))
      AND UPPER(TRIM(COALESCE(existing.batch_no,'')))='BEGINNING BALANCE'
      AND existing.date_downloaded='2026-01-01'
      AND UPPER(TRIM(COALESCE(existing.fund_source,''))) LIKE '%2025%REMAINING BALANCE%'
);

COMMIT;

-- POST-FLIGHT: must return exactly one January 1, 2026 opening row per ledger.
SELECT COUNT(*) AS aics_opening_rows
FROM aics_fund_downloads
WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'
  AND date_downloaded='2026-01-01';

SELECT facility, COUNT(*) AS opening_rows
FROM fund_downloads
WHERE UPPER(TRIM(COALESCE(batch_no,'')))='BEGINNING BALANCE'
  AND date_downloaded='2026-01-01'
GROUP BY facility
ORDER BY facility;
