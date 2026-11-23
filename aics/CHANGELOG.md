## v0.008 — Unified shell / compact footer / system switch

- Incremented application and directory release version to .0009.
- Removed the header Selection button from application pages.
- Added inline MAIFIP/AICS system switch beside the LPGMA Isabela label.
- AICS sidebar now follows the MAIFIP FAMS shell structure and visual hierarchy.
- Footer is compact and bottom-anchored.


## v0.012 — Dashboard, Header Fund Summary & Validity Refinement
- Refined the Dashboard Main Facility card so the DSWD/SWAD logo is strictly contained within its logo frame at desktop, tablet, and mobile sizes.
- Added a centered FAMS-style Header Fund Summary with Total Allotment, Disbursed Assistance, Remaining Fund, and Utilization Rate.
- Header fund figures use the same centralized Region II fund and released-assistance sources as the dashboard.
- Changed the New Assistance validity default from 30 days to 7 days, including server-side fallback and schema defaults for newly initialized databases.
- Preserved automatic cancellation of expired WAITING records and the locked-record / Submit Ticket behavior.
- Refined responsive header/facility spacing and overflow behavior.
- Version: 0.012.

## Version 0.011
- Added LPGMA Office Group Chat selector and dedicated office group.
- Added SWAD Region II central group and province group selectors for Isabela, Cagayan, Nueva Vizcaya, Quirino, and Batanes.
- Added Help & Support FAQs, tips, and official LPGMA headquarters contacts.
- Improved avatar cache busting so administrator default avatars update correctly in header/sidebar/profile.
- Improved communication and value legibility styles.

# CHANGELOG — SWAD FAMS

## 0.008
- Refined sidebar/navigation icons and interaction states.
- Added Help & Support to navigation.
- Refined header, notification badge, profile and facility-card styling.
- LPGMA sidebar branding centered again.
- Staff user type is restricted to LPGMA; Social Worker is restricted to SWAD subfacilities.
- Replaced legacy fund transaction Type with FAMS-style Fund Download ledger (Batch No., Downloaded Fund, Download Date, Fund Source).
- Fixed New Assistance INSERT placeholder mismatch.
- Replaced address dropdown implementation with the local FAMS-style PSGC Region II cache and cascade.
- Added bundled PSGC Region II 2026 address directory and local address API.
- Added an upgrade migration that preserves existing users and assistance records.

# SWAD FAMS Region II — Version 0.008

- Refined the global CSS for a modern, formal FAMS-inspired visual system.
- Added the user-provided DSWD logo as a local asset with white sticker/padding treatment.
- Added local default administrator and default-user avatars under `assets/avatars/`.
- Renamed Encoder to Staff and enforced server-side role/facility rules:
  - Administrator: LPGMA only
  - Manager: LPGMA only
  - Staff: valid LPGMA/SWAD facility assignment
  - Social Worker: SWAD subfacility only
- Added facility logo indicator in account creation/edit popup.
- Added avatar upload, crop, zoom and repositioning to account-management popup, including new accounts.
- Added strict password minimum and assignment validation.
- Added database-backed Province → Municipality/City → Barangay address cascade.
- Added Days of Validity and automatic cancellation/locking of expired WAITING records.
- Cancelled records show Submit Ticket instead of Edit.
- Refined Chat to an FAMS-style directory/conversation layout for LPGMA and SWAD users.
- Refined Fund Management with FAMS-style summary/ledger and popup add/edit controls.
- Refined Ticket Management popup interactions, including record-linked tickets.
- Added Help & Support page and repaired its navigation target.
- Reworked dashboard monthly disbursement into a numerical table plus line trend chart.
- Added responsive refinements for narrow phone screens.
- Added session cookie hardening and maintained CSRF protection.
- Historical records are normalized to SWAD subfacility assignments by Region II province.

## v0.0085
- Expanded Help & Support with searchable AICS/SWAD workflow guidance.
- Adopted the shared reporting-period AJAX handler and corrected dashboard fragment wrapper behavior.
- Standardized shared Help & Support contrast and controls.
