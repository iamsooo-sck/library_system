# Library Management System

## Overview
The **Library Management System** is a web-based application designed to manage library operations efficiently.  
It allows users to browse, borrow, and return books, while librarians manage books, users, and reservations.  
Supports multiple roles: **Student, Teacher, Staff, Librarian**.

---

## Features

### User Roles
- **Student / Teacher**
  - Browse available books
  - Borrow and return books
  - View borrowed books and due dates

- **Librarian**
  - Manage book inventory (add, update, delete, archive)
  - Manage user accounts
  - Track borrowed books and pending reservations
  - Approve or decline reservation requests

- **Staff**
  - Assist in managing library operations
  - Handle book reservations and issue approvals

### Functionalities
- Book search and filtering
- Borrowing and returning books
- Reservation management
- Role-based access control
- Dashboard with key statistics
- User authentication and session management

---

## Tech Stack
- **Frontend:** HTML, CSS
- **Backend:** PHP
- **Database:** MySQL / MariaDB
- **Server:** Apache (XAMPP)
## Installation

1. Clone the repository:
    ```bash
    git clone https://github.com/iamsooo-sck/library_system
    ```
2. Move the project folder to your web server root directory (e.g., `htdocs` for XAMPP)
3. Create a database in MySQL (e.g., `library_db`)
4. Import the provided `database.sql` into your database
5. Configure database connection in `db_connect.php`:
    ```php
    $host = "localhost";
    $user = "root";
    $pass = "";
    $db   = "library_db";
    $conn = new mysqli($host, $user, $pass, $db);
    ```
6. Open in browser:
    ```
    http://localhost/library_system/
    ```

---

## Usage

1. Register or log in as a user (Student/Teacher) or use librarian credentials
2. Navigate to your dashboard:
   - **Students/Teachers:** Borrow, return, and search books
   - **Librarians:** Manage books, users, and reservations
3. View statistics and pending actions on the dashboard
