<?php
require 'db.php';
session_start();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = (int) ($_POST['category_id'] ?? 0);

    if ($category_id) {
        $stmt = $pdo->prepare("UPDATE category_format SET is_locked = 0 WHERE category_id = ?");
        $stmt->execute([$category_id]);

        $_SESSION['message'] = "Seedings / Groupings have been unlocked.";
    }
}

header("Location: category_details.php?category_id=$category_id");
exit;
