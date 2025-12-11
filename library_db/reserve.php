<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['id']) || !in_array($_SESSION['role'], ['Student', 'Teacher'])) {
    header("Location: login.php");
    exit;
}

include 'db_connect.php';
$user_id = $_SESSION['id'];

/**
 * Handle reservation action: ?reserve=Book_ID
 * - Only for books not 'Available'
 * - Prevent duplicate reservations (pending/approved)
 * - Prevent conflicts with active loans (borrower already holds the book)
 */
if (isset($_GET['reserve'])) {
    $book_id = intval($_GET['reserve']);

    // Check book status (must not be 'Available')
    $checkBook = $conn->prepare("SELECT COALESCE(Status, 'Available') AS Status FROM books WHERE Book_ID = ?");
    $checkBook->bind_param("i", $book_id);
    $checkBook->execute();
    $book = $checkBook->get_result()->fetch_assoc();

    if (!$book || $book['Status'] === 'Available') {
        echo "<p style='text-align:center;color:#b00020;font-weight:600;'>This book is available. Please borrow directly.</p>
              <p style='text-align:center;'><a href='borrow.php'>Go to Borrow</a></p>";
        exit;
    }

    // Prevent duplicate reservations (pending/approved)
    $checkRes = $conn->prepare("
        SELECT Reservation_ID
        FROM reservations
        WHERE User_ID = ? AND Book_ID = ? AND Status IN ('pending','approved')
        LIMIT 1
    ");
    $checkRes->bind_param("ii", $user_id, $book_id);
    $checkRes->execute();
    $existingRes = $checkRes->get_result()->fetch_assoc();

    // Prevent reserving a book the user already holds (active loan)
    $checkLoan = $conn->prepare("
        SELECT Loan_ID
        FROM loans
        WHERE Borrower_User_ID = ? AND Book_ID = ? AND Status = 'active'
        LIMIT 1
    ");
    $checkLoan->bind_param("ii", $user_id, $book_id);
    $checkLoan->execute();
    $existingLoan = $checkLoan->get_result()->fetch_assoc();

    if ($existingRes || $existingLoan) {
        echo "<p style='text-align:center;color:#b00020;font-weight:600;'>You already have this book (loan or reservation).</p>
              <p style='text-align:center;'><a href='reserve.php'>Back to Reserve</a></p>";
        exit;
    }

    // Create reservation (pending)
    $createRes = $conn->prepare("
        INSERT INTO reservations (User_ID, Book_ID, Status, Reserved_At)
        VALUES (?, ?, 'pending', NOW())
    ");
    $createRes->bind_param("ii", $user_id, $book_id);
    $createRes->execute();

    header("Location: reserve.php");
    exit;
}

/**
 * Fetch books eligible for reservation:
 * - Not Available (Unavailable/Missing/Repair/Damaged)
 * - Not already borrowed by this user (active loan)
 * - Not already reserved by this user (pending/approved)
 */
$reserveStmt = $conn->prepare("
    SELECT b.Book_ID, b.Title, b.Author, COALESCE(b.Status, 'Available') AS Status
    FROM books b
    WHERE COALESCE(b.Status, 'Available') <> 'Available'
      AND b.Book_ID NOT IN (
          SELECT l.Book_ID
          FROM loans l
          WHERE l.Borrower_User_ID = ? AND l.Status = 'active'
      )
      AND b.Book_ID NOT IN (
          SELECT r.Book_ID
          FROM reservations r
          WHERE r.User_ID = ? AND r.Status IN ('pending','approved')
      )
    ORDER BY b.Title
");
$reserveStmt->bind_param("ii", $user_id, $user_id);
$reserveStmt->execute();
$result = $reserveStmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reserve a book</title>
    <style>
        table { border-collapse: collapse; width: 80%; margin: 20px auto; }
        th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
        th { background-color: #f2f2f2; }
        h2, p { text-align: center; }
        .note { color:#555; }
    </style>
</head>
<body>
    <h2>Unavailable books (reservable)</h2>
    <p class="note">You can reserve books that are not currently available. Staff will review and approve reservations.</p>
    <table>
        <tr>
            <th>Title</th>
            <th>Author</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['Title']); ?></td>
                <td><?php echo htmlspecialchars($row['Author']); ?></td>
                <td><?php echo htmlspecialchars($row['Status']); ?></td>
                <td>
                    <a href="reserve.php?reserve=<?php echo (int)$row['Book_ID']; ?>"
                       onclick="return confirm('Reserve this book?');">Reserve</a>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="4">No unavailable books to reserve right now.</td></tr>
        <?php endif; ?>
    </table>

    <p><a href="dashboard_<?php echo strtolower($_SESSION['role']); ?>.php">Back to dashboard</a></p>
</body>
</html>
