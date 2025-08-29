<?php
// It's good practice to start the session in files that need session data,
// though your other pages likely already do this.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<header class="topbar">
    <div class="left-section">
        <div class="logo">
            <img src="bap_logo.jpg" alt="Logo" class="logo-img">
        </div>
        <nav class="nav-bar">
            <a href="dashboard.php" class="nav-link">Leagues</a>
        </nav>
    </div>

    <div class="user-menu">
        
        <?php if (isset($_SESSION['username'])): ?>
            <span class="username-display"><?= htmlspecialchars($_SESSION['username']); ?></span>
        <?php endif; ?>

        <img src="user.png" alt="User Icon" class="user-icon" id="userIcon">
        
        <div class="dropdown-content" id="userDropdown">
            <a href="logout.php">Logout</a>
        </div>
    </div>
    </header>

<style>
    .user-menu {
        position: relative;
        display: flex; /* MODIFIED: Use flexbox for easy alignment */
        align-items: center; /* Vertically centers the username and icon */
        gap: 10px; /* ADDED: Creates space between the username and icon */
    }
    /* ADDED: Style for the username text */
    .username-display {
        font-weight: bold;
        color: #333; /* You can change this color to match your theme */
    }
    .user-icon {
        cursor: pointer;
    }
    .dropdown-content {
        display: none;
        position: absolute;
        top: 100%; /* Positions the dropdown right below the header elements */
        right: 0;
        margin-top: 5px; /* Adds a little space below the icon */
        background-color: #f9f9f9;
        min-width: 120px;
        box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
        z-index: 100;
        border-radius: 5px;
        overflow: hidden;
    }
    .dropdown-content a {
        color: black;
        padding: 12px 16px;
        text-decoration: none;
        display: block;
        text-align: left;
    }
    .dropdown-content a:hover {
        background-color: #ddd;
    }
    .show {
        display: block;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const userIcon = document.getElementById('userIcon');
    const userDropdown = document.getElementById('userDropdown');

    // Toggles the dropdown's visibility when the user icon is clicked
    userIcon.addEventListener('click', function(event) {
        userDropdown.classList.toggle('show');
        event.stopPropagation(); // Prevents the window click from closing it immediately
    });

    // Closes the dropdown if the user clicks anywhere else on the page
    window.addEventListener('click', function(event) {
        // Check if the click is outside the user-menu container
        if (!event.target.closest('.user-menu')) {
            if (userDropdown.classList.contains('show')) {
                userDropdown.classList.remove('show');
            }
        }
    });
});
</script>