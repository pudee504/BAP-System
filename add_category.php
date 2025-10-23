<?php
session_start();
require 'db.php';
require_once 'logger.php'; 

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

// --- This block runs when the page is first loaded ---
if (!isset($_GET['league_id']) || !filter_var($_GET['league_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]])) {
    // Log this attempt as it could be a sign of tampering or a broken link
    log_action('ACCESS_DENIED', 'FAILURE', 'Attempted to access add_category.php with an invalid or missing league ID.');
    die("Invalid or missing league ID.");
}
$league_id = (int) $_GET['league_id'];

// Fetch the league name to display in the title
$leagueStmt = $pdo->prepare("SELECT league_name FROM league WHERE id = ?");
$leagueStmt->execute([$league_id]);
$league_name = $leagueStmt->fetchColumn();
if (!$league_name) {
    die("League not found.");
}

// Initialize variables
$error = '';

// --- This block only runs when the form is submitted ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_league_id = (int) $_POST['league_id'];
    $category_name = trim($_POST['category_name']);
    $format_id = (int) $_POST['game_format'];
    $num_teams = (int) ($_POST['num_teams'] ?? 0);

    // --- General Input Validation ---
    if ($posted_league_id !== $league_id || !$category_name || !$format_id || !$num_teams) {
        $error = "Please fill in all required fields.";
        $log_details = "Attempted to add category with incomplete data for league ID: {$league_id}.";
        log_action('ADD_CATEGORY', 'FAILURE', $log_details);
    } else {
        // --- Server-side validation for team count ranges ---
        if ($format_id === 1 || $format_id === 2) { // Single or Double Elimination
            if ($num_teams < 2 || $num_teams > 32) {
                $format_name = ($format_id === 1) ? 'Single Elimination' : 'Double Elimination';
                $error = "{$format_name} format requires between 2 and 32 teams. You entered {$num_teams}.";
            }
        }
        // --- Round Robin Specific Validation ---
        elseif ($format_id === 3) {
            $num_groups = (int) ($_POST['num_groups'] ?? 0);
            $advance_per_group = (int) ($_POST['advance_per_group'] ?? 0);

            if ($num_teams > 64) {
                $error = "The maximum number of teams for a Round Robin format is 64. You entered {$num_teams}.";
            } elseif ($num_groups <= 0 || $advance_per_group <= 0) {
                $error = 'Number of groups and advancing teams must be filled in.';
            } elseif ($num_groups > $num_teams) {
                $error = "Cannot have more groups ({$num_groups}) than teams ({$num_teams}).";
            } else {
                $min_teams_per_group = floor($num_teams / $num_groups);
                if ($min_teams_per_group < 2) {
                    $error = "Each group must have at least 2 teams. Your setup results in some groups having only {$min_teams_per_group} team(s).";
                } elseif ($advance_per_group > $min_teams_per_group) {
                    $error = "Cannot advance {$advance_per_group} teams when the smallest group only has {$min_teams_per_group} teams.";
                } elseif (($num_groups * $advance_per_group) >= $num_teams) {
                    $total_advancing = $num_groups * $advance_per_group;
                    $error = "Invalid setup: The total number of advancing teams ({$total_advancing}) must be less than the total number of teams ({$num_teams}).";
                }
            }
        }
    }
    

    // --- Centralized Error Handling & Database Operations ---
    if ($error) {
        $log_details = "Validation failed for category '{$category_name}'. Reason: {$error}";
        log_action('ADD_CATEGORY', 'FAILURE', $log_details);
        // The script will continue and display the error message in the form
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO category (league_id, category_name) VALUES (?, ?)");
            $stmt->execute([$league_id, $category_name]);
            $category_id = $pdo->lastInsertId();
            
            if ($format_id === 3) {
                $stmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams, num_groups, advance_per_group) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$category_id, $format_id, $num_teams, $num_groups, $advance_per_group]);
                
                $clusterInsertStmt = $pdo->prepare("INSERT INTO cluster (category_id, cluster_name) VALUES (?, ?)");
                for ($i = 1; $i <= $num_groups; $i++) {
                    $clusterInsertStmt->execute([$category_id, "Group " . $i]);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams) VALUES (?, ?, ?)");
                $stmt->execute([$category_id, $format_id, $num_teams]);
            }

            $formatStmt = $pdo->prepare("SELECT format_name FROM format WHERE id = ?");
            $formatStmt->execute([$format_id]);
            $format_name = $formatStmt->fetchColumn();
            $log_details = "Created category '{$category_name}' (ID: {$category_id}). Format: {$format_name}, Teams: {$num_teams}.";
            if ($format_id === 3) {
                $log_details .= " Groups: {$num_groups}, Advance Per Group: {$advance_per_group}.";
            }
            
            $pdo->commit();
            log_action('ADD_CATEGORY', 'SUCCESS', $log_details);

            header("Location: league_details.php?id=" . $league_id);
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "A database error occurred. Could not create the category.";
            $log_details = "Database error while creating category '{$category_name}'. Error: " . $e->getMessage();
            log_action('ADD_CATEGORY', 'FAILURE', $log_details);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Category to <?= htmlspecialchars($league_name) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="form-container">
    <h1>Add Category</h1>
    
    <?php if (!empty($error)): ?>
        <div class="form-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form action="add_category.php?league_id=<?= $league_id ?>" method="POST">
        <div class="form-group">
            <label for="categoryName">Category Name</label>
            <input type="text" name="category_name" id="categoryName" required>
        </div>

        <div class="form-group">
            <label for="gameFormat">Game Format</label>
            <select name="game_format" id="gameFormat" required onchange="toggleFormatOptions(this.value)">
                <option value="">-- Select Format --</option>
                <?php
                $stmt = $pdo->query("SELECT id, format_name FROM format");
                while ($format = $stmt->fetch()) {
                    echo '<option value="' . $format['id'] . '">' . htmlspecialchars($format['format_name']) . '</option>';
                }
                ?>
            </select>
        </div>

        <div id="numTeamsContainer" class="form-group" style="display:none;">
            <label for="numTeamsInput">Number of Teams</label>
            <input type="number" name="num_teams" id="numTeamsInput">
        </div>

        <div id="roundRobinOptions" style="display: none;">
            <div class="form-group">
                <label for="numGroups">Number of Groups</label>
                <input type="number" name="num_groups" id="numGroups" min="1" max="16">
            </div>
            <div class="form-group">
                <label for="advancePerGroup">Teams Advancing per Group</label>
                <select name="advance_per_group" id="advancePerGroup">
                    <option value="">-- Select --</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </select>
            </div>
        </div>

        <input type="hidden" name="league_id" value="<?= $league_id ?>">
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Add Category</button>
        </div>
    </form>
</div>

<script>
    function toggleFormatOptions(format) {
        const numTeamsContainer = document.getElementById('numTeamsContainer');
        const numTeamsInput = document.getElementById('numTeamsInput');
        const roundRobinOptions = document.getElementById('roundRobinOptions');
        const numGroupsInput = document.getElementById('numGroups');
        const advancePerGroupSelect = document.getElementById('advancePerGroup');

        // Hide all optional sections by default and reset 'required' status
        numTeamsContainer.style.display = 'none';
        roundRobinOptions.style.display = 'none';
        numTeamsInput.required = false;
        numGroupsInput.required = false;
        advancePerGroupSelect.required = false;

        if (format === '1' || format === '2') { // Single or Double Elimination
            numTeamsContainer.style.display = 'block';
            numTeamsInput.min = '2';
            numTeamsInput.max = '32';
            numTeamsInput.placeholder = '2-32 Teams';
            numTeamsInput.required = true;
        } else if (format === '3') { // Round Robin
            numTeamsContainer.style.display = 'block';
            roundRobinOptions.style.display = 'block';
            numTeamsInput.min = '2';
            numTeamsInput.max = '64';
            numTeamsInput.placeholder = '2-64 Teams';
            numTeamsInput.required = true;
            numGroupsInput.required = true;
            advancePerGroupSelect.required = true;
        }
    }
</script>

</body>
</html>

