# FAMS Version 0.958

Removed Assistance Type from user-facing table/list columns while retaining the database field for future reference.

- `assistance_type` remains in the database and all record/import/revision workflows.
- Assistance Type remains available in record entry/edit forms and ticket workflows.
- Assistance Type remains searchable/importable for future use.
- Removed Assistance Type from the SWAD FAMS masterlist columns.
- Removed Assistance Type from dynamic masterlist/search-result rows.
- Removed Assistance Type from import-checker display columns.
- Removed Assistance Type from the printable monthly report table.
- Existing database column names and stored data were not renamed or removed.
