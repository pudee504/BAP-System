You should **not** include all of your code in your documentation. Good documentation highlights the most important, representative, and complex parts of the codebase to explain the architecture and key patterns. Including everything would make it unreadable and obscure the critical information.

Based on my analysis of your system, you should focus on showcasing the following areas. Here is a complete list of the specific files and code snippets that would be most valuable for your "Relevant Source Code" section.

---

### 1. System Core: Database and Configuration

This section demonstrates the foundational setup of the application.

*   **File:** `db.php`
    *   **Code to Include:** The entire file.
    *   **Reason:** It's short, critical, and perfectly shows how you establish a secure, reusable database connection using PDO, which is a cornerstone of your entire application.

*   **File:** `config.php`
    *   **Code to Include:** The structure of the return array, with placeholder values.
    *   **Reason:** It shows your configuration management strategy without exposing sensitive credentials.
    ```php
    <?php
    // config.php
    return [
        'host' => 'localhost',
        'dbname' => 'your_db_name',
        'username' => 'your_username',
        'password' => 'your_password',
    ];
    ?>
    ```

---

### 2. User Authentication and Authorization

This section explains how you secure the system and control access.

*   **File:** `login.php`
    *   **Code to Include:** The PHP block that processes the form submission.
    *   **Reason:** It shows how you verify user credentials against the database, start a session, and handle login errors.

*   **File:** `dashboard.php` (or any protected page)
    *   **Code to Include:** The session check at the top of the file.
    *   **Reason:** This snippet is the primary mechanism for protecting pages and demonstrates your authentication enforcement pattern.
    ```php
    <?php
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    // ... rest of the page
    ?>
    ```

*   **File:** `includes/auth_functions.php`
    *   **Code to Include:** The `has_league_permission()` function.
    *   **Reason:** This is the heart of your role-based access control (RBAC) and shows how you check if a user has the authority to manage specific parts of the system.

---

### 3. Data Management (CRUD Operations)

Showcasing one clear example of Creating, Reading, Updating, and Deleting data is sufficient.

*   **File:** `add_team.php`
    *   **Code to Include:** The PHP `if ($_SERVER['REQUEST_METHOD'] == 'POST')` block.
    *   **Reason:** It's a perfect, concise example of how you handle form submissions and securely insert data into the database using prepared statements to prevent SQL injection.

*   **File:** `edit_player.php`
    *   **Code to Include:** The initial PHP block that fetches the player's current data and the subsequent block that processes the form update.
    *   **Reason:** This demonstrates the "Read" and "Update" parts of CRUD, again highlighting the use of secure prepared statements.

---

### 4. Tournament and Scheduling Logic

This section showcases the core business logic of the application.

*   **File:** `single_elimination.php`
    *   **Code to Include:** The primary function or loop responsible for generating the bracket structure and matchups.
    *   **Reason:** This is a key piece of your application's domain logic and shows how you translate tournament rules into code.

*   **File:** `round_robin.php`
    *   **Code to Include:** The function or logic that generates the schedule of games.
    *   **Reason:** It demonstrates a different, important type of scheduling logic.

---

### 5. Real-Time Game Management (Most Important Section)

This is the most complex and unique feature of your system. You should dedicate the most space to it.

*   **File:** `manage_game.php`
    *   **Code to Include:**
        1.  The JavaScript `fetchAndApplyState()` function.
        2.  A JavaScript function that updates a stat, like `updateStat()`.
        3.  The JavaScript `undoAction()` function.
    *   **Reason:** These snippets are crucial. They demonstrate the SPA-like, real-time nature of the game module, showing how the frontend polls for state, sends updates to the server, and handles unique features like the undo log.

*   **File:** `get_game_state.php`
    *   **Code to Include:** The entire PHP script.
    *   **Reason:** This is the backend heart of the real-time functionality. It shows how you query the database for all relevant game data and package it as JSON for the frontend.

*   **File:** `update_stat.php`
    *   **Code to Include:** The entire PHP script.
    *   **Reason:** It's a perfect example of a backend endpoint that receives data from the frontend, securely updates the database, logs the action, and returns a success/error response.

---

### 6. Frontend Structure

This shows how you maintain a consistent look and feel.

*   **File:** `includes/header.php`
    *   **Code to Include:** The entire file.
    *   **Reason:** It demonstrates how you handle common UI elements like the navigation bar, how you include CSS, and how the UI can change based on the user's session state.

By including these specific, well-chosen snippets, your documentation will be professional, informative, and easy to understand without overwhelming the reader.
