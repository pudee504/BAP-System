<?php
// It's crucial to start the session to know who is performing the action.
session_start();
require 'db.php';
require_once 'logger.php'; // Include our logging function

// --- This block only runs when the form is submitted ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $league_id = (int) $_POST['league_id'];
    $category_name = trim($_POST['category_name']);
    $format_id = (int) $_POST['game_format'];

    if ($format_id === 3) {
        $num_teams = (int) ($_POST['num_teams_input'] ?? 0);
    } else {
        $num_teams = (int) ($_POST['num_teams_dropdown'] ?? 0);
    }

    // --- General Input Validation ---
    if (!$league_id || !$category_name || !$format_id || !$num_teams) {
        $log_details = "Attempted to add category with incomplete data for league ID: {$league_id}.";
        log_action('ADD_CATEGORY', 'FAILURE', $log_details);
        die("Invalid input. Please go back and fill in all required fields.");
    }
    
    // --- MODIFIED: Server-side check for Elimination Formats ---
    if (($format_id === 1 || $format_id === 2) && $num_teams < 4) {
        $format_name = ($format_id === 1) ? 'Single Elimination' : 'Double Elimination';
        $error = "{$format_name} format requires a minimum of 4 teams.";
        log_action('ADD_CATEGORY', 'FAILURE', "Validation failed for '{$category_name}'. Reason: {$error}");
        die("Invalid input: {$error}");
    }

    // --- Round Robin Specific Validation ---
    if ($format_id === 3) {
        $num_groups = (int) ($_POST['num_groups'] ?? 0);
        $advance_per_group = (int) ($_POST['advance_per_group'] ?? 0);
        $error = ''; // Variable to hold our error message

        if ($num_teams > 64) {
            $error = "The maximum number of teams for a Round Robin format is 64. You entered {$num_teams}.";
        }
        elseif ($num_groups <= 0 || $advance_per_group <= 0) {
            $error = 'Number of groups and advancing teams must be filled in.';
        } 
        elseif ($num_groups > $num_teams) {
            $error = "Cannot have more groups ({$num_groups}) than teams ({$num_teams}).";
        } 
        else {
            $min_teams_per_group = floor($num_teams / $num_groups);

            if ($min_teams_per_group < 2) {
                $error = "Each group must have at least 2 teams. Your setup results in some groups having only {$min_teams_per_group} team(s).";
            } 
            elseif ($advance_per_group > $min_teams_per_group) {
                $error = "Cannot advance {$advance_per_group} teams when the smallest group only has {$min_teams_per_group} teams.";
            }
            elseif (($num_groups * $advance_per_group) >= $num_teams) {
                $total_advancing = $num_groups * $advance_per_group;
                $error = "Invalid setup: The total number of advancing teams ({$total_advancing}) must be less than the total number of teams ({$num_teams}).";
            }
        }

        if ($error) {
            $log_details = "Round Robin validation failed for category '{$category_name}'. Reason: {$error}";
            log_action('ADD_CATEGORY', 'FAILURE', $log_details);
            die("Invalid Round Robin setup: {$error} Please go back and correct the values.");
        }
    }


    // --- Database Operations with a Safety Transaction ---
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO category (league_id, category_name) VALUES (?, ?)");
        $stmt->execute([$league_id, $category_name]);
        $category_id = $pdo->lastInsertId();
        
        if ($format_id === 3) {
            $stmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams, num_groups, advance_per_group) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$category_id, $format_id, $num_teams, $num_groups, $advance_per_group]);
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

    } catch (PDOException $e) {
        $pdo->rollBack();
        $log_details = "Database error while creating category '{$category_name}'. Error: " . $e->getMessage();
        log_action('ADD_CATEGORY', 'FAILURE', $log_details);
        die("A database error occurred. Could not create the category. Please try again.");
    }

    header("Location: league_details.php?id=" . $league_id);
    exit;
}


// --- This block runs when the page is first loaded ---
if (!isset($_GET['league_id']) || !filter_var($_GET['league_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]])) {
    die("Invalid or missing league ID in URL. Example: add_category.php?league_id=1");
}
$league_id = (int) $_GET['league_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Category</title>
  <link rel="stylesheet" href="style.css">

</head>
<body class="login-page">
  <div class="login-container">
    <h1>Add Category</h1>
    <form action="add_category.php" method="POST">
      <label for="categoryName">Category Name</label>
      <input type="text" name="category_name" id="categoryName" required>

      <label for="gameFormat">Game Format</label>
      <select name="game_format" id="gameFormat" required onchange="toggleTeamsInput(this.value)">
        <option value="">-- Select Format --</option>
        <?php
        // Fetch formats from database
        $stmt = $pdo->query("SELECT id, format_name FROM format");
        while ($format = $stmt->fetch()) {
            echo '<option value="' . $format['id'] . '">' . htmlspecialchars($format['format_name']) . '</option>';
        }
        ?>
      </select>

      <div id="dropdownTeams" style="display:none;">
        <label for="numTeamsSelect">Number of Teams</label>
        <select name="num_teams_dropdown" id="numTeamsSelect">
          <option value="">-- Number of Teams --</option>
          <option value="4">4</option>
          <option value="8">8</option>
          <option value="16">16</option>
          <option value="32">32</option>
        </select>
      </div>

      <div id="customTeams" style="display:none;">
        <label for="numTeamsInput">Number of Teams</label>
        <input type="number" name="num_teams_input" id="numTeamsInput" min="2">
      </div>

      <div id="roundRobinOptions" style="display: none;">
        <label for="numGroups">Number of Groups</label>
        <input type="number" name="num_groups" id="numGroups" min="1" max="8">

        <label for="advancePerGroup">Teams Advancing per Group</label>
        <select name="advance_per_group" id="advancePerGroup">
          <option value="">-- Select Teams --</option>
          <option value="1">1</option>
          <option value="2">2</option>
        </select>
      </div>

      <input type="hidden" name="league_id" value="<?= $league_id ?>">
      <button type="submit" class="login-button">Add Category</button>
    </form>
  </div>

  <script>
    function toggleTeamsInput(format) {
      const dropdown = document.getElementById('dropdownTeams');
      const custom = document.getElementById('customTeams');
      const roundOptions = document.getElementById('roundRobinOptions');
      const dropdownSelect = document.getElementById('numTeamsSelect');
      const optionForTwoTeams = dropdownSelect.querySelector('option[value="2"]');

      // --- MODIFIED SCRIPT ---
      if (format === '1' || format === '2') { // Single or Double Elimination
        dropdown.style.display = 'block';
        custom.style.display = 'none';
        roundOptions.style.display = 'none';

        // Hide the "2 teams" option for these formats
        optionForTwoTeams.style.display = 'none';
        
        // If "2" was selected, reset the dropdown to prevent submitting a hidden value
        if (dropdownSelect.value === '2') {
          dropdownSelect.value = '';
        }
      } else if (format === '3') { // Round Robin
        dropdown.style.display = 'none';
        custom.style.display = 'block';
        roundOptions.style.display = 'block';
      } else { // No format selected
        dropdown.style.display = 'none';
        custom.style.display = 'none';
        roundOptions.style.display = 'none';
      }
    }
  </script>

</body>
</html>