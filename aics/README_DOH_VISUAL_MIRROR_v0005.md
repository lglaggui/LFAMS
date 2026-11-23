# SWAD FAMS — DOH/MAIFIP Visual Mirror v0.005

This revision aligns the SWAD FAMS application shell visually with the DOH/MAIFIP FAMS interface while retaining SWAD-specific workflows and data.

## Visual changes
- DOH-style LPGMA sidebar branding and Financial Assistance / Monitoring System grouping.
- DOH-style DSWD SWAD Region II facility card.
- Matching navigation sections and hierarchy.
- Help & Support retained as the final support navigation item.
- Action Logs and Facility Management positioned in the management/support hierarchy.
- DOH-style top bar, profile treatment, notifications, chat shortcut, and fund summary.
- Matching card, border, spacing, typography, navy/blue/green visual language.
- Responsive mobile sidebar behavior retained.

## Functional safety checks
- All PHP files pass `php -l`.
- Referenced local PHP/CSS/JS/image assets in the updated SWAD header were checked for existence.
- Existing SWAD dashboard, records, fund management, accounts, offices, tickets, chat, help, logs, and switching routes were not removed.
