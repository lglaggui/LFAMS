# FAMS Version 0.957

SWAD FAMS terminology and masterlist refinement.

- Guardian is displayed as **Client**.
- Patient is displayed as **Beneficiary**.
- Client contact number is displayed directly beneath the client name in the masterlist.
- Added a dedicated `assistance_type` database column through a safe runtime migration.
- Assistance Type is editable in record entry and revision workflows.
- Existing historical assistance types are not guessed or backfilled from remarks; they remain blank/Not specified until intentionally populated.
- The FAMS masterlist now presents Submission, Client, Beneficiary, Address, Assistance Type, Amount, Office, Status, and Action.
- Existing database patient/guardian column names remain unchanged for compatibility.
