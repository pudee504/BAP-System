<?php
require 'db.php';
session_start();


$category_id = (int) ($_POST['category_id'] ?? 0);

if (!$category_id) {
    die("Missing category ID.");
}

$stmt = $pdo->prepare("UPDATE category_format SET is_locked = TRUE WHERE category_id = ?");
$stmt->execute([$category_id]);

header("Location: category_details.php?category_id=" . $category_id);
exit;
