<?php
session_start();
require_once '../config/db.php';

// Only super admin allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

$success = '';
$error   = '';

// ── HANDLE ROLE UPDATE ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $uid     = (int)$_POST['user_id'];
    $newRole = $_POST['new_role'];
    $allowed = ['buyer','seller','admin','moderator','support','finance'];

    if ($uid === $_SESSION['user_id']) {
        $error = 'You cannot change your own role.';
    } elseif (!in_array($newRole, $allowed)) {
        $error = 'Invalid role selected.';
    } else {
        $pdo->prepare('UPDATE users SET role = :role WHERE user_id = :id')
            ->execute([':role' => $newRole, ':id' => $uid]);

        // Notify user
        $pdo->prepare('
            INSERT INTO notifications (user_id, type, message)
            VALUES (:uid, "system", :msg)
        ')->execute([
            ':uid' => $uid,
            ':msg' => 'Your account role has been updated to: ' . ucfirst($newRole),
        ]);

        $success = 'Role updated successfully.';
    }
}

// Fetch all staff (non buyer/seller)
$staff = $pdo->query('
    SELECT user_id, first_name, last_name, email, role,
           is_active, created_at
    FROM users
    WHERE role IN ("admin","moderator","support","finance")
    ORDER BY role, first_name
')->fetchAll();

// Fetch all users for role assignment
$allUsers = $pdo->query('
    SELECT user_id, first_name, last_name, email, role
    FROM users
    ORDER BY first_name
')->fetchAll();

// Role descriptions
$roleInfo = [
    'admin' => [
        'icon'  => '👑',
        'color' => '#e8f0fb',
        'text'  => 'var(--primary)',
        'desc'  => 'Full access to all platform features including user management, listings, orders, reports, roles and system settings.',
        'perms' => ['Manage all users','Manage all listings','Manage all orders','View reports','Assign roles','Configure settings','Delete content'],
    ],
    'moderator' => [
        'icon'  => '🛡️',
        'color' => '#eafaf1',
        'text'  => 'var(--success)',
        'desc'  => 'Reviews and moderates listings and user content. Can approve, flag or remove listings.',
        'perms' => ['View all users','Approve listings','Flag listings','Delete listings','View dashboard'],
    ],
    'support' => [
        'icon'  => '🎧',
        'color' => '#fef9e7',
        'text'  => '#b7770d',
        'desc'  => 'Handles customer support, disputes and order management.',
        'perms' => ['View all orders','Update order status','Manage disputes','View users (read only)'],
    ],
    'finance' => [
        'icon'  => '💼',
        'color' => '#fdf0ef',
        'text'  => 'var(--danger)',
        'desc'  => 'Access to financial reports, revenue data and CSV exports.',
        'perms' => ['View reports','Export data','View revenue stats','View orders (read only)'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Role Management – Electro Trade Admin</title>
  <link href="../assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    .role-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      border-left: 4px solid var(--primary);
    }

    .role-header {
      display: flex;
      align-items: center;
      gap: .75rem;
      margin-bottom: 1rem;
    }

    .role-icon {
      font-size: 1.8rem;
    }

    .role-title {
      font-size: 1.1rem;
      font-weight: 700;
    }

    .role-badge {
      display: inline-block;
      padding: .2rem .6rem;
      border-radius: 20px;
      font-size: .75rem;
      font-weight: 600;
    }

    .role-admin     { background:#e8f0fb; color:var(--primary); }
    .role-moderator { background:#eafaf1; color:var(--success); }
    .role-support   { background:#fef9e7; color:#b7770d; }
    .role-finance   { background:#fdf0ef; color:var(--danger); }

    .perm-list {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
      margin-top: .75rem;
    }

    .perm-tag {
      background: var(--bg);
      border-radius: 6px;
      padding: .25rem .65rem;
      font-size: .78rem;
      color: var(--text);
      border: 1px solid var(--border);
    }

    .staff-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px,1fr));
      gap: 1rem;
      margin-top: 1rem;
    }

    .staff-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.25rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .staff-avatar {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      background: var(--primary);
      color: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      font-weight: 700;
      flex-shrink: 0;
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

    .modal-overlay.open { display: flex; }

    .modal-box {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: 0 8px 32px rgba(0,0,0,.2);
      padding: 2rem;
      width: 100%;
      max-width: 500px;
      position: relative;
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
  </style>
</head>
<body>

<div class="dashboard-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <span class="sidebar-brand">⚡ Electro Trade Admin</span>
    <nav class="sidebar-nav">
      <a href="dashboard.php">🏠 Dashboard</a>
      <a href="users.php">👥 Users</a>
      <a href="listings.php">📦 Listings</a>
      <a href="orders.php">🛒 Orders</a>
<!--      <a href="reports.php">📊 Reports</a> -->
      <a href="roles.php" class="active">🔐 Roles</a>
      <a href="settings.php">⚙️ Settings</a>
      <a href="../index.php">🌐 View Site</a>
      <a href="../auth/logout.php" style="color:rgb(255, 255, 255);">🚪 Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN ── -->
  <main class="dashboard-content">

    <div style="display:flex; justify-content:space-between; align-items:center;
                margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>🔐 Role Management</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          Manage staff roles and permissions
        </p>
      </div>
      <button class="btn btn-primary" onclick="openModal('assignModal')">
        ➕ Assign Role
      </button>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── ROLE DESCRIPTIONS ── -->
    <h5 style="margin-bottom:1rem;">📋 Available Roles & Permissions</h5>
    <div class="row">
      <?php foreach ($roleInfo as $rName => $rData): ?>
        <div class="col-12 col-6" style="margin-bottom:1rem;">
          <div class="role-card" style="border-left-color:<?= $rData['text'] ?>;">
            <div class="role-header">
              <span class="role-icon"><?= $rData['icon'] ?></span>
              <div>
                <div class="role-title"><?= ucfirst($rName) ?></div>
                <span class="role-badge role-<?= $rName ?>">
                  <?= count(array_filter($staff, fn($s) => $s['role'] === $rName)) ?>
                  assigned
                </span>
              </div>
            </div>
            <p style="font-size:.88rem; color:var(--text-muted); margin-bottom:.75rem;">
              <?= $rData['desc'] ?>
            </p>
            <div class="perm-list">
              <?php foreach ($rData['perms'] as $perm): ?>
                <span class="perm-tag">✓ <?= $perm ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ── CURRENT STAFF ── -->
    <h5 style="margin:1.5rem 0 1rem;">👥 Current Staff Members</h5>
    <?php if (empty($staff)): ?>
      <div class="sz-card text-center" style="padding:2rem;">
        <p style="color:var(--text-muted);">No staff members assigned yet.</p>
      </div>
    <?php else: ?>
      <div class="staff-grid">
        <?php foreach ($staff as $s): ?>
          <div class="staff-card">
            <div class="staff-avatar">
              <?= strtoupper(substr($s['first_name'], 0, 1)) ?>
            </div>
            <div style="flex:1; min-width:0;">
              <div style="font-weight:600; font-size:.92rem;">
                <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                <?php if ($s['user_id'] === $_SESSION['user_id']): ?>
                  <span style="font-size:.72rem; color:var(--text-muted);">(You)</span>
                <?php endif; ?>
              </div>
              <div style="font-size:.78rem; color:var(--text-muted); 
                          overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                <?= htmlspecialchars($s['email']) ?>
              </div>
              <div style="margin-top:.4rem; display:flex; 
                          align-items:center; gap:.5rem; flex-wrap:wrap;">
                <span class="role-badge role-<?= $s['role'] ?>">
                  <?= ucfirst($s['role']) ?>
                </span>
                <span style="font-size:.75rem; color:<?= $s['is_active'] 
                    ? 'var(--success)' : 'var(--danger)' ?>;">
                  ● <?= $s['is_active'] ? 'Active' : 'Suspended' ?>
                </span>
              </div>
            </div>
            <!-- Change role inline -->
            <?php if ($s['user_id'] !== $_SESSION['user_id']): ?>
              <form method="POST">
                <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                <select name="new_role" class="form-control"
                        style="padding:.3rem .5rem; font-size:.8rem; 
                               width:auto; margin-bottom:.4rem;">
                  <?php foreach (['buyer','seller','admin','moderator','support','finance'] as $r): ?>
                    <option value="<?= $r ?>" <?= $s['role'] === $r ? 'selected' : '' ?>>
                      <?= ucfirst($r) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" name="update_role"
                        class="btn btn-primary btn-sm w-100">
                  Save
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

<!-- ── ASSIGN ROLE MODAL ── -->
<div class="modal-overlay" id="assignModal">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('assignModal')">✕</button>
    <h5>➕ Assign Role to User</h5>
    <p style="color:var(--text-muted); font-size:.88rem; margin-bottom:1.25rem;">
      Select a user and assign them a staff role.
    </p>
    <form method="POST">
      <div class="form-group">
        <label class="form-label">Select User</label>
        <select name="user_id" class="form-control" required>
          <option value="">-- Select a user --</option>
          <?php foreach ($allUsers as $u): ?>
            <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
              <option value="<?= $u['user_id'] ?>">
                <?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?>
                (<?= htmlspecialchars($u['email']) ?>) –
                Current: <?= ucfirst($u['role']) ?>
              </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Assign Role</label>
        <select name="new_role" class="form-control" required>
          <?php foreach (['buyer','seller','admin','moderator','support','finance'] as $r): ?>
            <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Role preview -->
      <div id="rolePreview" style="background:var(--bg); border-radius:var(--radius-sm);
                                    padding:1rem; margin-bottom:1rem; display:none;">
        <div style="font-weight:600; margin-bottom:.4rem;" id="previewTitle"></div>
        <div style="font-size:.85rem; color:var(--text-muted);" id="previewDesc"></div>
      </div>

      <button type="submit" name="update_role" class="btn btn-primary w-100">
        🔐 Assign Role
      </button>
    </form>
  </div>
</div>

<script>
  function openModal(id) {
    document.getElementById(id).classList.add('open');
  }

  function closeModal(id) {
    document.getElementById(id).classList.remove('open');
  }

  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
      if (e.target === this) this.classList.remove('open');
    });
  });

  // Role preview on select
  const roleDescs = <?= json_encode(array_map(fn($r) => [
    'icon' => $r['icon'],
    'desc' => $r['desc']
  ], $roleInfo)) ?>;

  document.querySelector('[name="new_role"]')?.addEventListener('change', function() {
    const preview = document.getElementById('rolePreview');
    const title   = document.getElementById('previewTitle');
    const desc    = document.getElementById('previewDesc');
    const info    = roleDescs[this.value];

    if (info) {
      title.textContent = info.icon + ' ' + this.value.charAt(0).toUpperCase()
                        + this.value.slice(1);
      desc.textContent  = info.desc;
      preview.style.display = 'block';
    } else {
      preview.style.display = 'none';
    }
  });
</script>
</body>
</html>