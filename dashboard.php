<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

include 'db.php';

$user_id = $_SESSION['user_id'];
// --- CHANGED: We now get the role NAME from the session, not the ID ---
$user_role = $_SESSION['role_name']; 
$params = [];
$leagues = [];
$query = ''; // Initialize the query variable to prevent errors

// --- CHANGED: The logic now checks for the role NAME ('Admin') ---
if ($user_role === 'Admin') {
    // --- User is an ADMIN ---
    $query = "SELECT * FROM league ORDER BY id ASC";

} else { // Covers any other role, like 'League Manager' or 'League Staff'
    // --- User is LEAGUE STAFF or another non-admin role ---
    $query = "SELECT l.*, GROUP_CONCAT(lma.assignment_id) AS assignments
              FROM league l
              JOIN league_manager_assignment lma ON l.id = lma.league_id
              WHERE lma.user_id = :user_id
              GROUP BY l.id 
              ORDER BY l.id ASC";
    $params['user_id'] = $user_id;
}

// This section will now work because $query is always set
if (!empty($query)) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
            /* Search icon */
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
<?php include 'includes/header.php'; // Corrected path if it's not in an includes folder ?>

<div class="dashboard-container">
    <h1 style="margin-bottom: 20px">Leagues</h1>

    <div class="search-container">
        <input type="text" id="leagueSearchInput" placeholder="Search leagues...">
    </div>
    
    <!-- --- CHANGED: Check against the role name variable --- -->
    <?php if ($user_role === 'Admin'): ?>
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
                                // --- CHANGED: Check against the role name variable ---
                                $hasManagerPermission = ($user_role === 'Admin') || (isset($league['assignments']) && in_array('1', explode(',', $league['assignments'])));
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
                                <!-- --- CHANGED: Check against the role name variable --- -->
                                <?php if ($user_role !== 'Admin'): ?>
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
// Your JavaScript for live search remains unchanged as it is correct.
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('leagueSearchInput');
    const tableBody = document.getElementById('leaguesTableBody');
    const allRows = tableBody.querySelectorAll('tr:not(#no-results-row)');
    const noResultsRow = document.getElementById('no-results-row');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();
        let visibleRows = 0;

        allRows.forEach(row => {
            const leagueName = row.cells[0].textContent.toLowerCase();
            
            if (leagueName.includes(searchTerm)) {
                row.style.display = '';
                visibleRows++;
            } else {
                row.style.display = 'none';
            }
        });

        noResultsRow.style.display = (visibleRows === 0) ? '' : 'none';
    });
});
</script>
</body>
</html>