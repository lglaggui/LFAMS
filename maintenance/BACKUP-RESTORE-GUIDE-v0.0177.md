# FAMS Region II — Database Backup and Restore Guide

## Before every release

1. Put the application in a maintenance window or ask users to sign out.
2. Back up the complete `htdocs` folder.
3. Back up the `lfams_unified` database. Do not rely on the application ZIP as a database backup.
4. Store the backup outside the public web root and verify the file size/date.
5. Keep at least one previous known-good backup until the new release has been verified.

## XAMPP / phpMyAdmin backup

1. Open phpMyAdmin and select `lfams_unified`.
2. Choose **Export** → **Custom**.
3. Select all tables and include structure and data.
4. Use SQL format and enable `Add CREATE TABLE` / `IF NOT EXISTS` only if appropriate for your restore workflow. Prefer a complete SQL dump for disaster recovery.
5. Download the `.sql` file and keep it in a private backup location outside `htdocs`.
6. For large databases, use the XAMPP `mysqldump` command-line utility with `--single-transaction --routines --triggers --events --default-character-set=utf8mb4`.

## Restore procedure

1. Stop application access and take a copy of the current database before restoring over it.
2. Create/select the intended database and confirm the target name carefully.
3. Import the SQL backup using phpMyAdmin or the MySQL `mysql` command-line client.
4. Confirm that the expected tables and record counts exist before reopening the application.
5. Check both AICS and MAIFIP login, masterlists, tickets, chat, action logs, and reporting.
6. Never restore a production backup into a live database without a separate current backup and an approved maintenance window.

## Optional v0.0177 performance indexes

The file `UPGRADE-v0.0177-PERFORMANCE-INDEXES.sql` adds only missing composite indexes. It does not change records or table columns. Run it once after a database backup, using the `lfams_unified` database. The script uses `INFORMATION_SCHEMA` checks and skips an index if its table or index already exists. The database account needs permission to create indexes and stored procedures. If your host does not permit stored procedures, ask the database administrator to apply equivalent `ALTER TABLE ... ADD INDEX` statements after checking existing indexes.

## Security

- Do not put SQL backups in `htdocs`, `public_html`, or any publicly served directory.
- Do not email unencrypted beneficiary data backups.
- Restrict backup access to authorized administrators.
- Test restoration periodically; a backup that has never been restored is not a verified recovery plan.
