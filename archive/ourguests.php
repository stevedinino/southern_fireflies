<?php
session_start();
require __DIR__ . '/config.php';

// Log out
if (isset($_POST['logout'])) {
    unset($_SESSION['sff_admin_ok']);
    header('Location: ourguests.php');
    exit;
}

// Simple password gate. Change ADMIN_PASSWORD in config.php before uploading.
$loginError = '';
if (!isset($_SESSION['sff_admin_ok'])) {
    if (isset($_POST['admin_password'])) {
        if (hash_equals(ADMIN_PASSWORD, $_POST['admin_password'])) {
            $_SESSION['sff_admin_ok'] = true;
        } else {
            $loginError = 'Incorrect password.';
        }
    }
}

if (!isset($_SESSION['sff_admin_ok'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" />
      <title>Admin Login – Southern Fireflies Retreat</title>
      <link rel="stylesheet" href="styles/layout.css" />
    </head>
    <body>
      <div class="content-wrapper">
        <div class="form-container">
          <h2>Admin Login</h2>
          <?php if ($loginError !== ''): ?>
            <p style="color:#b00020; text-align:center;"><?= htmlspecialchars($loginError) ?></p>
          <?php endif; ?>
          <form method="POST">
            <input type="password" name="admin_password" placeholder="Password" required />
            <button type="submit" class="btn full-width">Log In</button>
          </form>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}
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
