<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $league_name = isset($_POST['league_name']) ? trim($_POST['league_name']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    if ($league_name && $start_date && $end_date) {
        if (strtotime($end_date) < strtotime($start_date)) {
            $error = "End date cannot be earlier than start date.";
        } else {
            // Check for duplicates
            $checkSql = "SELECT COUNT(*) FROM league WHERE league_name = :league_name AND location = :location";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([
                ':league_name' => $league_name,
                ':location' => $location
            ]);
            $existingCount = $checkStmt->fetchColumn();

            if ($existingCount > 0) {
                $error = "A league with this name and location already exists.";
            } else {
                // Determine league status
                $today = date('Y-m-d');
                $status = ($today < $start_date) ? 'Upcoming' : 'Active';

                // Insert the league
                $stmt = $pdo->prepare("INSERT INTO league (league_name, location, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$league_name, $location, $start_date, $end_date, $status]);

                $league_id = $pdo->lastInsertId();

                header("Location: league_details.php?id=" . $league_id);
                exit;
            }
        }
    } else {
        $error = "League name, start date, and end date are required.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  
  <meta charset="UTF-8">
  <title>Create League</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <div class="login-container">
    <h1>Create a New League</h1>

    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form action="create_league.php" method="POST">
      <div class="form-group">
        <label for="league_name">League Name</label>
        <input type="text" name="league_name" required required value="<?= htmlspecialchars($league_name ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="location">Location</label>
        <input type="text" name="location" required value="<?= htmlspecialchars($location?? '') ?>">
      </div>

      <div class="date-range">
        <div class="form-group half">
          <label for="start_date">Start Date</label>
          <input type="date" name="start_date" required required value="<?= htmlspecialchars($start_date ?? '') ?>">
        </div>
        <div class="form-group half">
          <label for="end_date">End Date</label>
          <input type="date" name="end_date" required value="<?= htmlspecialchars($end_date ?? '') ?>">
        </div>
      </div>

      <button type="submit" class="login-button">Create League</button>
    </form>
  </div>
</body>
</html>