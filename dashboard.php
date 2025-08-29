<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

include 'db.php';

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$params = [];
$leagues = [];

if ($role_id == 1) {
    // --- User is an ADMIN ---
    // MODIFIED: Changed the sorting order to show oldest leagues first.
    $query = "SELECT * FROM league ORDER BY id ASC";

} elseif ($role_id == 2) {
    // --- User is LEAGUE STAFF ---
    $query = "SELECT l.*, GROUP_CONCAT(lma.assignment_id) AS assignments
              FROM league l
              JOIN league_manager_assignment lma ON l.id = lma.league_id
              WHERE lma.user_id = :user_id
              GROUP BY l.id 
              ORDER BY l.id ASC"; // MODIFIED: Changed the sorting order here as well.
    $params['user_id'] = $user_id;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - League Management</title>
    <link rel="stylesheet" href="style.css">
    
    <style>
        .search-container {
            position: relative;
            width: 100%;
            max-width: 800px;
            margin-bottom: 20px;
        }
        #leagueSearchInput {
            width: 100%;
            padding: 10px 15px 10px 40px; /* Left padding for icon */
            border: 1px solid #ccc;
            border-radius: 25px;
            font-size: 16px;
            box-sizing: border-box;
            transition: box-shadow 0.2s;
        }
        #leagueSearchInput:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.5);
        }
        .search-container::before {
            /* Search icon (you can use an SVG or font icon too) */
            content: '🔍';
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #888;
        }
    </style>
    </head>
<body>
<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    <h1 style="margin-bottom: 20px">Leagues</h1>

    <div class="search-container">
        <input type="text" id="leagueSearchInput" placeholder="Search leagues...">
    </div>
    <?php if ($role_id == 1): ?>
        <a href="create_league.php" class="create-league-button">+ Create League</a>
    <?php endif; ?>
    
    <div class="table-wrapper">
        <table class="leagues-table">
            <thead>
            <tr>
                <th>League Name</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody id="leaguesTableBody">
                <?php if (count($leagues) > 0): ?>
                    <?php foreach ($leagues as $league): ?>
                        <tr>
                            <td>
                                <a href="league_details.php?id=<?= $league['id'] ?>">
                                    <?= htmlspecialchars($league['league_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($league['location']) ?></td>
                            <td><?= htmlspecialchars($league['status']) ?></td>
                            <td>
                                <?php
                                $hasManagerPermission = ($role_id == 1) || (isset($league['assignments']) && in_array('1', explode(',', $league['assignments'])));
                                ?>

                                <?php if ($hasManagerPermission): ?>
                                    <a href="edit_league.php?id=<?= $league['id'] ?>">Edit</a> |
                                    <a href="delete_league.php?id=<?= $league['id'] ?>" onclick="return confirm('Are you sure you want to delete this league?');">Delete</a>
                                <?php else: ?>
                                    <a href="league_details.php?id=<?= $league['id'] ?>">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="no-results-row" style="display: none;">
                        <td colspan="4">No leagues match your search.</td>
                    </tr>
                <?php else: ?>
                    <tr class="no-results">
                        <td colspan="4">
                            <div class="no-results-message">
                                <?php if ($role_id == 2): ?>
                                    You have not been assigned to any leagues.
                                <?php else: ?>
                                    No leagues found.
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('leagueSearchInput');
    const tableBody = document.getElementById('leaguesTableBody');
    const allRows = tableBody.querySelectorAll('tr:not(#no-results-row)'); // Get all data rows
    const noResultsRow = document.getElementById('no-results-row');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();
        let visibleRows = 0;

        allRows.forEach(row => {
            // Get the text from the first cell (League Name)
            const leagueName = row.cells[0].textContent.toLowerCase();
            
            // If the league name includes the search term, show it. Otherwise, hide it.
            if (leagueName.includes(searchTerm)) {
                row.style.display = '';
                visibleRows++;
            } else {
                row.style.display = 'none';
            }
        });

        // Show or hide the "No results" message based on visibility
        if (visibleRows === 0) {
            noResultsRow.style.display = ''; // Use table-row if your CSS is specific
        } else {
            noResultsRow.style.display = 'none';
        }
    });
});
</script>
</body>
</html>