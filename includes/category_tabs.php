<?php
// ============================================================
// File: includes/category_tabs.php
// Purpose: Displays the tab navigation for Teams, Schedule, and Standings.
// ============================================================
?>

<!-- ============================================================
     TAB NAVIGATION
     - Highlights the active tab based on the current page
     - Tabs: Teams | Schedule | Standings
============================================================ -->
<div class="tabs">
  <div class="tab <?= $active_tab === 'teams' ? 'active' : '' ?>" data-tab="teams">Teams</div>
  <div class="tab <?= $active_tab === 'schedule' ? 'active' : '' ?>" data-tab="schedule">Schedule</div>
  <div class="tab <?= $active_tab === 'standings' ? 'active' : '' ?>" data-tab="standings">Standings</div>
</div>
