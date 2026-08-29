<?php
// Build: 2026-08-28-B
// 2026-08-28 (Steve): laying groundwork for the eventual registration-
// workflow redesign - added a RegID column to registrations.csv. This
// table used to discard the file's own header row and substitute a
// hardcoded 9-column <th> list, then dump each row's cells in raw file
// order - the exact positional-read defect Finding 1 (2026-08-19 code
// review) already found and fixed in merchandise.csv/ourmerch.php. With
// RegID now added as column 1, that hardcoded list would have put every
// value one column off starting today. Fixed the same way ourmerch.php
// was: both the header cells and each row's cells now come from the
// file's own header, whatever columns it actually has and in whatever
// order - no assumed column count, no assumed order.
//
// 2026-08-28 (Steve, #2): formatting cleanup to mirror ourmerch.php -
// this page and that one are meant to feel like one admin tool, not two
// differently-styled ones:
//   1. Dropped the bottom Clear/Log Out/View Merch Requests block.
//      ourmerch.php carries none of these either (its own logout route
//      still works via admin_guard.php's merch_admin_login_gate() POST
//      handler, same as this page's did - there's just no button for it
//      in either page's markup); Clear All Registrations is still
//      reachable at clear.php directly if it's ever needed again, this
//      just stops it sitting in a button under every visit here.
//   2. Widened the page frame the same way ourmerch.php did (Finding-
//      driven "content-wrapper maxes out at 1000px" fix) - this table
//      can run as wide as RegID/Name/Address/Phone/Email/Event/
//      Duration/Notes/Date/IP all at once, and a fixed 1000px box just
//      made it scroll inside itself instead of using the screen.
//   3. Froze the header row (position: sticky) inside its own scrolling
//      pane, same as ourmerch.php's table - with RegID now added, this
//      list has grown past a hundred rows; scrolling through 113 of
//      them without the column names in view meant losing track of
//      which cell was which partway down.
require __DIR__ . '/admin_guard.php'; // must come before anything else that might start a session

// Simple password gate (same login as ourmerch.php - one admin, two
// lists). Shared implementation in admin_guard.php as of 2026-08-20
// (Finding 11, 2026-08-19 code review) - was previously duplicated
// byte-for-byte here and in ourmerch.php (including a drifted <title>
// that this also fixes - "Retreat" vs "Retreats").
merch_admin_login_gate('Admin Login – Southern Fireflies Retreats', 'ourguests.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Retreat Registrants</title>
  <link rel="icon" href="images/favicon.png" type="image/png" />
  <link rel="stylesheet" href="styles/layout.css" />
  <style>
    /* Same override as ourmerch.php, and for the same reason: this
       admin page doesn't share the public site's nav/branding, so
       widening it here doesn't touch any other page. Without it, the
       table scrolls inside layout.css's fixed 1000px .content-wrapper
       (meant for readable blog-style pages, not a wide data table)
       regardless of zoom level. */
    .content-wrapper {
      max-width: 98vw;
    }
    /* Table lives in its own scrollable pane with a real, intentional
       height + overflow on both axes - same as ourmerch.php's
       .merch-table-pane - so the sticky header below sticks relative to
       THIS pane instead of relying on page scroll. */
    .guests-table-pane {
      max-height: 75vh;
      overflow: auto;
      background: #fff;
      border-radius: 8px;
    }
    table th {
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 2;
      box-shadow: 0 1px 0 #ddd;
    }
  </style>
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container">
      <h2>Retreat Registrants</h2>

      <?php
      $csvFile = 'registrations.csv';
      $rows = [];
      $header = [];
      if (file_exists($csvFile) && filesize($csvFile) > 0) {
          if (($handle = fopen($csvFile, 'r')) !== false) {
              while (($row = fgetcsv($handle)) !== false) {
                  $rows[] = $row;
              }
              fclose($handle);
          }
          if (!empty($rows)) {
              $header = array_shift($rows);
              // Same Excel-BOM defense as merchandise.csv's readers.
              if (isset($header[0])) {
                  $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
              }
          }
      }

      if (!empty($rows)) {
          echo '<div class="guests-table-pane"><table style="width:100%; border-collapse: collapse;">';
          echo '<tr>';
          foreach ($header as $colName) {
              echo '<th>' . htmlspecialchars($colName) . '</th>';
          }
          echo '</tr>';
          foreach ($rows as $data) {
              echo '<tr>';
              foreach ($data as $cell) {
                  echo '<td style="padding:6px; border-bottom:1px solid #eee;">' . htmlspecialchars($cell) . '</td>';
              }
              echo '</tr>';
          }
          echo '</table></div>';
      } else {
          echo '<p style="text-align:center;">No registrations yet.</p>';
      }
      ?>
    </div>
  </div>
</body>
</html>
