FAMS Region II v0.0183 — Deployment Notes

Upload the contents of the htdocs/ directory to the web server document root, preserving core/, maifip/, and aics/. Configure the shared database credentials only in core/database.php.

Database SQL exports and historical upgrade scripts are no longer stored inside htdocs. They are supplied separately in FAMS-Database-Backups-Consolidated-v0.0183.zip. The archive contains the baseline database export and historical migrations with instructions. The baseline export is for a NEW EMPTY database only; never import it over a live database.

Before deployment, back up application files and the live database. Verify both AICS and MAIFIP login, role restrictions, records, tickets, fund summaries, chat, notifications, reporting, and file uploads after deployment.

This release includes route-aware loading for shared JavaScript, lossless PNG optimization where pixel identity was verified, and separation of SQL backup material from the web root. PHP/JS/CSS static validation does not replace authenticated end-to-end testing on the target PHP/MySQL host.
