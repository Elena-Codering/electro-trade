<?php
session_start();
require_once '../config/db.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['user_name'] = $user['first_name'];
            $_SESSION['role']      = $user['role'];

            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                case 'moderator':
                case 'support':
                case 'finance':
                    header('Location: ../admin/dashboard.php');
                    break;
                case 'seller':
                    header('Location: ../seller/dashboard.php');
                    break;
                default:
                    header('Location: ../index.php');
            }
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – Electro Trade</title>
  <link href="../assets/css/style.css?v=20260605" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card">
    <span class="auth-logo text-center d-block">⚡ Electro Trade</span>
    <h2 class="text-center">Welcome Back</h2>
    <p class="subtitle text-center">Login to your Electro Trade account</p>

    <?php if ($error): ?>
      <div class="alert alert-danger">
        ⚠️ <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_msg'])): ?>
      <div class="alert alert-success">
        ✅ <?= htmlspecialchars($_SESSION['success_msg']) ?>
      </div>
      <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>

    <form method="POST">
      <!-- Email -->
      <div class="mb-3">
        <label class="form-label">Email Address</label>
        <div class="form-label">
          <input type="email" name="email" class="form-control"
            placeholder="you@example.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
      </div>

      <!-- Password -->
      <div class="mb-4">
        <label class="form-label">Password</label>
        <div class="form-group mb-3">
          <input type="password" name="password" id="passwordInput"
            class="form-control" placeholder="Enter your password" required>
          <input type="checkbox" onclick="myFunction()">Show Password
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100 mb-3">
        Login
      </button>
    </form>

    <p class="text-center mt-2 text-muted" style="font-size:.9rem">
      Don't have an account? 
      <a href="register.php" class="fw-bold">Register here</a>
    </p>
  </div>
</div>

<script>
function myFunction() {
  var x = document.getElementById("passwordInput");
  if (x.type === "password") {
    x.type = "text";
  } else {
    x.type = "password";
  }
}
</script>