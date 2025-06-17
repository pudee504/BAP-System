<?php
require 'db.php';
session_start();

$league_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$league_id) {
    die("Invalid league ID.");
}

// Fetch current league data
$stmt = $pdo->prepare("SELECT * FROM league WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    die("League not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $league_name = trim($_POST['league_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = trim($_POST['status'] ?? '');

    if ($league_name && $location && $status) {
        $update = $pdo->prepare("UPDATE league SET league_name = ?, location = ?, status = ? WHERE id = ?");
        $update->execute([$league_name, $location, $status, $league_id]);

        header("Location: dashboard.php");
        exit;
    } else {
        $error = "All fields are required.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit League</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
<div class="login-container">
    <h1>Edit League</h1>

    <?php if (!empty($error)): ?>
        <p style="color:red"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>League Name:</label><br>
        <input type="text" name="league_name" value="<?= htmlspecialchars($league['league_name']) ?>" required><br>

        <label>Location:</label><br>
        <input type="text" name="location" value="<?= htmlspecialchars($league['location']) ?>" required><br>

        <label>Status:</label><br>
        <select name="status" required>
            <option value="Upcoming" <?= $league['status'] === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
            <option value="Ongoing" <?= $league['status'] === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
            <option value="Completed" <?= $league['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
        </select><br><br>

        <button type="submit">Update League</button>
    </form>
</div>
</body>
</html>
