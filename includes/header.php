<?php
// FILENAME: includes/header.php
// DESCRIPTION: This is the main navigation header for the application.

// Ensure a session is started, as this header relies on session variables
// to display the username and role-specific links.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<header class="topbar">
    <div class="left-section">
        <div class="logo">
            <img src="assets/bap_logo.jpg" alt="Logo" class="logo-img">
        </div>
        <nav class="nav-bar">
            <a href="dashboard.php" class="nav-link">Leagues</a>
            
            <?php // Conditional check: Only show the "Users" link if the logged-in user has the 'Admin' role.
            if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'Admin'): ?>
                <a href="users.php" class="nav-link">Users</a>
            <?php endif; ?>

        </nav>
    </div>

    <div class="user-menu">
        
        <?php // Display the username if the user is logged in.
        if (isset($_SESSION['username'])): ?>
            <span class="username-display"><?= htmlspecialchars($_SESSION['username']); ?></span>
        <?php endif; ?>

        <img src="assets/user.png" alt="User Icon" class="user-icon" id="userIcon">
        
        <div class="dropdown-content" id="userDropdown">
            <a href="change_password.php">Change Password</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
</header>

<style>
    /* --- NAVIGATION BAR STYLES --- */
    .nav-bar {
        display: flex;
        gap: 1.5rem; 
    }
    .nav-link {
        color: white;
        text-decoration: none;
        font-size: 20px;
        font-weight: bold;
        padding: 8px 16px;
        border-radius: 20px;
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    .nav-link:hover {
        background-color: rgba(255, 255, 255, 0.15);
        text-decoration: none;
    }
    /* Style for the link corresponding to the current page */
    .nav-link.active {
        background-color: rgba(255, 255, 255, 0.25);
        color: #ffc107;
    }

    /* --- USER MENU STYLES --- */
    .user-menu { position: relative; display: flex; align-items: center; gap: 10px; }
    .username-display { font-weight: bold; color: #fff; }
    .user-icon { cursor: pointer; }
    .dropdown-content { display: none; position: absolute; top: 100%; right: 0; margin-top: 5px; background-color: #f9f9f9; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 100; border-radius: 5px; overflow: hidden; }
    .dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; text-align: left; }
    .dropdown-content a:hover { background-color: #ddd; }
    .show { display: block; } /* This class is toggled by JS to show/hide the dropdown */
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const userIcon = document.getElementById('userIcon');
    const userDropdown = document.getElementById('userDropdown');

    // Toggle the 'show' class on the dropdown when the user icon is clicked.
    userIcon.addEventListener('click', function(event) {
        userDropdown.classList.toggle('show');
        event.stopPropagation(); // Stop the click from immediately bubbling to the window
    });

    // Close the dropdown if the user clicks anywhere else on the page.
    window.addEventListener('click', function(event) {
        if (!event.target.closest('.user-menu')) {
            if (userDropdown.classList.contains('show')) {
                userDropdown.classList.remove('show');
            }
        }
    });

    // --- Active Page Link Highlighter ---
    // Get the current page filename (e.g., "dashboard.php").
    const currentPage = window.location.pathname.split("/").pop();
    // Loop through all nav links.
    document.querySelectorAll('.nav-bar a').forEach(link => {
        // If the link's href matches the current page, add the 'active' class.
        if (link.getAttribute('href') === currentPage) {
            link.classList.add('active');
        }
    });

    // --- Disable Autocomplete ---
    // Find all common text-based inputs and disable browser autocomplete.
    const inputs = document.querySelectorAll('input[type="text"], input[type="password"], input[type="email"], input[type="number"]');
    inputs.forEach(input => {
        input.setAttribute('autocomplete', 'off');
    });
});

</script>