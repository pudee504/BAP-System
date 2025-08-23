<?php
// This file is included within 'category_tabs_standings.php' and uses variables defined there.

// 1. Calculate the bracket structure to determine byes and preliminary matches.
$num_teams = (int)$category['num_teams'];
// Find the next highest power of two (e.g., for 13 teams, this will be 16).
$next_power_of_two = 2 ** ceil(log($num_teams, 2));
// The number of byes is the difference.
$num_byes = $next_power_of_two - $num_teams;
// The number of teams that must play in the first round.
$num_prelim_teams = $num_teams - $num_byes;

// 2. Initialize bracket positions in the database if this is the first time loading.
if (empty($bracket_positions)) {
    $pdo->beginTransaction();
    try {
        // Ensure a clean slate by clearing any old positions for this category.
        $pdo->prepare("DELETE FROM bracket_positions WHERE category_id = ?")->execute([$category_id]);
        
        // Get all teams that need to be placed in the bracket.
        $unassigned_teams_stmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY team_name ASC");
        $unassigned_teams_stmt->execute([$category_id]);
        $unassigned_teams = $unassigned_teams_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Create a database entry for each slot in the bracket up to the next power of two.
        $insertPos = $pdo->prepare("INSERT INTO bracket_positions (category_id, position, team_id) VALUES (?, ?, ?)");
        for ($i = 1; $i <= $next_power_of_two; $i++) {
            // Assign a team to the slot if available, otherwise leave it null (empty).
            $team_to_assign = array_shift($unassigned_teams);
            $insertPos->execute([$category_id, $i, $team_to_assign]);
        }
        $pdo->commit();
        
        // Re-fetch the newly created bracket positions to display them.
        $posStmt = $pdo->prepare("
            SELECT bp.position, bp.team_id, t.team_name 
            FROM bracket_positions bp 
            LEFT JOIN team t ON bp.team_id = t.id 
            WHERE bp.category_id = ? 
            ORDER BY bp.position ASC
        ");
        $posStmt->execute([$category_id]);
        $bracket_positions = $posStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error initializing bracket positions: " . $e->getMessage());
    }
}

// 3. Render the interactive bracket preview.
?>
<link rel="stylesheet" href="includes/bracket_renderer.css">
<h3>Bracket Preview & Seeding</h3>
<p>Drag and drop teams to set their starting positions. Teams playing in the first round are paired together. The remaining teams have a <strong>(BYE)</strong> and advance automatically.</p>

<div class="bracket-preview-container">
    <ul id="bracket-sortable-list">
        <?php
        $position_counter = 0;
        foreach ($bracket_positions as $bp) {
            $position_counter++;
            $team_id = $bp['team_id'];
            $team_name = $bp['team_name'] ?? 'Empty Slot';
            
            // Determine if the team at this position is in a preliminary match or has a bye.
            $is_in_prelim_match = ($position_counter <= $num_prelim_teams);
            $has_bye = !$is_in_prelim_match && ($team_id !== null);
            
            // Assign a class for CSS to visually group pairs.
            $pair_class = 'pair-' . ceil($position_counter / 2);
            
            echo "<li class='team-draggable {$pair_class}' data-position='{$bp['position']}' data-team-id='{$team_id}'>";
            echo "<span class='drag-handle'>&#x2630;</span> "; // Drag handle icon
            echo htmlspecialchars($team_name);
            if ($has_bye) {
                echo " <strong class='bye-indicator'>(BYE)</strong>";
            }
            echo "</li>";
        }
        ?>
    </ul>
</div>

<?php if (!$bracketLocked): ?>
    <form action="lock_bracket.php" method="POST" onsubmit="return confirm('Are you sure you want to lock this bracket? This will finalize the seeding and cannot be easily undone.')" style="margin-top: 20px;">
        <input type="hidden" name="category_id" value="<?= $category_id ?>">
        <button type="submit">Lock Bracket & Proceed to Schedule</button>
    </form>
<?php else: ?>
    <p style="color:green; margin-top: 20px;"><strong>Bracket is locked. You can now generate the schedule in the 'Schedule' tab.</strong></p>
<?php endif; ?>

<script>
const sortableList = document.getElementById('bracket-sortable-list');
if (sortableList) {
    new Sortable(sortableList, {
        animation: 150, // Animation speed
        handle: '.drag-handle', // Specify the drag handle element
        disabled: <?= $bracketLocked ? 'true' : 'false' ?>, // Disable sorting if bracket is locked
        onEnd: function (evt) {
            // This function is called when a drag-and-drop operation ends.
            const items = evt.to.children;
            const newOrder = Array.from(items).map((item, index) => {
                return {
                    position: index + 1, // The new visual position (1-based index)
                    team_id: item.getAttribute('data-team-id')
                };
            });

            // Send the new order to the server via an AJAX request.
            fetch('update_bracket_positions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    category_id: <?= $category_id ?>,
                    order: newOrder
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Reload the page to reflect the new order and re-calculate byes.
                    location.reload(); 
                } else {
                    alert('Error updating positions: ' + data.message);
                }
            })
            .catch(err => {
                console.error('Update failed:', err);
                alert('An unexpected error occurred. Please check the console and try again.');
            });
        }
    });
}
</script>

<style>
/* Basic styling for the drag-and-drop list */
.bracket-preview-container ul { 
    list-style-type: none; 
    padding: 0; 
    max-width: 400px;
}
.team-draggable { 
    padding: 12px; 
    border: 1px solid #ccc; 
    margin-bottom: 6px; 
    background-color: #f9f9f9; 
    cursor: grab; 
    display: flex;
    align-items: center;
    border-radius: 4px;
}
.drag-handle { 
    margin-right: 15px; 
    cursor: move; 
    color: #555;
}
.bye-indicator { 
    color: #28a745; 
    margin-left: auto;
    font-weight: bold;
}
/* Visual pairing styles to make matchups clear */
.pair-1 { border-left: 5px solid #007bff; }
.pair-2 { border-left: 5px solid #dc3545; }
.pair-3 { border-left: 5px solid #ffc107; }
.pair-4 { border-left: 5px solid #17a2b8; }
.pair-5 { border-left: 5px solid #6f42c1; }
.pair-6 { border-left: 5px solid #fd7e14; }
.pair-7 { border-left: 5px solid #20c997; }
.pair-8 { border-left: 5px solid #6610f2; }
</style>
