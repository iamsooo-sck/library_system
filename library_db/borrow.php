<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id']) || !in_array($_SESSION['role'], ['Student','Teacher'])) {
    header("Location: login.php");
    exit;
}

include 'db_connect.php';

// Handle borrow action
if (isset($_GET['book_id'])) {
    $book_id = intval($_GET['book_id']);
    $user_id = $_SESSION['id'];
    $role    = $_SESSION['role'];

    // Enforce 3-book limit for students only
    if ($role === 'Student') {
        $check = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM loans
            WHERE Borrower_User_ID = ? AND Status = 'active'
        ");
        $check->bind_param("i", $user_id);
        $check->execute();
        $count = $check->get_result()->fetch_assoc()['cnt'];

        if ($count >= 3) {
            echo "<p style='text-align:center;color:#c0392b;font-weight:bold;'>
                    Borrow limit reached (3 books per semester).
                  </p>
                  <p style='text-align:center;'>
                    <a href='dashboard_student.php' style='color:#201408; text-decoration: none; padding: 10px 15px; border: 1px solid #201408; border-radius: 4px;'>Back to Dashboard</a>
                  </p>";
            exit;
        }
    }

    // Insert new loan
    $stmt = $conn->prepare("
        INSERT INTO loans (Book_ID, Borrower_User_ID, Processed_By_User_ID, Date_Borrowed, Due_Date, Status)
        VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), 'active')
    ");
    $stmt->bind_param("iii", $book_id, $user_id, $user_id);
    $stmt->execute();

    // Mark book as unavailable — FIXED to ENUM value
    $upd = $conn->prepare("UPDATE books SET Status = 'archived' WHERE Book_ID = ?");
    $upd->bind_param("i", $book_id);
    $upd->execute();

    $_SESSION['flash_success'] = "Book successfully borrowed! It is due in 14 days.";
    header("Location: borrow.php");
    exit;
}

// Fetch available books — FIXED: use 'active'
$result = $conn->query("
    SELECT Book_ID, Title, Author, Price, Status
    FROM books
    WHERE Status = 'active'
    ORDER BY Title
");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Borrow Books — Blue Theme</title>
  <style>
    :root{
      --bg:#eaf6ff;
      --card:#ffffff;
      --primary:#0b67ff; /* strong blue */
      --primary-600:#085ad9;
      --accent:#7fb3ff;
      --muted:#6b7a89;
      --success:#1aa36c;
      --danger:#e74c3c;
      --shadow: 0 6px 18px rgba(11,103,255,0.08);
      --radius:12px;
      --maxw:1100px;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    }

    /* Page layout */
    body{background:linear-gradient(180deg,var(--bg),#f6fbff);margin:0;color:#102030}
    .wrap{max-width:var(--maxw);margin:36px auto;padding:28px}
    header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
    .brand{display:flex;gap:14px;align-items:center}
    .logo{width:56px;height:56px;border-radius:10px;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;box-shadow:var(--shadow)}
    h1{font-size:20px;margin:0}
    p.lead{margin:0;color:var(--muted)}

    /* navigation */
    nav .btn{background:transparent;border:1px solid rgba(11,103,255,0.12);padding:8px 12px;border-radius:8px;color:var(--primary);text-decoration:none;font-weight:600}

    /* flash */
    .flash{padding:12px 16px;border-radius:10px;margin:18px 0;display:flex;align-items:center;gap:12px;background:linear-gradient(90deg,#e6f1ff,#ffffff);border:1px solid rgba(11,103,255,0.08)}
    .flash.success{border-left:6px solid var(--primary-600)}

    /* grid */
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px}
    .card{background:var(--card);padding:16px;border-radius:12px;box-shadow:var(--shadow);border:1px solid rgba(16,32,48,0.04);display:flex;flex-direction:column;justify-content:space-between}
    .meta{font-size:13px;color:var(--muted);margin-top:8px}
    .title{font-weight:700;margin:6px 0}

    .price{font-weight:700;color:var(--primary-600)}
    .row{display:flex;align-items:center;gap:8px}
    .btn-primary{background:var(--primary);color:#fff;padding:8px 12px;border-radius:8px;border:none;font-weight:700;text-decoration:none;display:inline-block}
    .btn-outline{background:transparent;border:1px solid rgba(11,103,255,0.12);padding:8px 12px;border-radius:8px;color:var(--primary);text-decoration:none;font-weight:700}

    footer{margin-top:28px;text-align:center;color:var(--muted)}

    /* responsive tweaks */
    @media (max-width:520px){.brand h1{font-size:16px}.wrap{padding:16px}}

  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div class="brand">
        <div class="logo">LB</div>
        <div>
          <h1>Library Borrow — Student/Teacher</h1>
          <p class="lead">Select a book and click <strong>Borrow</strong>. Students limited to 3 active loans.</p>
        </div>
      </div>
      <nav>
        <a class="btn" href="dashboard_student.php">Back to Dashboard</a>
      </nav>
    </header>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="flash success">
        <strong>Success</strong>
        <div style="flex:1"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
        <a href="borrow.php" class="btn-outline">OK</a>
      </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="flash" style="border-left:6px solid var(--danger);background:linear-gradient(90deg,#fff0f0,#fff)">
        <strong style="color:var(--danger)">Error</strong>
        <div style="flex:1"><?php echo htmlspecialchars($error); ?></div>
        <a href="borrow.php" class="btn-outline">Close</a>
      </div>
    <?php endif; ?>

    <section>
      <h2 style="margin:0 0 12px">Available Books</h2>
      <div class="grid">
        <?php while ($row = $result->fetch_assoc()): ?>
          <article class="card">
            <div>
              <div class="title"><?php echo htmlspecialchars($row['Title']); ?></div>
              <div class="meta">By <?php echo htmlspecialchars($row['Author'] ?? 'Unknown'); ?></div>
              <div class="meta">Status: <?php echo htmlspecialchars($row['Status']); ?></div>
            </div>

            <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
              <div>
                <div class="price">PHP <?php echo number_format($row['Price'],2); ?></div>
              </div>
              <div class="row">
                <a class="btn-outline" href="book_details.php?book_id=<?php echo (int)$row['Book_ID']; ?>">Details</a>
                <a class="btn-primary" href="?book_id=<?php echo (int)$row['Book_ID']; ?>" onclick="return confirm('Borrow this book? It will be due in 14 days.');">Borrow</a>
              </div>
            </div>
          </article>
        <?php endwhile; ?>
      </div>

      <?php if ($result->num_rows === 0): ?>
        <p style="text-align:center;color:var(--muted);margin-top:18px">No books currently available.</p>
      <?php endif; ?>
    </section>

</body>
</html>
