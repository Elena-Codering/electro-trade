<?php
session_start();
require_once '../config/db.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $phone      = trim($_POST['phone']);
    $province   = trim($_POST['province']);
    $role       = $_POST['role'];
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    // Validation
    if (empty($first_name)) $errors[] = 'First name is required.';
    if (empty($last_name))  $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (!in_array($role, ['buyer','seller'])) $errors[] = 'Please select a valid role.';

    if (empty($errors)) {
        // Check if email exists
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users 
                (first_name, last_name, email, password_hash, phone, province, role) 
                VALUES (:fn, :ln, :email, :hash, :phone, :province, :role)');
            $stmt->execute([
                ':fn'       => $first_name,
                ':ln'       => $last_name,
                ':email'    => $email,
                ':hash'     => $hash,
                ':phone'    => $phone,
                ':province' => $province,
                ':role'     => $role,
            ]);
            $success = 'Account created successfully! You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register – Electro Trade</title>
  <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-card">
    <span class="auth-logo text-center d-block">⚡ Electro Trade</span>
    <h2 class="text-center">Create Account</h2>
    <p class="subtitle text-center">Join thousands of South Africans buying & selling electronics</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?>
          <div>⚠️ <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success">
        ✅ <?= htmlspecialchars($success) ?>
        <a href="login.php" class="fw-bold">Login here</a>
      </div>
    <?php endif; ?>

    <form method="POST">
      <!-- Role Selection -->
      <div class="mb-3">
        <label class="form-label">I want to <span class="text-danger">*</span></label>
        <div class="d-flex gap-3">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="role" value="buyer" id="roleBuyer"
              <?= (($_POST['role'] ?? 'buyer') === 'buyer') ? 'checked' : '' ?>>
            <label class="form-check-label" for="roleBuyer">
              <i class="bi bi-bag"></i> Buy items
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="role" value="seller" id="roleSeller"
              <?= (($_POST['role'] ?? '') === 'seller') ? 'checked' : '' ?>>
            <label class="form-check-label" for="roleSeller">
              <i class="bi bi-shop"></i> Sell items
            </label>
          </div>
        </div>
      </div>

      <!-- Name -->
      <div class="row">
        <div class="col-6 mb-3">
          <label class="form-label">First Name <span class="text-danger">*</span></label>
          <input type="text" name="first_name" class="form-control"
            value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
        </div>
        <div class="col-6 mb-3">
          <label class="form-label">Last Name <span class="text-danger">*</span></label>
          <input type="text" name="last_name" class="form-control"
            value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
        </div>
      </div>

      <!-- Email -->
      <div class="mb-3">
        <label class="form-label">Email Address <span class="text-danger">*</span></label>
        <input type="email" name="email" class="form-control"
          value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>

      <!-- Phone -->
      <div class="mb-3">
        <label class="form-label">Phone Number</label>
        <input type="text" name="phone" class="form-control"
          placeholder="e.g. 071 234 5678"
          value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>

      <!-- Province -->
      <div class="mb-3">
        <label class="form-label">Province</label>
        <select name="province" class="form-control">
          <option value="">-- Select Province --</option>
          <?php
          $provinces = ['Gauteng','Western Cape','KwaZulu-Natal','Eastern Cape',
                        'Limpopo','Mpumalanga','North West','Free State','Northern Cape'];
          foreach ($provinces as $p):
            $sel = (($_POST['province'] ?? '') === $p) ? 'selected' : '';
          ?>
            <option value="<?= $p ?>" <?= $sel ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Password -->
      <div class="mb-3">
        <label class="form-label">Password <span class="text-danger">*</span></label>
        <input type="password" name="password" class="form-control"
          placeholder="Minimum 6 characters" required>
      </div>

      <!-- Confirm Password -->
      <div class="mb-4">
        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>

      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-person-plus"></i> Create Account
      </button>
    </form>

    <p class="text-center mt-3 text-muted" style="font-size:.9rem">
      Already have an account? <a href="login.php" class="fw-bold">Login here</a>
    </p>

  </div>
</div>
</body>
</html>