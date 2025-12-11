<?php
// START: Revised PHP Logic (Necessary for functionality)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id']) || $_SESSION['role'] !== 'Staff') {
    // If db_connect is included, redirect. Otherwise, proceed with mock data for UI.
    if (file_exists('db_connect.php')) {
        header("Location: login.php");
        exit;
    }
}

// Flash message functions (to improve user feedback)
function set_flash($type, $message) {
    $_SESSION["flash_$type"] = $message;
}

// --- DATABASE CONNECTION CHECK ---
// Note: In a live environment, this include must point to a valid file.
if (file_exists('db_connect.php')) {
    // Attempt to connect. Handle connection errors if necessary later.
    include 'db_connect.php'; 
    $conn_available = true;
} else {
    $conn_available = false;
    // Set session data for UI demo if connection is missing
    if (!isset($_SESSION['name'])) { $_SESSION['name'] = 'Cy Santos'; }
    if (!isset($_SESSION['id'])) { $_SESSION['id'] = 99; }
    set_flash('error', '⚠️ **Database connection missing.** Actions like Approve/Clear will not work in this demo mode.');
}
// ---------------------------------


if ($conn_available && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $action_performed = false; // Flag to check if an action was executed

    /* ---------- Handle reservation actions ---------- */
    if (isset($_GET['approve'])) {
        $res_id = (int)$_GET['approve'];
        $stmt = $conn->prepare("UPDATE reservations SET Status = 'approved' WHERE Reservation_ID = ?");
        $stmt->bind_param("i", $res_id);
        if ($stmt->execute()) {
            set_flash('success', "Reservation ID $res_id approved.");
        } else {
            set_flash('error', "Failed to approve reservation ID $res_id: " . $conn->error);
        }
        $action_performed = true;
    } elseif (isset($_GET['cancel'])) {
        $res_id = (int)$_GET['cancel'];
        $stmt = $conn->prepare("UPDATE reservations SET Status = 'canceled' WHERE Reservation_ID = ?");
        $stmt->bind_param("i", $res_id);
        if ($stmt->execute()) {
            set_flash('success', "Reservation ID $res_id canceled.");
        } else {
            set_flash('error', "Failed to cancel reservation ID $res_id: " . $conn->error);
        }
        $action_performed = true;
    } elseif (isset($_GET['fulfill'])) {
        $res_id = (int)$_GET['fulfill'];
        $stmt = $conn->prepare("UPDATE reservations SET Status = 'fulfilled' WHERE Reservation_ID = ?");
        $stmt->bind_param("i", $res_id);
        if ($stmt->execute()) {
            set_flash('success', "Reservation ID $res_id fulfilled.");
        } else {
            set_flash('error', "Failed to fulfill reservation ID $res_id: " . $conn->error);
        }
        $action_performed = true;
    }

    /* ---------- Handle clearance actions ---------- */
    elseif (isset($_GET['clear_user'])) {
        $user_id = (int)$_GET['clear_user'];
        $loanCheck = $conn->prepare("SELECT 1 FROM loans WHERE Borrower_User_ID = ? AND Status = 'active' LIMIT 1");
        $loanCheck->bind_param("i", $user_id);
        $loanCheck->execute();
        $hasLoan = $loanCheck->get_result()->fetch_assoc();

        $penaltyCheck = $conn->prepare("SELECT 1 FROM penalties WHERE User_ID = ? AND Status = 'pending' LIMIT 1");
        $penaltyCheck->bind_param("i", $user_id);
        $penaltyCheck->execute();
        $hasPenalty = $penaltyCheck->get_result()->fetch_assoc();
        
        $alreadyCleared = $conn->prepare("SELECT 1 FROM clearance_records WHERE User_ID = ? AND Status = 'cleared' LIMIT 1");
        $alreadyCleared->bind_param("i", $user_id);
        $alreadyCleared->execute();
        $isCleared = $alreadyCleared->get_result()->fetch_assoc();

        // only clear if no loans, no penalties, and not already cleared
        if (empty($hasLoan) && empty($hasPenalty) && empty($isCleared)) {
            $stmt = $conn->prepare("
                INSERT INTO clearance_records (User_ID, Cleared_By_User_ID, Cleared_At, Status)
                VALUES (?, ?, NOW(), 'cleared')
            ");
            $stmt->bind_param("ii", $user_id, $_SESSION['id']);
            if ($stmt->execute()) {
                set_flash('success', "Borrower successfully cleared (User ID $user_id).");
            } else {
                 set_flash('error', "Failed to clear user (User ID $user_id): " . $conn->error);
            }
        } else {
            set_flash('error', 'User is not eligible for clearance (active loans or pending penalties).');
        }
        $action_performed = true;
    }

    /* ----------- Handle penalty payment ----------- */
    elseif (isset($_GET['pay_penalty'])) {
        $penalty_id = (int)$_GET['pay_penalty'];
        $stmt = $conn->prepare("UPDATE penalties SET Status = 'paid', Paid_At = NOW() WHERE Penalty_ID = ?");
        $stmt->bind_param("i", $penalty_id);
        if ($stmt->execute()) {
             set_flash('success', "Penalty ID $penalty_id marked as paid.");
        } else {
            set_flash('error', "Failed to mark penalty ID $penalty_id as paid: " . $conn->error);
        }
        $action_performed = true;
    }

    // Redirect after any successful action to prevent form resubmission/URL clutter
    if ($action_performed) {
        // Strip the action GET parameter before redirecting
        $redirect_url = 'dashboard_staff.php';
        if (!empty($_GET['status'])) {
             $redirect_url .= '?status=' . urlencode($_GET['status']);
        }
        header("Location: " . $redirect_url);
        exit;
    }
}


if ($conn_available) {
    /* ---------- Queries ---------- */
    $inventory = $conn->query("SELECT Book_ID, Title, Author, Price, Status FROM books ORDER BY Title");
    $damaged = $conn->query("SELECT Book_ID, Title, Author, Status FROM books WHERE Status IN ('damaged','repair','missing') ORDER BY Title");

    /* Reservation filter toggle */
    $statusFilter = $_GET['status'] ?? '';
    // Allowed filter options remain the same so staff can still view them if they explicitly filter
    $allowed = ['pending','approved','fulfilled','canceled']; 

    $resSql = "
        SELECT r.Reservation_ID, b.Title, b.Author, u.Name AS UserName,
        COALESCE(NULLIF(TRIM(r.Status), ''), 'pending') AS Status,
        r.Reserved_At
        FROM reservations r
        JOIN books b ON r.Book_ID = b.Book_ID
        JOIN users u ON r.User_ID = u.User_ID
    ";

    if (in_array($statusFilter, $allowed)) {
        // If a specific status is requested (via filter), use it
        $resStmt = $conn->prepare($resSql . " WHERE r.Status = ? ORDER BY r.Reserved_At DESC");
        $resStmt->bind_param("s", $statusFilter);
    } else {
        // DEFAULT VIEW: Show only Pending and Approved (excludes fulfilled and canceled as requested)
        $resStmt = $conn->prepare($resSql . " WHERE r.Status IN ('pending', 'approved') ORDER BY r.Reserved_At DESC");
    }
    $resStmt->execute();
    $reservations = $resStmt->get_result();

    $penalties = $conn->query("
        SELECT p.Penalty_ID, u.Name AS UserName, p.Amount, p.Reason, p.Status, p.Issued_At, p.Paid_At
        FROM penalties p
        JOIN users u ON p.User_ID = u.User_ID
        ORDER BY p.Issued_At DESC
    ");
    
    $users = $conn->query("
        SELECT u.User_ID, u.Name,
        (SELECT COUNT(*) FROM loans WHERE Borrower_User_ID = u.User_ID AND Status = 'active') AS ActiveLoans,
        (SELECT COUNT(*) FROM penalties WHERE User_ID = u.User_ID AND Status = 'pending') AS UnpaidPenalties,
        (SELECT COUNT(*) FROM clearance_records WHERE User_ID = u.User_ID AND Status = 'cleared') AS Cleared
        FROM users u
        WHERE u.Role IN ('Student','Teacher')
        ORDER BY u.Name
    ");
    
} else {
    // Mock data for UI preview if DB fails (UI will still load and look correct)
    class MockResult {
        public $num_rows = 0; private $data = []; private $pointer = 0;
        public function __construct($data = []) { $this->data = $data; $this->num_rows = count($data); }
        public function fetch_assoc() { return ($this->pointer < $this->num_rows) ? $this->data[$this->pointer++] : false; }
    }
    // Mock Data adjusted to only show pending/approved by default
    $inventory = new MockResult([['Book_ID'=>1,'Title'=>'The Great Gatsby','Author'=>'F. Scott','Price'=>350.50,'Status'=>'Available'],['Book_ID'=>2,'Title'=>'Moby Dick','Author'=>'H. Melville','Price'=>520.00,'Status'=>'Unavailable']]);
    $damaged = new MockResult([['Book_ID'=>201,'Title'=>'Old Text','Author'=>'Anon','Status'=>'Repair'], ['Book_ID'=>202,'Title'=>'Lost Guide','Author'=>'J. Doe','Status'=>'Missing']]);
    $reservations = new MockResult([['Reservation_ID'=>10,'Title'=>'The Martian','Author'=>'A. Weir','UserName'=>'User A','Status'=>'pending','Reserved_At'=>'2025-12-10 10:30:00'], ['Reservation_ID'=>11,'Title'=>'1984','Author'=>'G. Orwell','UserName'=>'User B','Status'=>'approved','Reserved_At'=>'2025-12-09 08:00:00']]);
    $penalties = new MockResult([['Penalty_ID'=>1,'UserName'=>'User B','Amount'=>50.00,'Reason'=>'Overdue Book','Status'=>'pending','Issued_At'=>'2025-12-01 12:00:00','Paid_At'=>null], ['Penalty_ID'=>2,'UserName'=>'User D','Amount'=>25.00,'Reason'=>'Minor Damage','Status'=>'paid','Issued_At'=>'2025-11-20 09:00:00','Paid_At'=>'2025-11-21 09:00:00']]);
    $users = new MockResult([['User_ID'=>5,'Name'=>'User C','ActiveLoans'=>0,'UnpaidPenalties'=>0,'Cleared'=>0], ['User_ID'=>6,'Name'=>'User E','ActiveLoans'=>1,'UnpaidPenalties'=>0,'Cleared'=>0], ['User_ID'=>7,'Name'=>'User F','ActiveLoans'=>0,'UnpaidPenalties'=>0,'Cleared'=>1]]);
    $statusFilter = '';
    
    // Add dummy records for the filters to show if selected (even in mock mode)
    if ($statusFilter === 'canceled') {
        $reservations = new MockResult([['Reservation_ID'=>12,'Title'=>'Canceled Book','Author'=>'C. Lee','UserName'=>'User C','Status'=>'canceled','Reserved_At'=>'2025-12-08 08:00:00']]);
    } elseif ($statusFilter === 'fulfilled') {
        $reservations = new MockResult([['Reservation_ID'=>13,'Title'=>'Fulfilled Book','Author'=>'F. Dean','UserName'=>'User D','Status'=>'fulfilled','Reserved_At'=>'2025-12-07 08:00:00']]);
    }
}

// END: Revised PHP Logic
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - BookWorm Management</title>
<style>
/* ---------------- Staff Dashboard Theme: Blue Gradient Update ---------------- */
:root {
    /* Blue Gradient Palette Mapping */
    --color-blue-darkest: #000033; /* For text and strongest contrast */
    --color-blue-dark:      #000080; /* For muted text and dark sections */
    --color-blue-mid:      #4169E1; /* Primary brand color */
    --color-blue-light:    #87CEFA; /* Primary light hover/accent */
    --color-blue-pale:      #B0E0E6; /* For subtle borders/lines */
    --color-blue-white:     #F0F8FF; /* Background color */

    /* Thematic Mappings */
    --primary: var(--color-blue-mid);
    --primary-light: var(--color-blue-light);
    --success: #27AE60; /* Kept Green */
    --danger: #E74C3C; /* Kept Red */
    --warning: #F39C12; /* Kept Yellow */
    --bg: var(--color-blue-white);
    --card-bg: #FFFFFF;
    --text-primary: var(--color-blue-darkest);
    --text-muted: var(--color-blue-dark);
    --border: var(--color-blue-pale);
}

body {
    font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg);
    color: var(--text-primary);
    margin:0;
    padding:0;
}

header {
    background-color: var(--primary);
    color:#fff;
    padding:15px 20px;
    display:flex;
    justify-content: space-between;
    align-items: center;
}
header h2 { margin:0; font-size:1.6rem; }
header nav a { color:var(--color-blue-white); text-decoration:none; margin-left:15px; font-weight:500; } /* Set nav links to white */

/* Container */
.container { max-width: 1300px; margin:20px auto; padding:0 15px; }

/* Cards / Sections */
.dashboard-section {
    background: var(--card-bg);
    border-radius:10px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 4px 15px rgba(0,0,0,0.05);
    border:1px solid var(--border);
}
.dashboard-section h3 {
    color: var(--primary);
    font-size:1.3rem;
    margin-bottom:15px;
    border-bottom:2px solid var(--primary-light);
    padding-bottom:6px;
}

/* Flash */
.flash-success { background: var(--success); color:#fff; padding:10px; border-radius:6px; text-align:center; margin-bottom:15px; }
.flash-error { background: var(--danger); color:#fff; padding:10px; border-radius:6px; text-align:center; margin-bottom:15px; }

/* Tables */
.table-container { overflow-x:auto; }
.styled-table {
    width:100%;
    border-collapse:collapse;
    font-size:0.95rem;
}
.styled-table th, .styled-table td { padding:12px 15px; text-align:left; border-bottom:1px solid var(--border); }
.styled-table th { color: var(--text-muted); font-weight:600; text-transform:uppercase; font-size:0.8rem; }
/* Changed hover to use one of the dark blues for better contrast */
.styled-table tbody tr:hover { background-color: var(--primary-light); color:var(--text-primary); transition:0.2s; } 

/* Badges */
.status-badge {
    padding:4px 8px; border-radius:12px; font-weight:600; font-size:0.75rem; text-transform:capitalize; display:inline-block;
}
/* Re-mapped status colors to the new blue palette for consistency, keeping red/green for critical statuses */
.status-pending { background: #E6E6FF; color:var(--primary); } /* Light Lavender-Blue */
.status-approved { background: var(--primary-light); color:var(--text-primary); }
.status-fulfilled, .status-cleared { background: var(--success); color:#fff; } /* Kept green for Success/Fulfilled */
.status-canceled, .status-unavailable, .status-damaged, .status-repair, .status-missing { background:var(--danger); color:#fff; } /* Kept red for Critical */
.status-available { background: var(--color-blue-pale); color: var(--text-muted); }
.status-paid { background:#dfe6e9; color:var(--text-muted); }

/* Action Links */
.action-links a {
    padding:5px 10px; border-radius:6px; text-decoration:none; font-size:0.85rem; border:1px solid var(--primary);
    color: var(--primary); margin-right:8px; transition:0.2s;
}
.action-links a:hover { background: var(--primary); color:#fff; }
.action-links a.btn-success { background: var(--success); color:#fff; border-color: var(--success); }

/* Filter Form */
.filter-form { display:flex; gap:10px; align-items:center; margin-bottom:15px; flex-wrap:wrap; }
.filter-form select, .filter-form button { padding:6px 12px; border-radius:6px; border:1px solid var(--border); font-size:0.9rem; }
.filter-form button { background: var(--primary); color:#fff; border:none; cursor:pointer; }
.filter-form button:hover { background: var(--primary-light); color: var(--text-primary); }

/* Responsive */
@media(max-width:900px){ .dashboard-section{padding:15px;} .filter-form{flex-direction:column; align-items:flex-start;} }
</style>
</head>
<body>

    <header class="main-header">
        <div class="brand">
            <h2>Staff Dashboard</h2>
        </div>
        <nav class="nav-links">
            <span class="user-info"> <strong>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Staff'); ?></strong> (Staff)</span>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <main class="container">
        
        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="flash-success">
                <?php echo $_SESSION['flash_success']; ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
         <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="flash-error">
                <?php echo $_SESSION['flash_error']; ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <section class="dashboard-section">
            <h3 class="section-title">Reservation Requests</h3>
            <form method="get" class="filter-form">
                <label>Filter by status:</label>
                <select name="status">
                    <option value="">Pending & Approved (Default)</option>
                    <option value="pending"   <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                    <option value="approved"  <?php echo $statusFilter==='approved'?'selected':''; ?>>Approved</option>
                    <option value="fulfilled" <?php echo $statusFilter==='fulfilled'?'selected':''; ?>>Fulfilled</option>
                    <option value="canceled"  <?php echo $statusFilter==='canceled'?'selected':''; ?>>Canceled</option>
                </select>
                <button type="submit">Apply</button>
                <?php if ($statusFilter): ?>
                    <a href="dashboard_staff.php" class="btn-clear-filter">Clear Filter</a>
                <?php endif; ?>
            </form>
            <div class="table-container">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">ID</th>
                            <th style="width: 25%">Book</th>
                            <th style="width: 15%">User</th>
                            <th style="width: 15%">Reserved At</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 30%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reservations->num_rows > 0): ?>
                            <?php while ($r = $reservations->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $r['Reservation_ID']; ?></td>
                                <td><strong><?php echo htmlspecialchars($r['Title']); ?></strong> (by <?php echo htmlspecialchars($r['Author']); ?>)</td>
                                <td><?php echo htmlspecialchars($r['UserName']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($r['Reserved_At'])); ?></td>
                                <td>
                                    <?php 
                                        $s = strtolower($r['Status']);
                                        $displayStatus = ucfirst($s);
                                    ?>
                                    <span class="status-badge status-<?php echo $s; ?>"><?php echo $displayStatus; ?></span>
                                </td>
                                <td class="action-links">
                                    <?php if ($r['Status'] === 'pending'): ?>
                                        <a href="dashboard_staff.php?approve=<?php echo (int)$r['Reservation_ID']; ?><?php echo $statusFilter ? "&status=$statusFilter" : ""; ?>" onclick="return confirm('Approve this reservation?');">Approve</a>
                                        <a href="dashboard_staff.php?cancel=<?php echo (int)$r['Reservation_ID']; ?><?php echo $statusFilter ? "&status=$statusFilter" : ""; ?>" onclick="return confirm('Cancel this reservation?');">Cancel</a>
                                    <?php elseif ($r['Status'] === 'approved'): ?>
                                        <a href="dashboard_staff.php?fulfill=<?php echo (int)$r['Reservation_ID']; ?><?php echo $statusFilter ? "&status=$statusFilter" : ""; ?>" onclick="return confirm('Mark this reservation as fulfilled (borrowed)?')" class="btn-success">Fulfill</a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty-state">No reservations found<?php echo $statusFilter ? " for status '{$statusFilter}'" : ""; ?>.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div style="display: flex; gap: 30px;">
            
            <section class="dashboard-section" style="flex: 2;">
                <h3 class="section-title">Penalty Management</h3>
                <div class="table-container">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th style="width: 15%">User</th>
                                <th style="width: 10%">Amount (₱)</th>
                                <th style="width: 30%">Reason</th>
                                <th style="width: 15%">Status</th>
                                <th style="width: 15%">Issued At</th>
                                <th style="width: 15%">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($penalties->num_rows > 0): ?>
                                <?php while ($p = $penalties->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['UserName']); ?></td>
                                    <td><?php echo number_format($p['Amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($p['Reason']); ?></td>
                                    <td>
                                        <?php $s = strtolower($p['Status']); ?>
                                        <span class="status-badge status-<?php echo $s; ?>"><?php echo ucfirst($s); ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($p['Issued_At'])); ?></td>
                                    <td class="action-links">
                                        <?php if ($p['Status'] === 'pending'): ?>
                                            <a href="dashboard_staff.php?pay_penalty=<?php echo (int)$p['Penalty_ID']; ?>"
                                               onclick="return confirm('Mark this penalty as PAID?');"
                                               class="btn-success">Mark Paid</a>
                                        <?php else: ?>
                                            Paid: <?php echo $p['Paid_At'] ? date('M d, Y', strtotime($p['Paid_At'])) : '—'; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="empty-state">No penalties currently recorded.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-section" style="flex: 1;">
                <h3 class="section-title">Clearance Processing</h3>
                
                <div class="table-container">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th style="width: 40%">User</th>
                                <th style="width: 15%">Loans</th>
                                <th style="width: 15%">Penalties</th>
                                <th style="width: 30%">Clearance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users->num_rows > 0): ?>
                                <?php while ($u = $users->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['Name']); ?></td>
                                    <td><?php echo $u['ActiveLoans']; ?></td>
                                    <td><?php echo $u['UnpaidPenalties']; ?></td>
                                    <td class="action-links">
                                        <?php if ($u['Cleared'] > 0): ?>
                                            <span class="status-badge status-cleared">Cleared</span>
                                        <?php elseif ($u['ActiveLoans'] == 0 && $u['UnpaidPenalties'] == 0): ?>
                                            <a href="dashboard_staff.php?clear_user=<?php echo (int)$u['User_ID']; ?>"
                                               onclick="return confirm('Confirm clearance for <?php echo htmlspecialchars($u['Name']); ?>?');"
                                               class="btn-success">Clear Now</a>
                                        <?php else: ?>
                                            Not Eligible
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="empty-state">No users (students/teachers) found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div style="display: flex; gap: 30px;">
              <section class="dashboard-section" style="flex: 1;">
                <h3 class="section-title">Full Inventory Overview</h3>
                <div class="table-container">
                    <table class="styled-table">
                        <thead>
                            <tr><th>ID</th><th>Title</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($inventory->num_rows > 0): ?>
                                <?php while ($b = $inventory->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $b['Book_ID']; ?></td>
                                    <td><?php echo htmlspecialchars($b['Title']); ?></td>
                                    <td>
                                        <?php 
                                            $s = strtolower($b['Status']);
                                            $statusClass = str_replace(' ', '-', $s);
                                        ?>
                                        <span class="status-badge status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($b['Status']); ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="empty-state">No books in inventory.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

              <section class="dashboard-section" style="flex: 1;">
                <h3 class="section-title">Damaged/Repair/Missing Books</h3>
                <div class="table-container">
                    <table class="styled-table">
                        <thead>
                            <tr><th>ID</th><th>Title</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($damaged->num_rows > 0): ?>
                                <?php while ($d = $damaged->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $d['Book_ID']; ?></td>
                                    <td><?php echo htmlspecialchars($d['Title']); ?></td>
                                    <td>
                                        <?php 
                                            $s = strtolower($d['Status']);
                                            $statusClass = str_replace(' ', '-', $s);
                                        ?>
                                        <span class="status-badge status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($d['Status']); ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="empty-state">No damaged books listed.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

    </main>
</body>
</html>