<?php
session_start();
include 'db_connect.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT User_ID, Name, Email, Password, Role FROM users WHERE Email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ($password === $row['Password']) {
            $_SESSION['id'] = $row['User_ID'];
            $_SESSION['name'] = $row['Name'];
            $_SESSION['email'] = $row['Email']; 
            $_SESSION['role'] = $row['Role'];

            switch ($row['Role']) {
                case 'Student':
                    header("Location: dashboard_student.php");
                    break;
                case 'Teacher':
                    header("Location: dashboard_teacher.php");
                    break;
                case 'Librarian':
                    header("Location: dashboard_librarian.php");
                    break;
                case 'Staff':
                    header("Location: dashboard_staff.php");
                    break;
                default:
                    $error = "Unknown role.";
            }
            exit;
        } else {
            $error = "Incorrect password.";
        }
    } else {
        $error = "No account found with that email.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BookHive Login</title>

<style>
:root {
  --bg-cream: #f9f3ec;
  --overlay: rgba(0,0,0,0.35);

  --card-bg: #ffffff;
  --card-border: #d88b4a;

  --text-dark: #4a3a2e;
  --text-muted: #8a6b52;

  --accent-main: #d88b4a;
  --accent-hover: #b9743c;

  --input-bg: #f3e8dd;
  --input-border: #d8b89a;

  --error-bg: #ffebee;
  --error-text: #d32f2f;
}

/* ✅ FULL PAGE BACKGROUND IMAGE */
body {
  margin: 0;
  padding: 0;
  font-family: 'Segoe UI', Roboto, sans-serif;

  background: url('images/mycover.jpg') center/cover no-repeat fixed;

  position: relative;
  color: var(--text-dark);
  animation: fadeIn 1.2s ease-out;

  display: flex;
  justify-content: center;
  align-items: center;
  height: 100vh;
}

/* ✅ DARK OVERLAY FOR READABILITY */
body::before {
  content: "";
  position: absolute;
  inset: 0;
  background: var(--overlay);
  z-index: 0;
}

/* ✅ LOGIN CARD */
.login-card {
  position: relative;
  z-index: 1;

  background-color: var(--card-bg);
  padding: 3rem 2.5rem;
  width: 100%;
  max-width: 420px;

  border-radius: 14px;
  text-align: center;
  border: 2px solid var(--card-border);
  box-shadow: 0 0 20px rgba(0,0,0,0.25);
}

/* Header */
.login-card h2 {
  margin-bottom: 1.8rem;
  color: var(--accent-main);
  font-weight: 700;
  font-size: 1.8rem;
}

/* Error message */
.error-message {
  background-color: var(--error-bg);
  color: var(--error-text);
  padding: 12px 15px;
  border-radius: 8px;
  margin-bottom: 1.5rem;
  border: 1px solid var(--error-text);
}

/* Form */
.form-group {
  margin-bottom: 1.5rem;
  text-align: left;
}
.form-label {
  color: var(--text-muted);
  margin-bottom: 6px;
  display: block;
}
.form-input {
  width: 100%;
  padding: 12px 15px;
  border-radius: 6px;
  border: 1px solid var(--input-border);
  background-color: var(--input-bg);
  color: var(--text-dark);
}
.form-input:focus {
  outline: none;
  border-color: var(--accent-main);
  box-shadow: 0 0 8px rgba(216,139,74,0.3);
}

/* Button */
.btn-login {
  width: 100%;
  padding: 14px;
  border: none;
  border-radius: 6px;
  background-color: var(--accent-main);
  color: white;
  font-size: 1.1rem;
  font-weight: 700;
  cursor: pointer;
  transition: background-color 0.2s, transform 0.1s;
}
.btn-login:hover {
  background-color: var(--accent-hover);
}
.btn-login:active {
  transform: scale(0.97);
}

/* Animations */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Responsive */
@media (max-width: 500px) {
  .login-card {
    padding: 2rem 1.5rem;
  }
}
</style>

</head>
<body>

    <!-- ✅ LOGIN CARD -->
    <div class="login-card">
        <h2>Login to BookWorm</h2>

        <?php if ($error): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-input" required placeholder="Enter your email">
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-input" required placeholder="Enter your password">
            </div>

            <button type="submit" class="btn-login">Login</button>
        </form>
    </div>

</body>
</html>
