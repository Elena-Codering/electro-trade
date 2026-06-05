<?php
session_start();
require_once '../config/db.php';

// Only admin and moderator allowed
$allowedRoles = ['admin', 'moderator'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../auth/login.php');
    exit;
}

$role    = $_SESSION['role'];
$success = '';
$error   = '';

// ── HANDLE ACTIONS ──────────────────────────────────────
// Suspend / Activate user
if (isset($_GET['suspend'])) {
    $uid = (int)$_GET['suspend'];
    if ($uid !== $_SESSION['user_id']) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE user_id = :id')
            ->execute([':id' => $uid]);
        $success = 'User suspended successfully.';
    } else {
        $error = 'You cannot suspend your own account.';
    }
}

if (isset($_GET['activate'])) {
    $uid = (int)$_GET['activate'];
    $pdo->prepare('UPDATE users SET is_active = 1 WHERE user_id = :id')
        ->execute([':id' => $uid]);
    $success = 'User activated successfully.';
}

// Delete user
if (isset($_GET['delete']) && $role === 'admin') {
    $uid = (int)$_GET['delete'];
    if ($uid !== $_SESSION['user_id']) {
        $pdo->prepare('DELETE FROM users WHERE user_id = :id')
            ->execute([':id' => $uid]);
        $success = 'User deleted successfully.';
    } else {
        $error = 'You cannot delete your own account.';
    }
}

// Update role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $uid     = (int)$_POST['user_id'];
    $newRole = $_POST['new_role'];
    $allowed = ['buyer','seller','admin','moderator','support','finance'];
    if (in_array($newRole, $allowed) && $uid !== $_SESSION['user_id']) {
        $pdo->prepare('UPDATE users SET role = :role WHERE user_id = :id')
            ->execute([':role' => $newRole, ':id' => $uid]);
        $success = 'User role updated successfully.';
    }
}

// Create new admin user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $fn    = trim($_POST['first_name']);
    $ln    = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];
    $ur    = $_POST['role'];

    $check = $pdo->prepare('SELECT user_id FROM users WHERE email = :e');
    $check->execute([':e' => $email]);

    if ($check->fetch()) {
        $error = 'A user with this email already exists.';
    } elseif (empty($fn) || empty($ln) || empty($email) || empty($pass)) {
        $error = 'All fields are required.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $pdo->prepare('
            INSERT INTO users (first_name, last_name, email, password_hash, role, is_verified, is_active)
            VALUES (:fn, :ln, :email, :hash, :role, 1, 1)
        ')->execute([
            ':fn'    => $fn,
            ':ln'    => $ln,
            ':email' => $email,
            ':hash'  => $hash,
            ':role'  => $ur,
        ]);
        $success = 'User created successfully.';
    }
}

// ── SEARCH & FILTER ──────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$filterRole = $_GET['filter_role'] ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

$sql    = 'SELECT * FROM users WHERE 1=1';
$params = [];

if ($search) {
    $sql .= ' AND (first_name LIKE :s OR last_name LIKE :s OR email LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}
if ($filterRole) {
    $sql .= ' AND role = :role';
    $params[':role'] = $filterRole;
}

// Count total
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE 1=1' .
    ($search ? ' AND (first_name LIKE :s OR last_name LIKE :s OR email LIKE :s)' : '') .
    ($filterRole ? ' AND role = :role' : ''));
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

$sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management – Electro Trade Admin</title>
  <link href="../assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    .role-admin     { background:#e8f0fb; color:var(--primary); }
    .role-moderator { background:#eafaf1; color:var(--success); }
    .role-support   { background:#fef9e7; color:#b7770d; }
    .role-finance   { background:#fdf0ef; color:var(--danger); }
    .role-seller    { background:#f5f0ff; color:#6c3483; }
    .role-buyer     { background:#f0f9ff; color:#1a6fa8; }

    .role-badge {
      display: inline-block;
      padding: .2rem .6rem;
      border-radius: 20px;
      font-size: .75rem;
      font-weight: 600;
    }

    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.5);
      z-index: 999;
      align-items: center;
      justify-content: center;
    }

    .modal-overlay.open {
      display: flex;
    }

    .modal-box {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: 0 8px 32px rgba(0,0,0,.2);
      padding: 2rem;
      width: 100%;
      max-width: 480px;
      position: relative;
    }

    .modal-box h5 {
      color: var(--primary);
      margin-bottom: 1.25rem;
    }

    .modal-close {
      position: absolute;
      top: 1rem;
      right: 1rem;
      background: none;
      border: none;
      font-size: 1.2rem;
      cursor: pointer;
      color: var(--text-muted);
    }

    .avatar-sm {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--primary);
      color: var(--white);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: .85rem;
      font-weight: 700;
      flex-shrink: 0;
    }
  </style>
</head>
<body>

<div class="dashboard-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <span class="sidebar-brand">⚡ Electro Trade Admin</span>
    <nav class="sidebar-nav">
      <a href="dashboard.php">🏠 Dashboard</a>
      <a href="users.php" class="active">👥 Users</a>
      <a href="listings.php">📦 Listings</a>
      <?php if (in_array($role, ['admin','support'])): ?>
        <a href="orders.php">🛒 Orders</a>
      <?php endif; ?>
<!--      <?php if (in_array($role, ['admin','finance'])): ?>
        <a href="reports.php">📊 Reports</a>
      <?php endif; ?> -->
      <?php if ($role === 'admin'): ?>
        <a href="roles.php">🔐 Roles</a>
        <a href="settings.php">⚙️ Settings</a>
      <?php endif; ?>
      <a href="../index.php">🌐 View Site</a>
      <a href="../auth/logout.php" style="color:rgb(255, 255, 255);">🚪 Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN ── -->
  <main class="dashboard-content">

    <div style="display:flex; justify-content:space-between; align-items:center;
                margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>👥 User Management</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          <?= number_format($totalUsers) ?> total users
        </p>
      </div>
      <?php if ($role === 'admin'): ?>
        <button class="btn btn-primary" onclick="openModal('createModal')">
          ➕ Create User
        </button>
      <?php endif; ?>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── SEARCH & FILTER ── -->
    <div class="sz-card" style="padding:1rem; margin-bottom:1.5rem;">
      <form method="GET" style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:200px;">
          <label class="form-label">Search</label>
          <input type="text" name="search" class="form-control"
            placeholder="Name or email..."
            value="<?= htmlspecialchars($search) ?>">
        </div>
        <div style="min-width:160px;">
          <label class="form-label">Filter by Role</label>
          <select name="filter_role" class="form-control">
            <option value="">All Roles</option>
            <?php foreach (['buyer','seller','admin','moderator','support','finance'] as $r): ?>
              <option value="<?= $r ?>" <?= $filterRole === $r ? 'selected' : '' ?>>
                <?= ucfirst($r) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">🔍 Search</button>
        <a href="users.php" class="btn btn-outline">Clear</a>
      </form>
    </div>

    <!-- ── USERS TABLE ── -->
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>User</th>
            <th>Role</th>
            <th>Status</th>
            <th>Joined</th>
            <?php if ($role === 'admin'): ?>
              <th>Change Role</th>
            <?php endif; ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= $u['user_id'] ?></td>
              <td>
                <div style="display:flex; align-items:center; gap:.75rem;">
                  <div class="avatar-sm">
                    <?= strtoupper(substr($u['first_name'], 0, 1)) ?>
                  </div>
                  <div>
                    <div style="font-weight:600; font-size:.9rem;">
                      <?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?>
                    </div>
                    <div style="font-size:.78rem; color:var(--text-muted);">
                      <?= htmlspecialchars($u['email']) ?>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <span class="role-badge role-<?= $u['role'] ?>">
                  <?= ucfirst($u['role']) ?>
                </span>
              </td>
              <td>
                <?php if ($u['is_active']): ?>
                  <span style="color:var(--success); font-weight:600; font-size:.85rem;">
                    ● Active
                  </span>
                <?php else: ?>
                  <span style="color:var(--danger); font-weight:600; font-size:.85rem;">
                    ● Suspended
                  </span>
                <?php endif; ?>
              </td>
              <td style="font-size:.82rem; color:var(--text-muted);">
                <?= date('d M Y', strtotime($u['created_at'])) ?>
              </td>

              <!-- Change Role (Admin only) -->
              <?php if ($role === 'admin'): ?>
                <td>
                  <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                    <form method="POST" style="display:flex; gap:.4rem;">
                      <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                      <select name="new_role" class="form-control"
                              style="padding:.3rem .6rem; font-size:.8rem; width:auto;">
                        <?php foreach (['buyer','seller','admin','moderator','support','finance'] as $r): ?>
                          <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>>
                            <?= ucfirst($r) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" name="update_role"
                              class="btn btn-primary btn-sm">Save</button>
                    </form>
                  <?php else: ?>
                    <span style="font-size:.8rem; color:var(--text-muted);">
                      (You)
                    </span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>

              <!-- Actions -->
              <td>
                <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                  <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                    <?php if ($u['is_active']): ?>
                      <a href="users.php?suspend=<?= $u['user_id'] ?>"
                         class="btn btn-accent btn-sm"
                         onclick="return confirm('Suspend this user?')">
                         ⏸ Suspend
                      </a>
                    <?php else: ?>
                      <a href="users.php?activate=<?= $u['user_id'] ?>"
                         class="btn btn-success btn-sm">
                         ▶ Activate
                      </a>
                    <?php endif; ?>
                    <?php if ($role === 'admin'): ?>
                      <a href="users.php?delete=<?= $u['user_id'] ?>"
                         class="btn btn-danger btn-sm"
                         onclick="return confirm('Permanently delete this user?')">
                         🗑️
                      </a>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="font-size:.8rem; color:var(--text-muted);">—</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ── PAGINATION ── -->
    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&filter_role=<?= $filterRole ?>">
            ← Prev
          </a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <<?= $i === $page ? 'span class="active"' : 'a href="?page='.$i.'&search='.urlencode($search).'&filter_role='.$filterRole.'"' ?>>
            <?= $i ?>
          </<?= $i === $page ? 'span' : 'a' ?>>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&filter_role=<?= $filterRole ?>">
            Next →
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

<!-- ── CREATE USER MODAL ── -->
<?php if ($role === 'admin'): ?>
  <div class="modal-overlay" id="createModal">
    <div class="modal-box">
      <button class="modal-close" onclick="closeModal('createModal')">✕</button>
      <h5>➕ Create New User</h5>
      <form method="POST">
        <div class="row">
          <div class="col-12 col-6">
            <div class="form-group">
              <label class="form-label">First Name</label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
          </div>
          <div class="col-12 col-6">
            <div class="form-group">
              <label class="form-label">Last Name</label>
              <input type="text" name="last_name" class="form-control" required>
            </div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control"
            placeholder="Minimum 6 characters" required>
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-control">
            <?php foreach (['buyer','seller','admin','moderator','support','finance'] as $r): ?>
              <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="create_user" class="btn btn-primary w-100">
          ➕ Create User
        </button>
      </form>
    </div>
  </div>
<?php endif; ?>

<script>
  function openModal(id) {
    document.getElementById(id).classList.add('open');
  }
  function closeModal(id) {
    document.getElementById(id).classList.remove('open');
  }
  // Close modal on overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
      if (e.target === this) this.classList.remove('open');
    });
  });
</script>
</body>
</html>