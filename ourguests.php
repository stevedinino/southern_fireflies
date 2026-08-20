<?php
// Build: 2026-08-20-A
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
</head>
<body>
  <div class="content-wrapper">
    <div class="page-container">
      <h2>Retreat Registrants</h2>

      <?php
      $csvFile = 'registrations.csv';
      $rows = [];
      if (file_exists($csvFile) && filesize($csvFile) > 0) {
          if (($handle = fopen($csvFile, 'r')) !== false) {
              while (($row = fgetcsv($handle)) !== false) {
                  $rows[] = $row;
              }
              fclose($handle);
          }
          if (!empty($rows)) {
              array_shift($rows); // drop the header row - it's already shown below
          }
      }

      if (!empty($rows)) {
          echo '<div style="overflow-x:auto;"><table style="width:100%; border-collapse: collapse;">';
          echo '<tr><th>Name</th><th>Address</th><th>Phone</th><th>Email</th><th>Event</th><th>Length of Stay</th><th>Notes</th><th>Timestamp</th><th>IP</th></tr>';
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

      <div class="button-container" style="text-align:center; margin-top:20px;">
        <form method="POST" action="clear.php"
              onsubmit="return document.getElementById('confirmText').value === 'CLEAR';">
          <p>Type <strong>CLEAR</strong> in the box below to confirm:</p>
          <input type="text" id="confirmText" placeholder="Type CLEAR to confirm" />
          <br />
          <button type="submit" class="btn">Clear All Registrations</button>
        </form>

        <p style="margin-top:20px;"><a href="ourmerch.php" style="color: var(--accent);">View Merch Requests &rarr;</a></p>

        <form method="POST" action="ourguests.php" style="margin-top:16px;">
          <input type="hidden" name="logout" value="1" />
          <button type="submit" class="btn" style="background:#888;">Log Out</button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
