# SWAD FAMS Region II — v0.009

- Fixed `status_class()` null TypeError in `record_form.php` by making the helper null-safe and guarding missing status values.
- Added functional header notification and quick-chat popovers with correct stacking/z-index and outside-click behavior.
- Added a reliable close behavior for top popovers and synchronized aria-expanded states.
- Refined notification badge appearance.
- Changed monthly disbursement desktop layout to approximately 25% numerical summary / 75% line chart, with responsive fallbacks.
- Made the footer persistently visible at the bottom of the viewport like the sticky header, with responsive offsets and content bottom padding.
- Updated application version to 0.009.
