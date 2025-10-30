<?php
// ============================================================
// File: includes/category_tabs_standings.php
// Purpose: Displays the Standings tab content depending on the
//          tournament format (Bracket or Round Robin).
// ============================================================
?>

<div class="tab-content <?= $active_tab === 'standings' ? 'active' : '' ?>" id="standings">

    <?php 
    // This file acts as a switch, checking the format and showing the correct content.
    // The '$is_bracket_format' variable comes from your 'category_info.php' file.
    
    // --- THIS PART HANDLES BRACKET FORMATS (SINGLE & DOUBLE ELIMINATION) ---
    if ($is_bracket_format): 
    ?>
    
        <div class="section-header">
            <h2>Bracket Setup</h2>
        </div>

        <?php 
        // Show a message if not all teams have been added yet.
        if (!$all_slots_filled): 
        ?>
            <p class="info-message">Please add all <?= $category['num_teams'] ?> teams in the 'Teams' tab before setting up the bracket.</p>
        
        <?php 
        // Otherwise, show the correct bracket visualizer.
        else: 
        ?>
            <?php 
            // Check if the format is Double Elimination.
            if ($category['tournament_format'] === 'Double Elimination') {
                require 'includes/double_elim_visualizer.php'; 
            } 
            // Otherwise, default to the Single Elimination visualizer.
            else {
                require 'includes/single_elim_visualizer.php'; 
            }
            ?>
        <?php endif; ?>

    <?php 
    // --- THIS PART HANDLES NON-BRACKET FORMATS (ROUND ROBIN) ---
    else: 
    ?>
        <?php 
        // This single line now loads the entire standings table from the other file.
        require 'includes/round_robin_standings.php'; 
        ?>
    <?php endif; ?>

</div>
