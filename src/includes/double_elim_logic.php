<?php
// This file contains the shared logic for generating a double-elimination bracket structure.

if (!function_exists('getRoundName')) {
    /**
     * Generates a display name for a given round.
     */
    function getRoundName($total_bracket_size, $bracket_type = 'winner', $round_num = 1) {
        if ($bracket_type === 'winner') {
            // MODIFIED: Removed special names like 'Final', 'Semifinals' to use a consistent naming scheme.
            return "Winners Round " . $round_num;
        } else {
            // This was already in the desired format.
            return "Losers Round {$round_num}";
        }
    }
}

if (!function_exists('generate_double_elimination_matches')) {
    /**
     * Creates a complete double elimination bracket structure.
     * This version uses a unified match numbering system (Match 1, Match 2, etc.).
     */
    function generate_double_elimination_matches($teams_by_position) {
        // --- 1. SETUP ---
        $num_teams = count($teams_by_position);
        if ($num_teams < 2) return null;

        $bracket_size = 2 ** ceil(log($num_teams, 2));
        $total_wb_rounds = log($bracket_size, 2);

        $winners_bracket = [];
        $losers_bracket = [];
        $grand_final = [];
        $all_matches_by_id = [];

        // Counters
        $w_match_counter = 1;
        $l_match_counter = 1;
        $display_counter = 1;


        // --- 2. GENERATE WINNERS' BRACKET ---
        $wb_round_participants = [];
        for ($i = 1; $i <= $bracket_size; $i++) {
            $wb_round_participants[$i] = $teams_by_position[$i] ?? ['name' => 'BYE', 'is_bye' => true];
        }

        for ($r = 1; $r <= $total_wb_rounds; $r++) {
            $winners_bracket[$r] = [];
            $next_round_participants = [];
            $p_values = array_values($wb_round_participants);

            for ($i = 0; $i < count($p_values) / 2; $i++) {
                $home = $p_values[$i];
                $away = $p_values[count($p_values) - 1 - $i];
                
                if (isset($home['is_bye'])) { $next_round_participants[] = $away; continue; }
                if (isset($away['is_bye'])) { $next_round_participants[] = $home; continue; }

                $match_id = 'W' . $w_match_counter++;
                $match_number_text = 'Match ' . $display_counter++;
                $match = ['id' => $match_id, 'round' => $r, 'bracket_type' => 'winner', 'home' => $home, 'away' => $away, 'match_number' => $match_number_text];
                
                $winners_bracket[$r][] = $match;
                $all_matches_by_id[$match_id] = $match;
                $next_round_participants[] = ['is_placeholder' => true, 'source_match_id' => $match_id, 'source_slot' => 'winner', 'name' => 'Winner of ' . $match_number_text];
            }
            $wb_round_participants = $next_round_participants;
        }
        $wb_champion = $wb_round_participants[0];

        // --- 3. GENERATE LOSERS' BRACKET ---
        $lb_round_num = 1;
        $advancing_players = [];

        for ($r = 1; $r < $total_wb_rounds; $r++) {
            $newly_dropped_losers = [];
            foreach ($winners_bracket[$r] as $match) {
                $newly_dropped_losers[] = ['is_placeholder' => true, 'source_match_id' => $match['id'], 'source_slot' => 'loser', 'name' => 'Loser of ' . $match['match_number']];
            }
            $newly_dropped_losers = array_reverse($newly_dropped_losers);

            $current_round_participants = [];
            $count = max(count($advancing_players), count($newly_dropped_losers));
            for ($i=0; $i<$count; $i++) {
                if(isset($advancing_players[$i])) $current_round_participants[] = $advancing_players[$i];
                if(isset($newly_dropped_losers[$i])) $current_round_participants[] = $newly_dropped_losers[$i];
            }

            $current_round_matches = [];
            $next_advancing_players = [];

            for ($i = 0; $i < count($current_round_participants); $i += 2) {
                $home = $current_round_participants[$i];
                $away = $current_round_participants[$i+1] ?? null;

                if ($away === null) {
                    $next_advancing_players[] = $home;
                    continue;
                }

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
            $advancing_players = $next_advancing_players;
        }

        // --- 4. CREATE FINAL LOSERS' BRACKET MATCHES ---
        $final_wb_loser_match = end($winners_bracket[$total_wb_rounds]);
        $advancing_players[] = ['is_placeholder' => true, 'source_match_id' => $final_wb_loser_match['id'], 'source_slot' => 'loser', 'name' => 'Loser of ' . $final_wb_loser_match['match_number']];

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
        if ($wb_champion && $lb_champion) {
            $gf1_match_number_text = 'Match ' . $display_counter++;
            // MODIFICATION: Added 'round_name' for proper display.
            $gf1_match = ['id' => 'GF1', 'round' => 98, 'bracket_type' => 'grand_final', 'home' => $wb_champion, 'away' => $lb_champion, 'match_number' => $gf1_match_number_text, 'round_name' => 'Grand Final'];
            $grand_final[] = $gf1_match;
            $all_matches_by_id['GF1'] = $gf1_match;

            $gf2_home = ['is_placeholder' => true, 'source_match_id' => 'GF1', 'source_slot' => 'winner', 'name' => 'Winner of ' . $gf1_match_number_text];
            $gf2_away = ['is_placeholder' => true, 'source_match_id' => 'GF1', 'source_slot' => 'loser', 'name' => 'Loser of ' . $gf1_match_number_text];
            
            $gf2_match_number_text = 'Match ' . $display_counter++;
            // MODIFICATION: Added 'round_name' for the potential reset match.
            $gf2_match = ['id' => 'GF2', 'round' => 99, 'bracket_type' => 'grand_final', 'home' => $gf2_home, 'away' => $gf2_away, 'match_number' => $gf2_match_number_text, 'round_name' => 'Grand Final (Reset)'];
            $grand_final[] = $gf2_match;
            $all_matches_by_id['GF2'] = $gf2_match;
        }

        return ['winners' => $winners_bracket, 'losers' => $losers_bracket, 'grand_final' => $grand_final, 'all_matches' => $all_matches_by_id];
    }
}