<?php
// dashboard.php — Displays leagues accessible to the logged-in user

session_start();

// --- Redirect to login if not authenticated ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

include 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_name']; 
$params = [];
$leagues = [];
$query = '';

// --- Query leagues based on role ---
if ($user_role === 'Admin') {
    // Admins see all leagues
    $query = "SELECT * FROM league ORDER BY id ASC";
} else {
    // Managers see only assigned leagues
    $query = "SELECT l.*, GROUP_CONCAT(lma.assignment_id) AS assignments
              FROM league l
              JOIN league_manager_assignment lma ON l.id = lma.league_id
              WHERE lma.user_id = :user_id
              GROUP BY l.id 
              ORDER BY l.id ASC";
    $params['user_id'] = $user_id;
}

// --- Execute query ---
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
    <title>Dashboard - BAP Federation Makilala Chapter</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="dashboard-container">

    <?php
    // --- Display Session Messages ---
    if (isset($_SESSION['error'])) {
        echo '<div class="form-error" style="margin-bottom: 1.5rem;">' . htmlspecialchars($_SESSION['error']) . '</div>';
        unset($_SESSION['error']); // Clear the message so it only shows once
    }
    if (isset($_SESSION['success'])) {
        echo '<div class="success-message" style="margin-bottom: 1.5rem;">' . htmlspecialchars($_SESSION['success']) . '</div>';
        unset($_SESSION['success']); // Clear the message
    }
    ?>
    
    <div class="dashboard-header">
        <h1>Leagues</h1>
        <!-- League search bar -->
        <div class="search-container">
            <input type="text" id="leagueSearchInput" placeholder="Search leagues by name...">
        </div>
    </div>
    
    <!-- Admin-only: create new league -->
    <?php if ($user_role === 'Admin'): ?>
        <div style="margin-bottom: 1.5rem;">
            <a href="create_league.php" class="btn btn-primary create-league-button">+ Create New League</a>
        </div>
    <?php endif; ?>
    
    <!-- Leagues table -->
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
                            <td class="actions">
                                <?php
                                // --- Check user permission for this league ---
                                $hasManagerPermission = ($user_role === 'Admin') || 
                                    (isset($league['assignments']) && in_array('1', explode(',', $league['assignments'])));
                                ?>

                                <?php if ($hasManagerPermission): ?>
                                    <a href="edit_league.php?id=<?= $league['id'] ?>">Edit</a>
                                    <a href="delete_league.php?id=<?= $league['id'] ?>" class="action-delete" onclick="return confirm('Are you sure you want to delete this league?');">Delete</a>
                                <?php else: ?>
                                    <a href="league_details.php?id=<?= $league['id'] ?>">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Hidden row for no search results -->
                    <tr id="no-results-row" style="display: none;">
                        <td colspan="4">No leagues match your search.</td>
                    </tr>
                <?php else: ?>
                    <!-- Message when user has no leagues -->
                    <tr class="no-results">
                        <td colspan="4">
                            <div class="no-results-message">
                                <?php if ($user_role !== 'Admin'): ?>
                                    You have not been assigned to any leagues yet.
                                <?php else: ?>
                                    No leagues have been created yet.
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- --- Client-side search filter --- -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('leagueSearchInput');
    const tableBody = document.getElementById('leaguesTableBody');
    const allRows = Array.from(tableBody.querySelectorAll('tr')).filter(row => row.id !== 'no-results-row' && !row.classList.contains('no-results'));
    const noResultsRow = document.getElementById('no-results-row');
    const originalNoResults = document.querySelector('.no-results');

    // Filter table rows by league name
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

        if (originalNoResults) originalNoResults.style.display = 'none';
        if (noResultsRow && allRows.length > 0) {
           noResultsRow.style.display = (visibleRows === 0) ? '' : 'none';
        }
        if (searchTerm === '' && originalNoResults) {
            originalNoResults.style.display = '';
        }
    });
});
</script>
</body>
</html>
