-- FAMS v0.0214: append-only, facility-scoped MAIFIP fund adjustments.
-- Review/backup the database before applying. This migration does not modify
-- monthly_report_settings; historical manual values are retained untouched.
CREATE TABLE IF NOT EXISTS maifip_fund_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    facility VARCHAR(32) NOT NULL,
    effective_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maifip_fund_adjustments_facility_date (facility, effective_date),
    KEY idx_maifip_fund_adjustments_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
