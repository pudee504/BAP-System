# System Design Documentation

## Overall System Design

The Sports League Management System is a monolithic web application built on a classic PHP and MySQL stack, designed to provide a comprehensive platform for managing sports leagues. The architecture follows a traditional server-side rendering model, where PHP scripts generate HTML content dynamically. However, it incorporates modern client-side techniques for real-time features, particularly in the live game management module, which functions like a single-page application (SPA).

The system is logically divided into three primary layers:

1.  **Presentation Layer (Frontend):** This layer is responsible for the user interface and user experience. It consists of PHP files that produce HTML, styled with standard CSS. Interactivity is powered by JavaScript, which is extensively used in the `manage_game.php` section to create a real-time experience. This frontend communicates with the backend via asynchronous `fetch` requests to API-like PHP endpoints to send and receive game state updates without full page reloads.

2.  **Application Layer (Backend):** This is the core of the system, containing the business logic. It's a collection of PHP scripts that handle specific functionalities. A centralized database connection is managed by `config.php` and `db.php` using the PDO extension for secure database communication. The application's features are organized into separate PHP files, such as `create_league.php`, `add_team.php`, and `users.php`. A notable component is the set of scripts that act as a quasi-API for the live game management module, including `get_game_state.php`, `update_stat.php`, and `log_game_action.php`. Authentication and authorization are handled through PHP sessions and dedicated functions in `includes/auth_functions.php`.

3.  **Data Layer (Database):** The persistence layer consists of a MySQL database. It stores all the application's data, including information about users, leagues, teams, players, game schedules, and real-time game statistics. The database schema is relational, utilizing foreign key constraints to ensure data integrity and consistency across the application.

### Key Architectural Patterns and Technologies:

*   **Monolithic Architecture:** The entire application is a single, cohesive unit.
*   **Server-Side Rendering (SSR):** PHP is used to generate HTML on the server.
*   **AJAX/Single-Page Application (SPA)-like module:** The live game management feature uses JavaScript to asynchronously communicate with the server, providing a dynamic, real-time user experience.
*   **Procedural Programming:** The PHP code is primarily organized into a procedural style, with functions and scripts dedicated to specific tasks.
*   **Stack:** PHP, MySQL, JavaScript, HTML, CSS.
*   **Dependencies:** The system uses Composer for dependency management, with a notable dependency on `endroid/qr-code` for generating QR codes.

---

## Diagram for System Design

To visually represent this architecture, a **C4 Model-inspired Component Diagram** is the most suitable choice. This type of diagram provides a clear overview of the major components of the system and how they interact with each other. Below is a description of the components and connections you should include in your diagram.

**Title:** Sports League Management System - Component Diagram

**Components (Boxes):**

1.  **Web Browser (Client):**
    *   **Description:** The primary interface for users, including Admins, League Managers, and Spectators.
    *   **Technology:** HTML, CSS, JavaScript.
    *   Represents the user's device.

2.  **Web Server:**
    *   **Description:** Hosts and executes the PHP application.
    *   **Technology:** Apache/Nginx.
    *   This component will contain the "PHP Application" component.

3.  **PHP Application (contained within the Web Server):**
    *   **Description:** The core of the sports league management system.
    *   This component should be broken down into smaller sub-components:
        *   **User & Session Management:** Handles user login, logout, registration, and session management. (e.g., `login.php`, `logout.php`, `users.php`)
        *   **League & Team Management:** Manages the creation, editing, and deletion of leagues, teams, and players. (e.g., `create_league.php`, `edit_team.php`, `assign_player.php`)
        *   **Game Engine (Real-time):** The core of the live game functionality. Handles real-time updates for scores, stats, and game logs. (e.g., `manage_game.php`, `get_game_state.php`, `update_stat.php`)
        *   **Tournament Logic:** Contains the logic for different tournament formats like Round Robin and Elimination. (e.g., `round_robin.php`, `single_elimination.php`)
        *   **Spectator Views:** Generates public-facing views like the scoreboard and spectator pages. (e.g., `scoreboard.php`, `spectator_view.php`)

4.  **MySQL Database:**
    *   **Description:** The central data store for the application.
    *   **Technology:** MySQL.
    *   Contains all league, team, player, user, and game data.

5.  **QR Code Generation Library (External):**
    *   **Description:** An external library used to generate QR codes.
    *   **Technology:** `endroid/qr-code` (PHP library).

**Connections (Arrows):**

*   An arrow from **Web Browser** to **Web Server** labeled "HTTP/S Requests".
*   An arrow from **PHP Application** to **MySQL Database** labeled "Queries/Reads/Writes data using PDO".
*   An arrow from the **Game Engine** sub-component to the **Web Browser** labeled "Sends real-time game state (JSON)".
*   An arrow from the **Web Browser** to the **Game Engine** sub-component labeled "Sends game actions (e.g., update score, log event)".
*   An arrow from the **Spectator Views** sub-component to the **QR Code Generation Library** labeled "Generates QR codes for URLs".

This diagram will provide a clear and concise visual summary of the system's architecture, making it an excellent addition to your documentation.
