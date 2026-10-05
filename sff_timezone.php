<?php
// Build: 2026-10-04-A
// ============================================================
// Sets the site's time zone to Eastern, once, for every page that
// requires it (Steve, 2026-10-04).
//
// Why this exists: nothing on the site used to set a time zone, so every
// date() call (Timestamp on new orders and registrations, the Pymt Date /
// Invoice Date / Created / Fulfilled stamps, backup file names, etc.)
// used whatever the web host's PHP default was - which on Network
// Solutions runs several hours ahead of Eastern. Now that Pymt Date holds
// the real payment date and the Stock & Print Plan ranks by it, an order
// marked paid in the evening must get TODAY's date, not tomorrow's.
//
// Only affects dates written from now on. Nothing already in
// merchandise.csv or registrations.csv is touched.
//
// require_once this near the top of any entry point that stamps or
// compares a date. A DST-aware zone name is used on purpose, so there is
// nothing to change twice a year.
// ============================================================

date_default_timezone_set('America/New_York');
