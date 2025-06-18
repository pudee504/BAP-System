<?php
$missing_seed = false;
$duplicate_seed = false;
$non_consecutive_seed = false;

$needs_check = !$is_round_robin; // Bracket categories need seeding validation

if ($team_count == $category['num_teams']) {
    if ($needs_check) {
        $seeds = array_map(fn($t) => (int) $t['seed'], $teams);
        $unique_seeds = array_unique($seeds);

        if (in_array(0, $seeds) || count($seeds) != $category['num_teams']) {
            $missing_seed = true;
        } elseif (count($unique_seeds) != $category['num_teams']) {
            $duplicate_seed = true;
        } else {
            sort($unique_seeds);
            if ($unique_seeds !== range(1, $category['num_teams'])) {
                $non_consecutive_seed = true;
            }
        }
    }

    if (!$category['is_locked']) {
        if ($needs_check && ($missing_seed || $duplicate_seed || $non_consecutive_seed)) {
            echo '<p style="color:red;"><strong>Fix seedings before locking:</strong><ul>';
            if ($missing_seed) echo '<li>Some teams have no seeds assigned.</li>';
            if ($duplicate_seed) echo '<li>Some seeds are duplicated.</li>';
            if ($non_consecutive_seed) echo '<li>Seeds must be in order from 1 to ' . $category['num_teams'] . '.</li>';
            echo '</ul></p>';
        } else {
            // Lock button
            echo '<form action="lock_seedings.php" method="POST" onsubmit="return confirm(\'Lock seedings/groupings? This cannot be undone.\')">';
            echo '<input type="hidden" name="category_id" value="' . $category_id . '">';
            echo '<button class="login-button" type="submit">🔒 Lock ' . ($is_round_robin ? 'Groupings' : 'Seedings') . '</button>';
            echo '</form>';
        }
    } else {
        // Already locked
        echo '<p style="color:green;"><strong>Seedings / Groupings are locked.</strong></p>';
        echo '<form action="unlock_seedings.php" method="POST" onsubmit="return confirm(\'Are you sure you want to unlock? This allows further changes.\')">';
        echo '<input type="hidden" name="category_id" value="' . $category_id . '">';
        echo '<button class="login-button" type="submit">🔓 Unlock ' . ($is_round_robin ? 'Groupings' : 'Seedings') . '</button>';
        echo '</form>';
    }
}
?>
