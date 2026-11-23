# LPGMA FAMS 0.951 — Role & Access Test Report

## Source under test
- Version: 0.951
- Roles: Admin / Site Admin, Manager, Staff (encoder), Social Worker
- Facilities: LPGMA, SIMC, CDH, CIMC, CVMC

## Automated checks
- 45 PHP files linted: PASS (0 syntax errors)
- assets/app.js `node --check`: PASS
- assets/app.css parsed with tinycss2: PASS (0 parser errors)
- JavaScript marker inside CSS (`document.addEventListener`): 0
- Version/cache: 0.951 / `fams-v0.951-static`

## Authorization checks
- Site Admin-only email approval/rejection: PASS
- Record deletion: Admin-only endpoint: PASS
- Facility management: Admin-only endpoint: PASS
- Fund settings: Admin/Manager entry point with Manager POST denial: PASS
- Monthly report settings: Admin/Staff only: PASS
- Sheet import: Admin/Staff only: PASS
- Offline conflict resolution: Admin/Staff/Social Worker only: PASS
- Ticket review Manager POST denial: PASS
- Chat clear-all Admin-only: PASS

## Manager refinements
- Ticket list provides a View Ticket action for Managers.
- Ticket review is read-only for Managers; no mutation controls are rendered.
- Fund entry popup is read-only for Managers; blank add-editor popup redirects back to Fund Management.
- FAMS editable sheet remains read-only for Managers.
- Monthly sheet editor remains read-only for Managers.
- Account navigation for non-admins is labeled My Account.

## Chat clear/read-state test logic
When Admin clears a conversation, the pre-clear highest message ID is written as the affected users' read cursor before deletion. This preserves the expected behavior that a message sent after the clear creates a new unread badge/notification.

## Facility-scope static audit
Critical record/fund/report/ticket/import/offline paths were inspected for active-facility scoping. All checked paths contain facility scope markers and/or use the active facility context.

## Manual acceptance tests still required
The following require a live database/browser session and cannot be proven by static analysis alone:
1. Login separately as each role.
2. Repeat FAMS, fund, ticket, chat, account, report and direct-URL tests.
3. Repeat facility isolation tests for SIMC, CDH, CIMC and CVMC Social Workers.
4. Test Manager read-only behavior by opening edit URLs directly and attempting POST requests.
5. Test Admin clear-chat followed by a new message from another user.
6. Verify notification bell and chat badges update without visual blinking.
7. Verify mobile/tablet responsive behavior.
