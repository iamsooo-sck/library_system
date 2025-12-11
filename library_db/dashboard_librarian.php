<?php
// START: Session and Security Checks
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: login.php"); exit;
}
include 'db_connect.php'; 

/* ---------- Handle book actions ---------- */
// Add book 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book'])) {
    $title  = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $price  = floatval($_POST['price'] ?? 0.00); 
    $status = trim($_POST['status'] ?? 'Available');

    if (!empty($title) && !empty($author) && !empty($status)) {
        $stmt = $conn->prepare("INSERT INTO books (Title, Author, Price, Status) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssds", $title, $author, $price, $status);
            $stmt->execute();
            $_SESSION['flash_success'] = "Book '{$title}' added successfully.";
        }
    }
    header("Location: dashboard_librarian.php"); 
    exit;
}

// Update status 
if (isset($_GET['set_status'], $_GET['book_id'])) {
    $book_id = (int)$_GET['book_id'];
    $new_status = $_GET['set_status'];
    
    $allowed_statuses = ['Available', 'Unavailable', 'Repair', 'Damaged'];
    if (in_array($new_status, $allowed_statuses)) {
        $stmt = $conn->prepare("UPDATE books SET Status = ? WHERE Book_ID = ?");
        $stmt->bind_param("si", $new_status, $book_id);
        $stmt->execute();
    }
    header("Location: dashboard_librarian.php"); 
    exit;
}

// Delete book (Robust check for ALL associated records in loans/reservations)
if (isset($_GET['delete'], $_GET['book_id'])) {
    $book_id = (int)$_GET['book_id'];
    
    $loanCheck = $conn->prepare("SELECT 1 FROM loans WHERE Book_ID = ? LIMIT 1");
    $loanCheck->bind_param("i", $book_id);
    $loanCheck->execute();
    $hasLoan = $loanCheck->get_result()->fetch_assoc();

    $resCheck = $conn->prepare("SELECT 1 FROM reservations WHERE Book_ID = ? LIMIT 1");
    $resCheck->bind_param("i", $book_id);
    $resCheck->execute();
    $hasReservation = $resCheck->get_result()->fetch_assoc();

    if (!empty($hasLoan) || !empty($hasReservation)) {
        $_SESSION['flash_error'] = "Deletion failed. Book ID #{$book_id} is referenced by existing loan or reservation records. Check the database constraints.";
    } else {
        $del = $conn->prepare("DELETE FROM books WHERE Book_ID = ?");
        $del->bind_param("i", $book_id);
        $del->execute();
        $_SESSION['flash_success'] = "Book ID #{$book_id} successfully deleted from inventory.";
    }
    header("Location: dashboard_librarian.php"); 
    exit;
}

/* ---------- Filters & queries (Unchanged) ---------- */
// Book search
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $booksStmt = $conn->prepare("
        SELECT Book_ID, Title, Author, Price, Status
        FROM books
        WHERE Title LIKE CONCAT('%', ?, '%') OR Author LIKE CONCAT('%', ?, '%')
        ORDER BY Title
    ");
    $booksStmt->bind_param("ss", $q, $q);
} else {
    $booksStmt = $conn->prepare("SELECT Book_ID, Title, Author, Price, Status FROM books ORDER BY Title");
}
$booksStmt->execute();
$books = $booksStmt->get_result();

// Reservation status filter
$allowedStatuses = ['pending','approved','fulfilled','canceled'];
$statusFilter = (isset($_GET['status']) && in_array($_GET['status'], $allowedStatuses)) ? $_GET['status'] : null;

if ($statusFilter) {
    $resStmt = $conn->prepare("
        SELECT r.Reservation_ID, b.Title, b.Author, u.Name AS UserName,
               COALESCE(NULLIF(TRIM(r.Status), ''), 'pending') AS ReservationStatus,
               r.Reserved_At
        FROM reservations r
        JOIN books b ON r.Book_ID = b.Book_ID
        JOIN users u ON r.User_ID = u.User_ID
        WHERE r.Status = ?
        ORDER BY r.Reserved_At DESC
    ");
    $resStmt->bind_param("s", $statusFilter);
} else {
    $resStmt = $conn->prepare("
        SELECT r.Reservation_ID, b.Title, b.Author, u.Name AS UserName,
               COALESCE(NULLIF(TRIM(r.Status), ''), 'pending') AS ReservationStatus,
               r.Reserved_At
        FROM reservations r
        JOIN books b ON r.Book_ID = b.Book_ID
        JOIN users u ON r.User_ID = u.User_ID
        ORDER BY r.Reserved_At DESC
    ");
}
$resStmt->execute();
$reservations = $resStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarian Dashboard</title>
    <style>
        /* Blue Theme Palette */
        :root {
            --col-bg-main: #f0f4f8;         /* Light blue background */
            --col-bg-card: #ffffff;         /* White card background */
            --col-text-primary: #1a202c;    /* Dark navy text */
            --col-text-secondary: #2b6cb0;  /* Medium blue for headers and accents */
            --col-text-muted: #4a5568;      /* Muted gray-blue text */
            --col-border: #cbd5e0;          /* Soft border */
            --col-danger: #e53e3e;          /* Red for errors */
            --col-success: #2f855a;         /* Green for success */
            --col-header-bg: #2b6cb0;       /* Medium blue header */
            --col-header-text: #f0f4f8;     /* Light cream text */
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--col-bg-main);
            color: var(--col-text-primary);
            line-height: 1.6;
        }

        /* Header */
        .main-header {
            background-color: var(--col-header-bg);
            padding: 1rem 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--col-header-text);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .brand h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .nav-links {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-info {
            font-size: 1.1rem;
            font-weight: 500;
        }
        .nav-links a {
            text-decoration: none;
            color: var(--col-header-text);
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 500;
            border: 2px solid var(--col-header-text);
            transition: background-color 0.2s;
        }
        .nav-links a:hover {
            background-color: rgba(240, 244, 248, 0.2);
        }

        /* Layout */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .two-column-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
            margin-top: 20px;
        }

        /* Card Style */
        .dashboard-section {
            background-color: var(--col-bg-card);
            border: 1px solid var(--col-border);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 15px rgba(43,108,176,0.1);
        }
        .section-title {
            color: var(--col-text-secondary);
            font-size: 1.5rem;
            margin-top: 0;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e2e8f0;
        }

        /* Tables */
        .styled-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 5px;
            font-size: 0.95rem;
        }
        .styled-table thead th {
            color: var(--col-text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            padding: 10px 15px;
            border-bottom: 2px solid var(--col-border);
            background-color: transparent;
            text-align: left;
        }
        .styled-table tbody td {
            padding: 12px 15px;
            border: none;
            border-bottom: 1px solid #e0e0e0;
        }
        .styled-table tbody tr:hover {
            background-color: #ebf4ff; /* light blue hover */
        }
        .empty-state {
            text-align: center;
            padding: 20px;
            color: var(--col-text-muted);
            font-style: italic;
        }

        /* Filters and Forms */
        .filters {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .filters .inline {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        input[type="text"], input[type="number"], select {
            padding: 10px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            font-size: 1rem;
            color: var(--col-text-primary);
        }
        label {
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
        }

        /* Buttons */
        button, .btn-link {
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-block;
            border: none;
            transition: all 0.2s ease-in-out;
        }
        button[type="submit"] {
            background-color: var(--col-text-secondary);
            color: var(--col-header-text);
        }
        button[type="submit"]:hover {
            background-color: #1e4e8c;
        }
        .btn-clear {
            background: none;
            color: var(--col-text-secondary);
            border: 1px solid var(--col-text-secondary);
        }
        .btn-clear:hover {
            background-color: var(--col-text-secondary);
            color: var(--col-header-text);
        }

        /* Action Links and Status Badges */
        .actions a {
            color: var(--col-text-secondary);
            text-decoration: none;
            margin-right: 12px;
            font-size: 0.85rem;
        }
        .actions a:hover {
            text-decoration: underline;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-pending { background-color: #bee3f8; color: #2b6cb0; }
        .status-approved { background-color: #90cdf4; color: #2c5282; }
        .status-fulfilled { background-color: #63b3ed; color: #1a365d; }
        .status-canceled { background-color: #fbb6ce; color: #822659; }
        .status-available { background-color: #c6f6d5; color: #276749; }
        .status-unavailable, .status-repair, .status-damaged { background-color: #fed7d7; color: #9b2c2c; }

        /* Add Book Form */
        .form-group {
            margin-bottom: 15px;
        }
        .add-book-form-container .form-group input, 
        .add-book-form-container .form-group select {
            width: 100%; 
            max-width: 300px;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .form-submit-center {
            text-align: left;
            margin-top: 20px;
        }

        /* Flash Messages */
        .flash-message {
            padding: 10px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            text-align: center;
            font-weight: 600;
        }
        .flash-success {
            background-color: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        .flash-error {
            background-color: #fed7d7;
            color: #9b2c2c;
            border: 1px solid #fbb6ce;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .two-column-layout {
                grid-template-columns: 1fr;
            }
            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="brand">
        <h2>Librarian Dashboard</h2>
    </div>
    <nav class="nav-links">
        <span class="user-info">
            <strong> Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Librarian'); ?></strong> (Librarian)
        </span>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main class="container">
    <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="flash-message flash-success">
            <?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="flash-message flash-error">
            <?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
        </div>
    <?php endif; ?>

    <!-- Top Section: Add Book + Inventory side by side -->
    <div class="two-column-layout">
        <!-- Add Book Section (left) -->
        <section class="dashboard-section add-book-form-container">
            <h3 class="section-title">Add New Book to Inventory</h3>
            <form method="post" action="dashboard_librarian.php">
                <input type="hidden" name="add_book" value="1">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" name="title" id="title" required>
                </div>
                <div class="form-group">
                    <label for="author">Author</label>
                    <input type="text" name="author" id="author" required>
                </div>
                <div class="form-group">
                    <label for="price">Price (₱)</label>
                    <input type="number" step="0.01" name="price" id="price" required value="0.00">
                </div>
                <div class="form-group">
                    <label for="status">Initial Status</label>
                    <select name="status" id="status">
                        <option value="Available">Available</option>
                        <option value="Unavailable">Unavailable</option>
                        <option value="Repair">Repair</option>
                        <option value="Damaged">Damaged</option>
                    </select>
                </div>
                <div class="form-submit-center">
                    <button type="submit">Add Book to Inventory</button>
                </div>
            </form>
        </section>

        <!-- Book Inventory Management (right) -->
        <section class="dashboard-section">
            <h3 class="section-title">Book Inventory Management</h3>
            <div class="filters">
                <form method="get" class="inline">
                    <label for="q-search">Search:</label>
                    <input type="text" name="q" id="q-search" placeholder="Title or Author" value="<?php echo htmlspecialchars($q); ?>">
                    <button type="submit">Search</button>
                    <?php if ($q !== ''): ?>
                        <a href="dashboard_librarian.php" class="btn-link btn-clear">Clear Search</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="table-container">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">ID</th>
                            <th style="width: 25%">Title</th>
                            <th style="width: 15%">Author</th>
                            <th style="width: 10%">Price (₱)</th>
                            <th style="width: 15%">Status</th>
                            <th style="width: 30%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($books && $books->num_rows > 0): ?>
                            <?php while ($b = $books->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo (int)$b['Book_ID']; ?></td>
                                <td><strong><?php echo htmlspecialchars($b['Title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($b['Author']); ?></td>
                                <td><?php echo number_format((float)$b['Price'], 2); ?></td>
                                <td>
                                    <?php 
                                        $bookStatus = strtolower($b['Status']);
                                        $statusClass = str_replace(' ', '-', $bookStatus);
                                    ?>
                                    <span class="status-badge status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($b['Status']); ?></span>
                                </td>
                                <td class="actions">
                                    <a href="dashboard_librarian.php?set_status=Available&book_id=<?php echo (int)$b['Book_ID']; ?>">Available</a> |
                                    <a href="dashboard_librarian.php?set_status=Unavailable&book_id=<?php echo (int)$b['Book_ID']; ?>">Unavailable</a> |
                                    <a href="dashboard_librarian.php?set_status=Repair&book_id=<?php echo (int)$b['Book_ID']; ?>">Repair</a> |
                                    <a href="dashboard_librarian.php?delete=1&book_id=<?php echo (int)$b['Book_ID']; ?>"
                                       style="color: var(--col-danger);"
                                       onclick="return confirm('WARNING: Delete book? This is only possible if the book has NO related loan or reservation history.');">Delete</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty-state">No books found matching your criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Bottom Section: Reservations Full Width -->
    <section class="dashboard-section" style="margin-top: 20px;">
        <h3 class="section-title">Reservation Requests</h3>
        <div class="filters">
            <form method="get" class="inline" action="dashboard_librarian.php">
                <label for="status-filter">Filter by Status:</label>
                <select name="status" id="status-filter">
                    <option value="">All</option>
                    <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                    <option value="approved" <?php echo $statusFilter==='approved'?'selected':''; ?>>Approved</option>
                    <option value="fulfilled"<?php echo $statusFilter==='fulfilled'?'selected':''; ?>>Fulfilled</option>
                    <option value="canceled" <?php echo $statusFilter==='canceled'?'selected':''; ?>>Canceled</option>
                </select>
                <button type="submit">Apply Filter</button>
                <?php if ($statusFilter): ?>
                    <a href="dashboard_librarian.php" class="btn-link btn-clear">Clear Filter</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-container">
            <table class="styled-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Book Title</th>
                        <th>User</th>
                        <th>Reserved at</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reservations && $reservations->num_rows > 0): ?>
                        <?php while ($r = $reservations->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo (int)$r['Reservation_ID']; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['Title']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['UserName']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($r['Reserved_At'])); ?></td>
                            <td>
                                <?php 
                                    $s = strtolower($r['ReservationStatus']);
                                    $labels = [
                                        'pending' => 'Pending', 'approved' => 'Approved', 'fulfilled' => 'Fulfilled',
                                        'canceled' => 'Canceled', 'waiting' => 'Pending'
                                    ];
                                    $displayStatus = $labels[$s] ?? 'Pending';
                                ?>
                                <span class="status-badge status-<?php echo $s; ?>"><?php echo $displayStatus; ?></span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="empty-state">No reservations found<?php echo $statusFilter ? " for status '{$statusFilter}'" : ""; ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
