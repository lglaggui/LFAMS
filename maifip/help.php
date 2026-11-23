<?php
require_once __DIR__.'/config.php'; require_login();
$pageTitle='Help & Support'; $user=current_user(); require __DIR__.'/partials/header.php';
?>
<div class="help-toolbar" role="search" aria-label="Search Help & Support">
  <div class="help-search-wrap">
    <span class="help-search-icon" aria-hidden="true">⌕</span>
    <input id="helpFaqSearch" type="search" placeholder="Search FAQs, tips, records, funds, Chat, reports…" autocomplete="off" aria-label="Search Help & Support">
  </div>
  <span class="help-topic-count" id="helpTopicCount">Help topics</span><span class="help-toolbar-note" id="helpSearchStatus">Showing all Help & Support topics</span>
</div>
<div class="help-support-note"><span class="help-support-note-icon" aria-hidden="true">✓</span><div><strong>Start here when something looks unfamiliar.</strong><span>Search a topic below, open the matching question, and follow the steps before trying the action again. Navigation now uses a lightweight loading placeholder so moving between pages should feel like one continuous application instead of a full browser refresh.</span></div></div>
<div class="help-facility-note">
  <span class="help-note-icon" aria-hidden="true">i</span>
  <div><strong>Facility data is independent.</strong><span>FAMS records, funds, disbursements, monthly reports, and dashboard values belong to the currently selected medical facility. CIMC and CVMC are separate facilities and do not share financial or record data with SIMC, CDH, or each other. Chat remains cross-facility so users can communicate with users in other offices.</span></div>
</div>
<div class="help-sections">
<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">ACCOUNT & ACCESS</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3"/><path d="M5 20v-1a7 7 0 0 1 14 0v1"/></g></svg>Login, Email & User Accounts</h3></div></div>
<div class="help-faq-grid">
<details open><summary>How do I log in?</summary><p>You can sign in using your <strong>Username</strong>, registered <strong>Email Address</strong>, or <strong>Full Name</strong>, together with your password. Full Name is a convenience login method; if several users share the same name, use Username or Email instead. Your account must be active and approved.</p></details>
<details><summary>Can I add or change my email address?</summary><p>Yes. From Accounts Management → My Account, a user who has no email can request one to be added, and a user with an existing email can request an update. The new email does not take effect until a Site Admin approves the request. A pending request should not be submitted repeatedly.</p></details>
<details><summary>What happens when my email request is approved or rejected?</summary><p>The Site Admin reviews the requested address. If approved, the account email is updated and you receive an in-system notification. If rejected, your existing email remains unchanged. Email addresses must be unique among user accounts.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">FAMS RECORDS</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="16" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></g></svg>Records, Status & Tickets</h3></div></div>
<div class="help-faq-grid">
<details><summary>How do I add a new FAMS record?</summary><p>Open FAMS → <strong>Add Financial Assistance Record</strong>. Complete the required patient, address, guardian, contact, bill, and guarantee details. Province, Municipality/City, and Barangay selections follow the local PSGC hierarchy.</p></details>
<details><summary>How do I search records?</summary><p>Use the FAMS search field; the list updates as you type. Use the available filters when you need to narrow results by status or other record information. For a spreadsheet-style workspace, open <strong>Display Sheet</strong>.</p></details>
<details><summary>What do WAITING, RELEASED, and CANCELLED mean?</summary><p><strong>WAITING</strong> indicates that the assistance record is still being processed. <strong>RELEASED</strong> indicates that assistance has been released. <strong>CANCELLED</strong> indicates that the assistance entry was cancelled. Editing permissions depend on the user's role and the record status.</p></details>
<details><summary>When should I use Submit Ticket?</summary><p>Social Workers and permitted LPGMA users can submit a ticket when a finalized RELEASED or CANCELLED record needs a correction or status/detail update. WAITING records may be directly editable when the user's role permits it. Ticket review and resolution are handled through the ticket-management workflow by authorized users.</p></details>
<details><summary>Why is my finalized record locked?</summary><p>RELEASED and CANCELLED records have controlled editing to preserve the integrity of finalized assistance information. Use Submit Ticket when your role requires a reviewed correction.</p></details>
<details><summary>Will my text be saved in uppercase?</summary><p>Yes. Official text entered into supported FAMS forms and records is normalized to <strong>UPPERCASE</strong> before it is saved. This keeps names, addresses, remarks, and other official record text consistent. Passwords, email addresses, usernames, technical IDs, and system values are not converted.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">FAMS WORKSPACE</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="2"/><circle cx="15" cy="12" r="2"/></g></svg>Masterlist, Toolbar & Facility Selector</h3></div></div>
<div class="help-faq-grid">
<details><summary>How do I change the active facility?</summary><p>For LPGMA users, use the <strong>Facility</strong> card in the sidebar. Click or tap the facility card, choose the medical facility from the selector, and allow the workspace to reload. The selected facility controls the facility-scoped FAMS data shown in the workspace.</p></details>
<details><summary>How can I tell that the Facility card is selectable?</summary><p>The Facility card is interactive and uses the selected facility's color as a visual cue. Hover, focus, or click the card to open the facility selector. Medical-facility users remain locked to their assigned facility.</p></details>
<details><summary>What is the difference between the Masterlist controls?</summary><p>The first toolbar line contains the main data actions: <strong>Import / Update Sheet</strong>, <strong>Display Whole Sheet</strong>, and <strong>Add Financial Assistance Record</strong>. The second line contains the masterlist search controls: search text, status filter, Search, and Reset.</p></details>
<details><summary>What does Display Whole Sheet do?</summary><p>It opens the spreadsheet-style FAMS masterlist workspace for reviewing and editing permitted sheet values. The sheet identifies the active facility with its facility logo, acronym, and full facility name.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">REPORTING & FUNDS</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M8 14h3M8 17h7"/></g></svg>Monthly Reports, Excel & Balances</h3></div></div>
<div class="help-faq-grid">
<details><summary>How do I prepare a monthly disbursement report?</summary><p>Use <strong>Display Current Month Report</strong> for the current reporting month. For another period, select the required year and month under Reporting Period and use <strong>Display Selected Period Report</strong>. Review editable report fields before printing or exporting.</p></details>
<details><summary>Can I edit the monthly report before printing?</summary><p>Yes. The editable monthly sheet is a reporting workspace. You can review and edit permitted report values, headings, remarks, balances, and signatures before printing/exporting. Use the editable preview when you want to inspect the final layout first.</p></details>
<details><summary>Can I export the monthly report to Excel?</summary><p>Yes. Use the <strong>Export to Excel</strong> control in the Monthly Disbursement Editable Sheet. The export is intended for the edited reporting sheet and is separate from the main FAMS master records.</p></details>
<details><summary>How is the monthly balance carried forward?</summary><p>The next month's Beginning Balance is based on the previous month's Ending/Remaining Balance. Negative balances are valid and are carried forward as-is; they are not automatically forced to zero.</p></details>
<details><summary>Do editable report-sheet changes automatically change the FAMS master records?</summary><p>No. The monthly editable report is a reporting workspace. Changes to that sheet do not automatically rewrite the underlying assistance records unless a feature explicitly saves a value to the reporting settings or database.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">IMPORT & DATA SAFETY</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M4 20h16"/></g></svg>Import, Backup & Data Integrity</h3></div></div>
<div class="help-faq-grid">
<details><summary>How do I import an updated sheet safely?</summary><p>Open Import / Update Sheet, upload the source file, and review the checker results before proceeding. Review numbering, duplicate controls, patient/details checks, and other warnings. A database backup is created before a merge. Do not continue when the checker reports a blocking problem.</p></details>
<details><summary>What should I do before importing a large update?</summary><p>Keep a copy of the source spreadsheet, verify that the columns and record content are correct, review the import checker, and confirm that the automatic database backup completed before applying the merge.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">PROFILE, CHAT & NOTIFICATIONS</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.7 8.7 0 0 1-4.2-1.1L4 19l1.2-3.1A7.2 7.2 0 0 1 4.5 12 7.5 7.5 0 0 1 12 4.5a7.5 7.5 0 0 1 8 7z"/><path d="M8.5 12h.01M12 12h.01M15.5 12h.01"/></g></svg>Communication & Personalization</h3></div></div>
<div class="help-faq-grid">
<details><summary>How do I change my avatar?</summary><p>Go to Accounts Management → My Account. You can choose a new photo, or use <strong>Reposition / Crop Current Photo</strong> to adjust your existing photo. If you are still using the default avatar, the same crop control can reposition or resize the default silhouette before saving it as your profile photo.</p></details>
<details><summary>Why does Chat sometimes look like the whole page is refreshing?</summary><p>Chat navigation uses the same application shell as FAMS. The page content is loaded in the background and replaced without rebuilding the entire shell. A small loading placeholder appears while the new content is being prepared. If a network or server error prevents partial loading, the system safely falls back to a normal page navigation.</p></details>
<details><summary>How do I return to the default avatar?</summary><p>Use <strong>Revert to Default Photo</strong> in My Account. Your custom photo is removed and the appropriate default avatar is restored.</p></details>
<details><summary>How do I use Chat?</summary><p>Open Chat, choose a person or an available office/group conversation, type your message, and press Send. Your own messages can be edited or deleted according to the available controls. Keep messages specific by including the Control No., page, or action when reporting a system issue.</p></details>
<details><summary>What should I do if the notification button does not open?</summary><p>Click the bell once and wait for the notification panel to load. If it remains unavailable, refresh the page once and confirm that your session is still active. Avoid repeatedly clicking while a notification request is loading. If the problem continues, report the page, time, and visible error to the Site Administrator through Chat.</p></details>
<details><summary>Why do buttons and controls look different when I hover or focus them?</summary><p>FAMS uses consistent hover, active, focus, and disabled states so interactive controls are easier to recognize. A visible focus ring is also provided for keyboard navigation. Facility colors are used mainly as accents rather than large background fills.</p></details>
<details><summary>Why does the header profile expand slowly?</summary><p>The header profile uses a deliberate smooth transition so the office logo and profile details appear without an abrupt layout jump. The compact state hides the office logo; the expanded state reveals it.</p></details>
<details><summary>Why does the sidebar keep its scroll position?</summary><p>FAMS remembers the sidebar navigation scroll position as a percentage. When you move between sidebar pages or reload the application, the navigation attempts to restore the same relative position.</p></details>
<details><summary>What do Chat notification badges mean?</summary><p>The Chat badge represents unread incoming chat messages/conversations. Open the relevant conversation to review the messages. The general notification panel separately handles system notifications such as ticket, registration, and approval work.</p></details>
<details><summary>What is a good format for reporting an issue through Chat?</summary><p>Use a short, structured message: <strong>Page → Record/Control No. → What you were doing → What happened → Error message</strong>. If possible, include the approximate time and avoid sending passwords or other sensitive credentials.</p></details>
<details><summary>What do the notification badges show?</summary><p>Notification badges highlight unread notifications or outstanding work. Some work badges remain visible while an item is still pending even if an individual notification has already been read. Open the linked page and resolve the underlying item.</p></details>
<details><summary>Can I remove notifications?</summary><p>The notification panel supports removing individual notifications and removing all notifications. Removing a notification does not necessarily resolve the underlying pending work; check the linked page when the notification concerns a ticket, registration, email approval, or other action.</p></details>
<details><summary>What should I do if the notification bell does not open?</summary><p>Click the bell once and wait for the notification panel to load. If it still does not respond, refresh the page once and try again. If the problem persists, report the page, action, and any visible error to the Site Administrator through Chat.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">DASHBOARD, ADDRESS & OFFLINE</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01M12 13v3M12 11h.01"/></g></svg>System Tools & Troubleshooting</h3></div></div>
<div class="help-faq-grid">
<details><summary>Who can customize the dashboard?</summary><p>Site administrators can use the dashboard layout controls. Save the layout after changing a division's position or width. The shared layout is stored for later visits.</p></details>
<details><summary>Why should I avoid moving dashboard sections while testing data?</summary><p>The dashboard layout controls change presentation only. When checking financial totals, first confirm the reporting period and underlying records, then adjust layout after the information is verified.</p></details>
<details><summary>How do address dropdowns work?</summary><p>Province → Municipality/City → Barangay selections use the FAMS local PSGC directory. Normal record entry does not need to call an external address service for every selection. A Site Admin can update the configured address directory when an official update is needed.</p></details>
<details><summary>Can I continue editing during an internet outage?</summary><p>Controlled offline record-detail editing is supported for the first phase of offline operation. Eligible Create/Edit Record changes can be queued on the device and synchronized when connectivity returns. The system checks record versions before applying offline edits. Status changes, tickets, imports, account administration, and fund administration remain server-authoritative.</p></details>
<details><summary>What should I do if something looks wrong?</summary><p>Refresh the page once. If the problem remains, record the page name, Control No. or user involved, action performed, and exact error message. Take a screenshot when useful and contact the Site Administrator through Chat. Do not repeatedly submit the same action if you are unsure whether it already succeeded.</p></details>
<details><summary>What should I never send through Chat?</summary><p>Do not send passwords, password-reset codes, database credentials, or other authentication secrets through Chat. Share only the minimum record information needed to identify the issue.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">FACILITIES & WORKSPACE</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6"/></g></svg>Facility Selection & Data Scope</h3></div></div>
<div class="help-faq-grid">
<details><summary>What does the selected facility control?</summary><p>The selected medical facility is the active FAMS workspace. Dashboard values, assistance records, fund breakdowns, fund downloads, disbursement summaries, monthly reports, and related exports are scoped to that facility.</p></details>
<details><summary>Do CIMC and CVMC share records or funds?</summary><p>No. CIMC and CVMC are independent facilities. Each has its own records, fund ledger, disbursements, monthly reporting data, dashboard values, and facility branding. Data entered for one facility should not appear when another facility is selected.</p></details>
<details><summary>Can medical-facility users switch to another facility?</summary><p>No. A Social Worker account remains assigned to its own facility. LPGMA users can select the medical-facility workspace available to them.</p></details>
<details><summary>Can users from different facilities chat with each other?</summary><p>Yes. Chat is intentionally cross-facility. A user may communicate with another active user from another office even though financial and FAMS record data remains facility-specific.</p></details>
<details><summary>Why is a new facility showing zero values?</summary><p>A newly activated facility starts with an empty data scope. Zero or empty values are expected until records, fund downloads, or reporting data are actually entered for that facility.</p></details>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">AUDIT & SECURITY</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v5l3 2"/><path d="M20 12a8 8 0 1 1-2.3-5.7M20 5v5h-5"/></g></svg>Action Logs & Activity Tracking</h3></div></div>
<div class="help-faq-grid">
<details><summary>What are Action Logs?</summary><p>Action Logs provide an audit trail of important system activity such as record changes, account actions, imports, exports, ticket actions, and other logged operations. They are available to authorized Admin and Manager users.</p></details>
<details><summary>Can Action Logs show which computer performed an action?</summary><p>The log can record the <strong>client IP address</strong> and the browser/device information available to the server. A Windows PC name is not normally exposed to a website by the browser, so a true computer name cannot be guaranteed. If the network provides reverse DNS, an optional host name may also be recorded when available.</p></details>
<details><summary>Why is the IP address useful?</summary><p>The client IP helps identify the network address from which an action reached the server. This is useful when reviewing unusual activity, troubleshooting shared-office connections, or correlating an action with a known workstation or network.</p></details>
<details><summary>What should I include when reporting a problem?</summary><p>Include the page, Control No. or account involved, the action you performed, the result/error message, and approximately when it happened. Authorized staff can then compare the report with the Action Logs and connection details.</p></details>
</div></section>

<section class="help-section help-quick-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">QUICK START</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/><path d="M20 12v7H4V5h10"/></g></svg>Daily FAMS Checklist</h3></div></div>
<div class="help-checklist-grid"><div class="help-quick-tips"><strong>Before processing records</strong><ul><li>Confirm you are signed into the correct account and office.</li><li>Search for an existing record before creating a new one.</li><li>Confirm the Control No., patient name, address, guardian, and contact details.</li><li>Check bill and guarantee amounts carefully before saving.</li></ul></div><div class="help-quick-tips"><strong>Before finalizing assistance</strong><ul><li>Confirm status and release date are consistent.</li><li>Review remarks and required supporting details.</li><li>Use Submit Ticket for controlled corrections when direct editing is not permitted.</li></ul></div><div class="help-quick-tips"><strong>Before preparing a report</strong><ul><li>Confirm the reporting year and month.</li><li>Check beginning balance against the previous period's ending balance.</li><li>Review clients, amounts, remarks, and signatures before print/export.</li></ul></div></div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">PRACTICAL TIPS</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></g></svg>Common Checks Before You Submit</h3></div></div>
<div class="help-tip-grid">
<div class="help-tip-card"><strong>Creating a record</strong><p>Confirm the selected facility first, then verify the patient details, amounts, and generated Control No. before saving.</p></div>
<div class="help-tip-card"><strong>Checking funds</strong><p>If a facility has no fund entries of its own, its fund summary should remain zero. Do not use another facility's balance as a reference for the current workspace.</p></div>
<div class="help-tip-card"><strong>Monthly reports</strong><p>Confirm facility, reporting year, and month before displaying or exporting a sheet. If the sheet looks unexpected, refresh once and verify the selected facility.</p></div>
</div></section>

<section class="help-section panel">
<div class="panel-heading compact"><div><span class="section-kicker">SITE ADMIN</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.7 8.7 0 0 1-4.2-1.1L4 19l1.2-3.1A7.2 7.2 0 0 1 4.5 12 7.5 7.5 0 0 1 12 4.5a7.5 7.5 0 0 1 8 7z"/><path d="M8.5 12h.01M12 12h.01M15.5 12h.01"/></g></svg>Chat with the Site Administrator</h3></div></div>
<div class="help-chat-card"><div class="help-chat-icon">💬</div><div><strong>Need help or need to report an issue?</strong><p>Use FAMS Chat to communicate directly with the Site Administrator. Help &amp; Support does not use a separate support-ticket form.</p><a class="primary-btn" href="chat.php">Open Chat</a></div></div>
<div class="help-quick-tips"><strong>Recommended issue format</strong><ul><li><strong>Page:</strong> where the problem occurred</li><li><strong>Record:</strong> Control No. or account/user, when applicable</li><li><strong>Action:</strong> what you clicked or submitted</li><li><strong>Result:</strong> what happened and the exact error message</li></ul></div></section>

<section class="help-section help-contact-section panel" aria-labelledby="lpgma-contact-title">
<div class="panel-heading compact"><div><span class="section-kicker">LPGMA ISABELA</span><h3 id="lpgma-contact-title">Contact Information</h3></div></div>
<div class="help-contact-grid">
<a class="help-contact-card" href="mailto:isabelalpgma@gmail.com">
<span class="help-contact-icon" aria-hidden="true">✉</span>
<span class="help-contact-copy"><small>Email Address</small><strong>isabelalpgma@gmail.com</strong><span>For LPGMA Isabela office inquiries and concerns.</span></span>
</a>
<a class="help-contact-card" href="tel:09650327116">
<span class="help-contact-icon" aria-hidden="true">☎</span>
<span class="help-contact-copy"><small>Contact Number</small><strong>0965 032 7116</strong><span>For LPGMA Isabela office inquiries and assistance.</span></span>
</a>
<a class="help-contact-card" href="mailto:lpgmahrep20th@gmail.com">
<span class="help-contact-icon" aria-hidden="true">✉</span>
<span class="help-contact-copy"><small>LPGMA House of Representatives Email</small><strong>lpgmahrep20th@gmail.com</strong><span>For LPGMA House of Representatives inquiries and correspondence.</span></span>
</a>
</div>
</section>

<?php if(($user['role'] ?? '') === 'admin'): ?>
<section class="help-section help-address-section panel"><div class="panel-heading compact"><div><span class="section-kicker">ADDRESS DIRECTORY</span><h3><svg class="help-heading-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.3 7-12a7 7 0 1 0-14 0c0 5.7 7 12 7 12z"/><circle cx="12" cy="9" r="2"/></g></svg>PSGC Local Data</h3></div></div><p class="help-muted">The address selector uses the local Province → Municipality/City → Barangay directory. Region I (Ilocos Region), Region II (Cagayan Valley), and CAR are supported. Internet is needed only when an administrator explicitly updates the local directory.</p><div class="help-quick-tips"><strong>Update guidance</strong><ul><li>Update the local address directory when an official PSGC update is needed, rather than during ordinary record entry.</li><li>Normal record entry and editing continue to use the local copy.</li><li>If an online update fails, the existing local directory is preserved.</li></ul></div><button type="button" class="secondary-btn" id="refreshPsgcBtn" data-csrf="<?=e(csrf_token())?>">Update Address Database</button><div id="refreshPsgcResult" class="help-muted" style="margin-top:8px"></div></section>
<?php endif; ?>
</div>
<script src="../core/help-support.js?v=20261026-v0093" defer></script>

<script>
(async function(){
 const b=document.getElementById('refreshPsgcBtn'),o=document.getElementById('refreshPsgcResult'); if(!b||!o)return;
 try{const r=await fetch('address_api.php?action=status',{credentials:'same-origin',cache:'no-store'});const d=await r.json();if(d.last_refresh){o.textContent='Last update: '+d.last_refresh+' • Next suggested update: '+(d.next_refresh_due||'—');}else{o.textContent='No online PSGC refresh has been recorded yet. The bundled local directory remains available.';}}catch(e){o.textContent='Unable to read the last refresh status. Existing local address data remains available.';}
 b.addEventListener('click',async()=>{if(!confirm('Update the local address database from the online PSGC source now?'))return;b.disabled=true;o.textContent='Updating Region I, Region II and CAR address data…';try{const r=await fetch('address_api.php?action=refresh',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':b.dataset.csrf||''}});const d=await r.json();o.textContent=(d.message||'Update complete.')+' Reload the page after a successful update.';}catch(e){o.textContent='Update failed. Existing local address data was preserved.';}finally{b.disabled=false;}});
})();
</script>
<?php require __DIR__.'/partials/footer.php'; ?>
