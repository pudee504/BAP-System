<?php
require 'db.php';

$team_id = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT);
if (!$team_id) die("Invalid team ID");

$stmt = $pdo->prepare("SELECT * FROM team WHERE id = ?");
$stmt->execute([$team_id]);
$team = $stmt->fetch();

if (!$team) die("Team not found");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_name = trim($_POST['team_name']);
    if ($new_name) {
        $update = $pdo->prepare("UPDATE team SET team_name = ? WHERE id = ?");
        $update->execute([$new_name, $team_id]);
        header("Location: category_details.php?category_id=" . $team['category_id'] . "#teams");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head><title>Edit Team</title></head>
<body class="login-page">
    <div class="login-container">
<h1>Edit Team</h1>
<form method="POST">
    <label>Team Name</label>
    <input type="text" name="team_name" value="<?= htmlspecialchars($team['team_name']) ?>" required>
    <button type="submit">Update</button>
</form>
<p><a href="category_details.php?category_id=<?= $team['category_id'] ?>#teams">← Back to Category</a></p>
</div>
</body>
</html>
