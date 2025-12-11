<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['id']) || $_SESSION['role'] !== 'Teacher') {
    header("Location: login.php");
    exit;
}

include 'db_connect.php';

/**
 * Return action: ?return=Loan_ID
 * Verifies ownership (Borrower_User_ID) and active status before updating.
 */
if (isset($_GET['return'])) {
    $loan_id = intval($_GET['return']);

    // Verify the loan belongs to this teacher and is active
    $verify = $conn->prepare("
        SELECT Loan_ID, Book_ID
        FROM loans
        WHERE Loan_ID = ? AND Borrower_User_ID = ? AND Status = 'active'
        LIMIT 1
    ");
    $verify->bind_param("ii", $loan_id, $_SESSION['id']);
    $verify->execute();
    $loan = $verify->get_result()->fetch_assoc();

    if ($loan) {
        // Mark loan returned and set Date_Returned
        $updateLoan = $conn->prepare("
            UPDATE loans
            SET Status = 'returned', Date_Returned = NOW()
            WHERE Loan_ID = ?
        ");
        $updateLoan->bind_param("i", $loan_id);
        $updateLoan->execute();

        // Reset book status to Available
        $updateBook = $conn->prepare("UPDATE books SET Status = 'Available' WHERE Book_ID = ?");
        $updateBook->bind_param("i", $loan['Book_ID']);
        $updateBook->execute();
    }

    header("Location: dashboard_teacher.php");
    exit;
}

/**
 * Reserve action: ?reserve=Book_ID
 * Prevents duplicate reservations and conflicts with active loans.
 */
if (isset($_GET['reserve'])) {
    $book_id = intval($_GET['reserve']);

    // Already has a pending/approved reservation for this book?
    $checkRes = $conn->prepare("
        SELECT Reservation_ID
        FROM reservations
        WHERE User_ID = ? AND Book_ID = ? AND Status IN ('pending','approved')
        LIMIT 1
    ");
    $checkRes->bind_param("ii", $_SESSION['id'], $book_id);
    $checkRes->execute();
    $existingRes = $checkRes->get_result()->fetch_assoc();

    // Already holds an active loan for this book?
    $checkLoan = $conn->prepare("
        SELECT Loan_ID
        FROM loans
        WHERE Borrower_User_ID = ? AND Book_ID = ? AND Status = 'active'
        LIMIT 1
    ");
    $checkLoan->bind_param("ii", $_SESSION['id'], $book_id);
    $checkLoan->execute();
    $existingLoan = $checkLoan->get_result()->fetch_assoc();

    if (!$existingRes && !$existingLoan) {
        // Create a new reservation (pending)
        $createRes = $conn->prepare("
            INSERT INTO reservations (User_ID, Book_ID, Status, Reserved_At)
            VALUES (?, ?, 'pending', NOW())
        ");
        $createRes->bind_param("ii", $_SESSION['id'], $book_id);
        $createRes->execute();
    }

    header("Location: dashboard_teacher.php");
    exit;
}

// Active loans for this teacher
$loansStmt = $conn->prepare("
    SELECT l.Loan_ID, b.Title, b.Author, l.Date_Borrowed, l.Due_Date
    FROM loans l
    JOIN books b ON l.Book_ID = b.Book_ID
    WHERE l.Borrower_User_ID = ? AND l.Status = 'active'
    ORDER BY l.Date_Borrowed DESC
");
$loansStmt->bind_param("i", $_SESSION['id']);
$loansStmt->execute();
$loans = $loansStmt->get_result();

// Books eligible for reservation (not Available)
$reserveBooks = $conn->query("
    SELECT Book_ID, Title, Author, Status
    FROM books
    WHERE Status <> 'Available'
    ORDER BY Title
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - BookHive</title>
<style>
    /* New Ocean Color Palette based on the image */
    :root {
        --col-patten-blue: #C6DBEA; /* Lightest Blue (almost white/foam) */
        --col-french-pass: #A2C8DF; /* Light Blue */
        --col-seagull: #68B4D1;     /* Medium Light Blue/Teal */
        --col-curious-blue: #3EBB83; /* Medium Dark Teal/Greenish Blue */
        --col-venice-blue: #2A5677; /* Dark Blue (Primary BG/Accent) */

        /* Assignments to Theme Variables */
        --col-bg-main: var(--col-venice-blue); /* Dark Blue BG */
        --col-bg-card: #2f6a94; /* Slightly lighter shade for card backgrounds (derived from Venice Blue) */
        --col-text-cream: var(--col-patten-blue); /* Text Color (Lightest Blue) */
        --col-text-muted: var(--col-french-pass); /* Muted Text Color */
        --col-accent-light: var(--col-seagull); /* Light Accent/Border */
        --col-accent-dark: #1f4360; /* Darker Accent/Separator */

        --col-danger: #e74c3c;
        --col-success: #27ae60;
    }

    body {
        margin: 0;
        font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        background-color: var(--col-bg-main);
        color: var(--col-text-cream);
        line-height: 1.6;
    }

    /* Layout & Containers */
    .container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 20px;
    }

    /* Header Nav */
    .main-header {
        background-color: var(--col-bg-card);
        padding: 1rem 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--col-accent-light);
    }

    .brand h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .user-info {
        color: var(--col-text-muted);
        font-size: 0.9rem;
    }

    .nav-links a {
        text-decoration: none;
        color: var(--col-text-cream);
        margin-left: 20px;
        padding: 8px 12px;
        border-radius: 4px;
        transition: background-color 0.2s;
        font-weight: 500;
    }

    .nav-links a:hover {
        background-color: var(--col-accent-dark); /* Darker shade for hover */
    }

    .nav-links .btn-primary {
        background-color: var(--col-patten-blue); /* Lightest Blue for contrast button */
        color: var(--col-bg-main); /* Dark text on light button */
        font-weight: 700;
    }
    .nav-links .btn-primary:hover {
        background-color: #e0f0f8; /* Even lighter hover */
    }

    /* Content Sections */
    .dashboard-section {
        background-color: var(--col-bg-card);
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 30px;
        border: 1px solid var(--col-accent-light);
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .section-title {
        margin-top: 0;
        margin-bottom: 1.5rem;
        font-size: 1.3rem;
        border-bottom: 2px solid var(--col-accent-light);
        padding-bottom: 10px;
        display: inline-block;
    }

    .note {
        color: var(--col-text-muted);
        font-size: 0.95rem;
        margin-bottom: 20px;
    }

    /* Modern Tables */
    .table-container {
        overflow-x: auto;
    }

    .styled-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
        font-size: 0.95rem;
    }

    .styled-table thead tr {
        background-color: var(--col-accent-dark);
        text-align: left;
    }

    .styled-table th,
    .styled-table td {
        padding: 15px 12px;
    }

    .styled-table th {
        color: var(--col-text-muted);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 1px;
    }

    .styled-table tbody tr {
        border-bottom: 1px solid var(--col-accent-light);
        transition: background-color 0.2s;
    }

    .styled-table tbody tr:hover {
        background-color: rgba(200, 220, 240, 0.1); /* Light hover effect */
    }
    
    .styled-table tbody tr:last-of-type {
        border-bottom: none;
    }

    /* Status Badges & Buttons */
    .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-overdue {
        background-color: rgba(231, 76, 60, 0.2);
        color: var(--col-danger);
    }

    .btn-action {
        display: inline-block;
        padding: 6px 12px;
        text-decoration: none;
        border-radius: 4px;
        font-size: 0.9rem;
        font-weight: 500;
        transition: all 0.2s;
        cursor: pointer;
        border: 1px solid var(--col-seagull); /* Teal border */
        color: var(--col-seagull);
    }

    .btn-action:hover {
        background-color: var(--col-seagull);
        color: var(--col-bg-card);
    }
    
    .empty-state {
        text-align: center;
        padding: 30px;
        color: var(--col-text-muted);
        font-style: italic;
    }

    /* Toasts Refined */
    .toast {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        bottom: 24px;
        background: rgba(42, 86, 119, 0.95); /* Semi-transparent Dark Blue */
        color: var(--col-text-cream);
        padding: 12px 20px;
        border-radius: 6px;
        opacity: 0;
        transition: opacity .3s ease, transform 0.3s ease;
        z-index: 9999;
        font-size: 14px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        border: 1px solid var(--col-seagull);
        pointer-events: none;
    }
    .toast.show {
        opacity: 1;
        transform: translateX(-50%) translateY(-10px);
        pointer-events: auto;
    }
    /* Borders to indicate status */
    .toast.success { border-left: 4px solid var(--col-success); }
    .toast.error { border-left: 4px solid var(--col-danger); }
    .toast.info { border-left: 4px solid var(--col-text-cream); } /* Using light text color for info */

    /* Responsive Adjustments */
    @media (max-width: 768px) {
        .main-header {
            flex-direction: column;
            text-align: center;
        }
        .brand { margin-bottom: 15px; }
        .nav-links { margin-top: 15px; }
        .styled-table th, .styled-table td { padding: 10px 8px; }
    }
</style>
</head>
<body>

    <header class="main-header">
        <div class="brand">
            <h2>BookHive Portal</h2>
            <span class="user-info">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Student'); ?></span>
        </div>
        <nav class="nav-links">
            <a href="borrow.php" class="btn-primary">Borrow a Book</a>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <main class="container">
        
        <section class="dashboard-section">
            <h3 class="section-title">My Borrowed Books</h3>
            
            <div class="table-container">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th style="width: 30%">Title</th>
                            <th style="width: 20%">Author</th>
                            <th style="width: 15%">Borrowed Date</th>
                            <th style="width: 20%">Due Date</th>
                            <th style="width: 15%">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($loans && $loans->num_rows > 0): ?>
                            <?php while ($row = $loans->fetch_assoc()):
                                $isOverdue = (strtotime($row['Due_Date']) < time());
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['Title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['Author']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($row['Date_Borrowed'])); ?></td>
                                <td>
                                    <?php if ($isOverdue): ?>
                                        <span class="status-badge status-overdue">Overdue</span><br>
                                    <?php endif; ?>
                                    <?php echo date('M d, Y', strtotime($row['Due_Date'])); ?>
                                </td>
                                <td>
                                    <a href="dashboard_student.php?return=<?php echo (int)$row['Loan_ID']; ?>"
                                       class="btn-action"
                                       onclick="return confirm('Are you sure you want to return this book?')">Return</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="empty-state">You currently have no active borrowed books.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-section">
            <h3 class="section-title">Reserve a Book</h3>
            <p class="note">The following books are currently unavailable. You may place a reservation, and staff will approve it when a copy becomes available.</p>
            
            <div class="table-container">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th style="width: 40%">Title</th>
                            <th style="width: 30%">Author</th>
                            <th style="width: 15%">Current Status</th>
                            <th style="width: 15%">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reserveBooks && $reserveBooks->num_rows > 0): ?>
                            <?php while ($b = $reserveBooks->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($b['Title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($b['Author']); ?></td>
                                <td style="color: var(--col-text-muted); font-style: italic;"><?php echo htmlspecialchars($b['Status']); ?></td>
                                <td>
                                    <a href="dashboard_student.php?reserve=<?php echo (int)$b['Book_ID']; ?>"
                                       class="btn-action"
                                       onclick="return confirm('Place a reservation for this book?')">Reserve</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="empty-state">There are no unavailable books to reserve right now.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <div id="toast" class="toast"></div>

    <script>
      function showToast(msg, type='info', ms=3000){
        const t = document.getElementById('toast');
        // Reset classes first
        t.className = 'toast';
        // Add specific type class for border color
        t.classList.add(type);
        t.textContent = msg;
        
        // Trigger reflow to restart animation if needed
        void t.offsetWidth; 
        
        t.classList.add('show');
        setTimeout(()=> t.classList.remove('show'), ms);
      }

      <?php if ($toast): ?>
        // Add a slight delay so the UI loads before the toast pops up
        setTimeout(() => {
             showToast(<?php echo json_encode($toast[0]); ?>, <?php echo json_encode($toast[1]); ?>);
        }, 300);
      <?php endif; ?>
    </script>
</body>
</html>