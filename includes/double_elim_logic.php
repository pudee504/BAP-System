<?php
// FILENAME: double_elim_logic.php
// DESCRIPTION: Contains shared functions to generate the structure and round names
// for a double-elimination tournament bracket.

if (!function_exists('getRoundName')) {
    /**
     * Generates a consistent display name for a given round.
     * (e.g., "Winners Round 1", "Losers Round 3").
     */
    function getRoundName($total_bracket_size, $bracket_type = 'winner', $round_num = 1) {
        if ($bracket_type === 'winner') {
            return "Winners Round " . $round_num;
        } else {
            return "Losers Round {$round_num}";
        }
    }
}

if (!function_exists('generate_double_elimination_matches')) {
    /**
     * Creates a complete double elimination bracket structure (Winners, Losers, Grand Final).
     * Uses a single, unified display counter (Match 1, Match 2...) for all games.
     */
    function generate_double_elimination_matches($teams_by_position) {
        // --- 1. SETUP ---
        $num_teams = count($teams_by_position);
        if ($num_teams < 2) return null;

        // Calculate the nearest power-of-two bracket size (e.g., 6 teams -> 8).
        $bracket_size = 2 ** ceil(log($num_teams, 2));
        // Calculate total rounds needed for the winners' bracket.
        $total_wb_rounds = log($bracket_size, 2);

        $winners_bracket = [];
        $losers_bracket = [];
        $grand_final = [];
        $all_matches_by_id = []; // Stores all matches for easy lookup by ID.

        // $w_match_counter / $l_match_counter are for internal IDs (W1, L1).
        $w_match_counter = 1;
        $l_match_counter = 1;
        // $display_counter is for the user-facing "Match 1", "Match 2", etc.
        $display_counter = 1;


        // --- 2. GENERATE WINNERS' BRACKET ---
        
        // Populate the first round participants, filling empty slots with BYEs.
        $wb_round_participants = [];
        for ($i = 1; $i <= $bracket_size; $i++) {
            $wb_round_participants[$i] = $teams_by_position[$i] ?? ['name' => 'BYE', 'is_bye' => true];
        }

        // Loop through each round of the winners' bracket.
        for ($r = 1; $r <= $total_wb_rounds; $r++) {
            $winners_bracket[$r] = [];
            $next_round_participants = [];
            $p_values = array_values($wb_round_participants);

            // Pair participants using standard seeding: 1 vs N, 2 vs N-1, etc.
            for ($i = 0; $i < count($p_values) / 2; $i++) {
                $home = $p_values[$i];
                $away = $p_values[count($p_values) - 1 - $i];
                
                // Handle automatic advancement for BYEs.
                if (isset($home['is_bye'])) { $next_round_participants[] = $away; continue; }
                if (isset($away['is_bye'])) { $next_round_participants[] = $home; continue; }

                // Create the match.
                $match_id = 'W' . $w_match_counter++;
                $match_number_text = 'Match ' . $display_counter++;
                $match = ['id' => $match_id, 'round' => $r, 'bracket_type' => 'winner', 'home' => $home, 'away' => $away, 'match_number' => $match_number_text];
                
                $winners_bracket[$r][] = $match;
                $all_matches_by_id[$match_id] = $match;
                // Create the placeholder for the winner of this match.
                $next_round_participants[] = ['is_placeholder' => true, 'source_match_id' => $match_id, 'source_slot' => 'winner', 'name' => 'Winner of ' . $match_number_text];
            }
            $wb_round_participants = $next_round_participants; // Seed the next round.
        }
        // The last remaining "participant" is the WB champion (or their placeholder).
        $wb_champion = $wb_round_participants[0];

        // --- 3. GENERATE LOSERS' BRACKET ---
        $lb_round_num = 1;
        $advancing_players = []; // Players advancing from the *previous* LB round.

        // Loop through WB rounds (except the final) to build the LB.
        for ($r = 1; $r < $total_wb_rounds; $r++) {
            // Get all losers from the current WB round.
            $newly_dropped_losers = [];
            foreach ($winners_bracket[$r] as $match) {
                $newly_dropped_losers[] = ['is_placeholder' => true, 'source_match_id' => $match['id'], 'source_slot' => 'loser', 'name' => 'Loser of ' . $match['match_number']];
            }
            $newly_dropped_losers = array_reverse($newly_dropped_losers);

            // Interleave advancing LB players with newly dropped WB players.
            // This is the core logic of a standard double-elimination bracket.
            $current_round_participants = [];
            $count = max(count($advancing_players), count($newly_dropped_losers));
            for ($i=0; $i<$count; $i++) {
                if(isset($advancing_players[$i])) $current_round_participants[] = $advancing_players[$i];
                if(isset($newly_dropped_losers[$i])) $current_round_participants[] = $newly_dropped_losers[$i];
            }

            $current_round_matches = [];
            $next_advancing_players = [];

            // Pair up the interleaved participants to create this round's LB matches.
            for ($i = 0; $i < count($current_round_participants); $i += 2) {
                $home = $current_round_participants[$i];
                $away = $current_round_participants[$i+1] ?? null;

                if ($away === null) { // Handle a bye in the losers' bracket.
                    $next_advancing_players[] = $home;
                    continue;
                }

                // Create the LB match.
                $match_id = 'L' . $l_match_counter++;
                $match_number_text = 'Match ' . $display_counter++;
                $match = ['id' => $match_id, 'round' => $lb_round_num, 'bracket_type' => 'loser', 'home' => $home, 'away' => $away, 'match_number' => $match_number_text];
                
                $current_round_matches[] = $match;
                $all_matches_by_id[$match_id] = $match;
                $next_advancing_players[] = ['is_placeholder' => true, 'source_match_id' => $match_id, 'source_slot' => 'winner', 'name' => 'Winner of ' . $match_number_text];
            }
            
            if (!empty($current_round_matches)) {
                $losers_bracket[$lb_round_num] = $current_round_matches;
                $lb_round_num++;
            }
            $advancing_players = $next_advancing_players; // Seed the next LB round.
        }

        // --- 4. CREATE FINAL LOSERS' BRACKET MATCHES ---
        
        // Add the loser of the Winners' Bracket Final to the advancing LB players.
        $final_wb_loser_match = end($winners_bracket[$total_wb_rounds]);
        $advancing_players[] = ['is_placeholder' => true, 'source_match_id' => $final_wb_loser_match['id'], 'source_slot' => 'loser', 'name' => 'Loser of ' . $final_wb_loser_match['match_number']];

        // Continue creating LB rounds until only one player (LB champion) remains.
        while (count($advancing_players) > 1) {
            $losers_bracket[$lb_round_num] = [];
            $next_round_advancers = [];
            for ($i = 0; $i < count($advancing_players); $i += 2) {
                $home = $advancing_players[$i];
                $away = $advancing_players[$i+1] ?? null;

                if ($away === null) { $next_round_advancers[] = $home; continue; }
                
                $match_id = 'L' . $l_match_counter++;
                $match_number_text = 'Match ' . $display_counter++;
                $match = ['id' => $match_id, 'round' => $lb_round_num, 'bracket_type' => 'loser', 'home' => $home, 'away' => $away, 'match_number' => $match_number_text];
                $losers_bracket[$lb_round_num][] = $match;
                $all_matches_by_id[$match_id] = $match;
                $next_round_advancers[] = ['is_placeholder' => true, 'source_match_id' => $match_id, 'source_slot' => 'winner', 'name' => 'Winner of ' . $match_number_text];
            }
            $advancing_players = $next_round_advancers;
            $lb_round_num++;
        }
        $lb_champion = $advancing_players[0] ?? null;

        // --- 5. GRAND FINAL ---
        // Create the Grand Final (and potential reset) from the WB and LB champions.
        if ($wb_champion && $lb_champion) {
            // First Grand Final match.
            $gf1_match_number_text = 'Match ' . $display_counter++;
            $gf1_match = ['id' => 'GF1', 'round' => 98, 'bracket_type' => 'grand_final', 'home' => $wb_champion, 'away' => $lb_champion, 'match_number' => $gf1_match_number_text, 'round_name' => 'Grand Final'];
            $grand_final[] = $gf1_match;
            $all_matches_by_id['GF1'] = $gf1_match;

            // Create placeholders for the potential second (reset) match.
            $gf2_home = ['is_placeholder' => true, 'source_match_id' => 'GF1', 'source_slot' => 'winner', 'name' => 'Winner of ' . $gf1_match_number_text];
            $gf2_away = ['is_placeholder' => true, 'source_match_id' => 'GF1', 'source_slot' => 'loser', 'name' => 'Loser of ' . $gf1_match_number_text];
            
            // Second (reset) Grand Final match.
            $gf2_match_number_text = 'Match ' . $display_counter++;
            $gf2_match = ['id' => 'GF2', 'round' => 99, 'bracket_type' => 'grand_final', 'home' => $gf2_home, 'away' => $gf2_away, 'match_number' => $gf2_match_number_text, 'round_name' => 'Grand Final (Reset)'];
            $grand_final[] = $gf2_match;
            $all_matches_by_id['GF2'] = $gf2_match;
        }

        return ['winners' => $winners_bracket, 'losers' => $losers_bracket, 'grand_final' => $grand_final, 'all_matches' => $all_matches_by_id];
    }
}