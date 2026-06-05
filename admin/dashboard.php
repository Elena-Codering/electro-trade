<?php
session_start();
require_once '../config/db.php';

// Only admin roles allowed
$allowedRoles = ['admin','moderator','support','finance'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../auth/login.php');
    exit;
}

$role = $_SESSION['role'];

// Fetch platform stats
$stats = $pdo->query('
    SELECT
        (SELECT COUNT(*) FROM users WHERE role IN ("buyer","seller")) AS total_users,
        (SELECT COUNT(*) FROM users WHERE role = "seller") AS total_sellers,
        (SELECT COUNT(*) FROM users WHERE role = "buyer") AS total_buyers,
        (SELECT COUNT(*) FROM listings WHERE status = "active") AS active_listings,
        (SELECT COUNT(*) FROM listings WHERE status = "pending") AS pending_listings,
        (SELECT COUNT(*) FROM listings WHERE status = "flagged") AS flagged_listings,
        (SELECT COUNT(*) FROM orders) AS total_orders,
        (SELECT COUNT(*) FROM orders WHERE status = "completed") AS completed_orders,
        (SELECT COALESCE(SUM(total_amount),0) FROM orders 
         WHERE status = "completed") AS total_revenue,
        (SELECT COUNT(*) FROM orders WHERE status = "disputed") AS disputed_orders
')->fetch();

// Fetch recent users
$recentUsers = $pdo->query('
    SELECT user_id, first_name, last_name, email, role, 
           is_active, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 8
')->fetchAll();

// Fetch recent orders
$recentOrders = $pdo->query('
    SELECT o.order_id, o.total_amount, o.status, o.created_at,
           l.title AS listing_title,
           CONCAT(u.first_name," ",u.last_name) AS buyer_name
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users u ON o.buyer_id = u.user_id
    ORDER BY o.created_at DESC
    LIMIT 8
')->fetchAll();

// Fetch flagged listings
$flagged = $pdo->query('
    SELECT l.*, 
           CONCAT(u.first_name," ",u.last_name) AS seller_name,
           li.image_url
    FROM listings l
    JOIN users u ON l.seller_id = u.user_id
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE l.status = "flagged"
    LIMIT 5
')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard – Electro Trade</title>
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

    .active-dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      margin-right: .3rem;
    }

    .dot-active   { background: var(--success); }
    .dot-inactive { background: var(--danger); }

    .revenue-highlight {
      font-size: 1.8rem;
      font-weight: 700;
      color: var(--success);
    }

    .quick-actions {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .quick-action {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.25rem;
      text-align: center;
      text-decoration: none;
      color: var(--text);
      transition: transform var(--transition), box-shadow var(--transition);
      border: 2px solid transparent;
    }

    .quick-action:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 24px rgba(0,0,0,.12);
      border-color: var(--primary);
      text-decoration: none;
      color: var(--text);
    }

    .quick-action .qa-icon { font-size: 2rem; margin-bottom: .5rem; }
    .quick-action .qa-label { font-size: .88rem; font-weight: 600; }
  </style>
</head>
<body>

<div class="dashboard-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <span class="sidebar-brand">⚡ Electro Trade Admin</span>
    <nav class="sidebar-nav">
      <a href="dashboard.php" class="active">🏠 Dashboard</a>

      <?php if (in_array($role, ['admin','moderator'])): ?>
        <a href="users.php">👥 Users</a>
        <a href="listings.php">📦 Listings</a>
      <?php endif; ?>

      <?php if (in_array($role, ['admin','support'])): ?>
        <a href="orders.php">🛒 Orders</a>
      <?php endif; ?>

<!--      <?php if (in_array($role, ['admin','finance'])): ?>
        <a href="reports.php">📊 Reports</a>
      <?php endif; ?>-->

      <?php if ($role === 'admin'): ?>
        <a href="roles.php">🔐 Roles</a>
        <a href="settings.php">⚙️ Settings</a>
      <?php endif; ?>

      <a href="../index.php">🌐 View Site</a>
      <a href="../auth/logout.php" 
         style="color:rgb(255, 255, 255); margin-top:auto;">
        🚪 Logout
      </a>
    </nav>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <main class="dashboard-content">

    <!-- Header -->
    <div style="display:flex; justify-content:space-between; 
                align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>Admin Dashboard 🛡️</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          Welcome back, <?= htmlspecialchars($_SESSION['user_name']) ?>
          <span class="role-badge role-<?= $role ?>"><?= ucfirst($role) ?></span>
        </p>
      </div>
      <div style="font-size:.85rem; color:var(--text-muted);">
        <?= date('l, d F Y') ?>
      </div>
    </div>

    <!-- ── STAT CARDS ── -->
    <div class="stat-cards">
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f0fb;">👥</div>
        <div>
          <div class="stat-value"><?= number_format($stats['total_users']) ?></div>
          <div class="stat-label">Total Users</div>
          <div style="font-size:.75rem; color:var(--text-muted);">
            <?= $stats['total_buyers'] ?> buyers · 
            <?= $stats['total_sellers'] ?> sellers
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:#eafaf1;">📦</div>
        <div>
          <div class="stat-value"><?= number_format($stats['active_listings']) ?></div>
          <div class="stat-label">Active Listings</div>
          <div style="font-size:.75rem; color:var(--warning);">
            <?= $stats['pending_listings'] ?> pending approval
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:#fef9e7;">🛒</div>
        <div>
          <div class="stat-value"><?= number_format($stats['total_orders']) ?></div>
          <div class="stat-label">Total Orders</div>
          <div style="font-size:.75rem; color:var(--success);">
            <?= $stats['completed_orders'] ?> completed
          </div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:#eafaf1;">💰</div>
        <div>
          <div class="revenue-highlight">
            R<?= number_format($stats['total_revenue'], 2) ?>
          </div>
          <div class="stat-label">Total Revenue</div>
        </div>
      </div>

      <?php if ($stats['flagged_listings'] > 0): ?>
        <div class="stat-card" style="border-left:4px solid var(--danger);">
          <div class="stat-icon" style="background:#fdf0ef;">🚩</div>
          <div>
            <div class="stat-value" style="color:var(--danger);">
              <?= $stats['flagged_listings'] ?>
            </div>
            <div class="stat-label">Flagged Listings</div>
            <a href="listings.php?filter=flagged" 
               style="font-size:.75rem;">Review now →</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($stats['disputed_orders'] > 0): ?>
        <div class="stat-card" style="border-left:4px solid var(--warning);">
          <div class="stat-icon" style="background:#fef9e7;">⚠️</div>
          <div>
            <div class="stat-value" style="color:var(--warning);">
              <?= $stats['disputed_orders'] ?>
            </div>
            <div class="stat-label">Disputed Orders</div>
            <a href="orders.php?filter=disputed" 
               style="font-size:.75rem;">Review now →</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── QUICK ACTIONS ── -->
    <?php if ($role === 'admin'): ?>
      <h5 style="margin-bottom:1rem;">⚡ Quick Actions</h5>
      <div class="quick-actions">
        <a href="users.php" class="quick-action">
          <div class="qa-icon">👥</div>
          <div class="qa-label">Manage Users</div>
        </a>
        <a href="listings.php?filter=pending" class="quick-action">
          <div class="qa-icon">⏳</div>
          <div class="qa-label">Approve Listings</div>
        </a>
        <a href="orders.php" class="quick-action">
          <div class="qa-icon">🛒</div>
          <div class="qa-label">View Orders</div>
        </a>
<!--        <a href="reports.php" class="quick-action">
          <div class="qa-icon">📊</div>
          <div class="qa-label">Reports</div>
        </a> -->
        <a href="roles.php" class="quick-action">
          <div class="qa-icon">🔐</div>
          <div class="qa-label">Manage Roles</div>
        </a>
        <a href="settings.php" class="quick-action">
          <div class="qa-icon">⚙️</div>
          <div class="qa-label">Settings</div>
        </a>
      </div>
    <?php endif; ?>

    <div class="row">

      <!-- ── RECENT USERS ── -->
      <?php if (in_array($role, ['admin','moderator'])): ?>
        <div class="col-12 col-6" style="margin-bottom:1.5rem;">
          <div style="display:flex; justify-content:space-between; 
                      align-items:center; margin-bottom:1rem;">
            <h5 style="margin:0;">👥 Recent Users</h5>
            <a href="users.php" class="btn btn-outline btn-sm">View All</a>
          </div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Joined</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentUsers as $u): ?>
                  <tr>
                    <td><?= $u['user_id'] ?></td>
                    <td>
                      <div style="font-weight:600; font-size:.88rem;">
                        <?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?>
                      </div>
                      <div style="font-size:.75rem; color:var(--text-muted);">
                        <?= htmlspecialchars($u['email']) ?>
                      </div>
                    </td>
                    <td>
                      <span class="role-badge role-<?= $u['role'] ?>">
                        <?= ucfirst($u['role']) ?>
                      </span>
                    </td>
                    <td>
                      <span class="active-dot 
                        <?= $u['is_active'] ? 'dot-active' : 'dot-inactive' ?>">
                      </span>
                      <?= $u['is_active'] ? 'Active' : 'Suspended' ?>
                    </td>
                    <td style="font-size:.8rem; color:var(--text-muted);">
                      <?= date('d M Y', strtotime($u['created_at'])) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <!-- ── RECENT ORDERS ── -->
      <?php if (in_array($role, ['admin','support','finance'])): ?>
        <div class="col-12 col-6" style="margin-bottom:1.5rem;">
          <div style="display:flex; justify-content:space-between; 
                      align-items:center; margin-bottom:1rem;">
            <h5 style="margin:0;">🛒 Recent Orders</h5>
            <a href="orders.php" class="btn btn-outline btn-sm">View All</a>
          </div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Item</th>
                  <th>Buyer</th>
                  <th>Amount</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentOrders as $o): ?>
                  <tr>
                    <td>#<?= $o['order_id'] ?></td>
                    <td style="max-width:140px; font-size:.85rem;">
                      <?= htmlspecialchars($o['listing_title']) ?>
                    </td>
                    <td style="font-size:.85rem;">
                      <?= htmlspecialchars($o['buyer_name']) ?>
                    </td>
                    <td style="font-weight:600; color:var(--primary);">
                      R<?= number_format($o['total_amount'], 2) ?>
                    </td>
                    <td>
                      <span class="role-badge" style="
                        background:<?= $o['status'] === 'completed' ? '#eafaf1' : 
                          ($o['status'] === 'cancelled' ? '#fdf0ef' : '#fef9e7') ?>;
                        color:<?= $o['status'] === 'completed' ? 'var(--success)' : 
                          ($o['status'] === 'cancelled' ? 'var(--danger)' : '#b7770d') ?>;">
                        <?= ucfirst($o['status']) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- ── FLAGGED LISTINGS ── -->
    <?php if (!empty($flagged) && in_array($role, ['admin','moderator'])): ?>
      <div style="margin-top:1rem;">
        <div style="display:flex; justify-content:space-between; 
                    align-items:center; margin-bottom:1rem;">
          <h5 style="margin:0; color:var(--danger);">🚩 Flagged Listings</h5>
          <a href="listings.php?filter=flagged" class="btn btn-outline btn-sm">
            View All
          </a>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Image</th>
                <th>Title</th>
                <th>Seller</th>
                <th>Price</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($flagged as $f): ?>
                <tr>
                  <td>
                    <img src="../<?= htmlspecialchars($f['image_url'] ?? 'assets/img/placeholder.png') ?>"
                         style="width:44px; height:44px; object-fit:cover; 
                                border-radius:6px;" alt="">
                  </td>
                  <td style="font-weight:500;">
                    <?= htmlspecialchars($f['title']) ?>
                  </td>
                  <td><?= htmlspecialchars($f['seller_name']) ?></td>
                  <td>R<?= number_format($f['price'], 2) ?></td>
                  <td>
                    <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                      <a href="listings.php?approve=<?= $f['listing_id'] ?>"
                         class="btn btn-success btn-sm">✅ Approve</a>
                      <a href="listings.php?delete=<?= $f['listing_id'] ?>"
                         class="btn btn-danger btn-sm"
                         onclick="return confirm('Delete this listing?')">
                         🗑️ Delete
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  </main>
</div>

</body>
</html>