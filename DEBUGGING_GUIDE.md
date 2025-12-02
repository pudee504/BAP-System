### Comprehensive Debugging Guide for Capstone Defense

This guide outlines potential areas of your codebase that panelists may target during a debugging session. It is designed to help you anticipate their questions and prepare robust answers.

---

#### 1. Database Security & SQL Injection

This is a critical area. Panelists will want to see that you've protected your application from unauthorized data access and manipulation.

*   **Vulnerability:** Hardcoded Database Credentials
    *   **File:** `config.php`
    *   **How they'll break it:** A panelist might not "break" the code with this, but they will almost certainly question it. They might also change the credentials in `config.php` to incorrect values to see how your application handles a database connection failure.
    *   **Potential Questions:**
        *   "What are the security risks of storing your database password in a file like this?"
        *   "If this project were to go into a live production environment, what changes would you make to this file?" (Answer: Use environment variables).
        *   "If I change the password here to 'wrongpassword', what is the expected behavior of your application?" (Answer: It should display a user-friendly error message and not crash).

*   **Vulnerability:** SQL Injection
    *   **Files to watch:** `login.php`, `add_team.php`, and any other file that takes user input to query the database.
    *   **How they'll break it:** Your code consistently uses **prepared statements** (e.g., `$pdo->prepare(...)` followed by `$stmt->execute(...)`), which is the correct way to prevent SQL injection. The panelists will know this, so they will likely try to trick you. They might ask you to explain *why* your code is safe, or they might try to find a place where you are *not* using prepared statements.
    *   **Potential Questions:**
        *   "What is SQL Injection and can you give me an example?"
        *   "Is your application vulnerable to SQL injection? Point to the specific lines of code that prevent it."
        *   (If they find a query without prepared statements): "What is wrong with this query? How would you fix it?"

---

#### 2. Input Validation & Cross-Site Scripting (XSS)

This is about ensuring that the data entered by users is safe and won't harm other users of the application.

*   **Vulnerability:** Cross-Site Scripting (XSS)
    *   **Files to watch:** `add_team.php`, `edit_player.php`, and any other pages where user input is saved and then displayed.
    *   **How they'll break it:** A panelist might try to create a new team with a malicious name like: `<script>alert('You have been hacked');</script>`. If your application is vulnerable, this script will be saved to the database, and when you view the team list, the script will execute in your browser, showing an alert box.
    *   **Potential Questions:**
        *   "What is Cross-Site Scripting (XSS)?"
        *   "If I enter a JavaScript alert as a team name, what do you expect to happen on the team details page?" (Answer: The script should be displayed as plain text, not executed. This is because you should be using `htmlspecialchars()` when displaying user-provided data).
        *   "Show me the code you are using to prevent XSS."

---

#### 3. Authentication and Session Management

This section is about ensuring that only authorized users can access sensitive parts of your application.

*   **Vulnerability:** Insecure Session Management
    *   **Files to watch:** `login.php`, `logout.php`, and any pages that are supposed to be for logged-in users only.
    *   **How they'll break it:** A panelist might try to access an admin page like `dashboard.php` by directly typing the URL in the browser without logging in. They might also ask you about session fixation or session hijacking.
    *   **Potential Questions:**
        *   "How do you protect pages that require a user to be logged in?" (Answer: You should have a check at the top of each protected page like `if (!isset($_SESSION['user_id'])) { ... }`).
        *   "What is the purpose of `session_start()`?"
        *   "What happens to the user's session when they click the logout button?" (Answer: `session_destroy()` should be called).
        *   "Does your application have a session timeout? Why is this important for security?"

---

#### 4. Business Logic

This is where they will test your deep understanding of how your system works. They will try to find edge cases or logical flaws in your application's core features.

*   **Vulnerability:** Flaws in Tournament Logic
    *   **Files to watch:** `round_robin.php`, `single_elimination.php`, `double_elimination.php`, `manage_game.php`.
    *   **How they'll break it:**
        *   They might try to add more teams than the specified limit for a category and see how your system handles it.
        *   In `manage_game.php`, they might try to give points to a player who has fouled out.
        *   They might try to declare a winner in a game that is still ongoing.
        *   They could try to create a tournament with an odd number of teams to see if your bracket generation logic can handle byes correctly.
    *   **Potential Questions:**
        *   "Walk me through the code that generates the schedule for a Round Robin tournament."
        *   "What happens in your system if a team is disqualified? How would you implement that?"
        *   "If I change the score of a completed game, what other parts of the system would be affected?" (Answer: The team standings, the bracket, and potentially the next round of matches).
        *   "Explain the logic in `update_stat.php`. How does it prevent a user from, for example, having negative points?"

---

#### 5. Error Handling

A robust application should handle errors gracefully and not expose sensitive information.

*   **Vulnerability:** Poor Error Handling
    *   **Files to watch:** `db.php`, and any file that performs critical operations.
    *   **How they'll break it:** A panelist could introduce a typo in a function name or a filename to see what happens. For example, they might change `require 'db.php';` to `require 'db_nonexistent.php';`. This will cause a fatal error, and they will want to see if your application shows a raw, ugly PHP error message (which is bad) or a custom, user-friendly error page (which is good).
    *   **Potential Questions:**
        *   "What happens if your application can't connect to the database?"
        *   "If I introduce a fatal error in your code, what will the user see?"
        *   "Why is it a bad idea to show raw PHP errors to a user?" (Answer: It can expose sensitive information about your server and code).
        *   "How does your `logger.php` file help you as a developer?"

### How to Prepare

*   **Read Your Own Code:** Go through the files I've listed and make sure you understand the logic.
*   **Practice Explaining:** For each of the potential questions above, practice explaining the answer out loud.
*   **Don't Be Afraid to Say "I Don't Know":** If they ask you something you truly don't know, it's better to be honest and then explain how you would go about finding the answer.
*   **Stay Calm:** They are trying to test your knowledge, not to make you fail. Take a deep breath and think through the problem logically.
