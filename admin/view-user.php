
<?php
session_start();
require_once '../config/db.php';

$allowedRoles = ['admin', 'moderator'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../auth/login.php');
    exit;
}

$uid = (int)($_GET['id'] ?? 0);
if (!$uid) {
    header('Location: users.php');
    exit;
}

// Fetch user
$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = :id');
$stmt->execute([':id' => $uid]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: users.php');
    exit;
}

// Fetch user stats
$stats = $pdo->prepare('
    SELECT
        COUNT(DISTINCT l.listing_id) AS total_listings,
        COUNT(DISTINCT CASE WHEN l.status = "active" THEN l.listing_id END) AS active_listings,
        COUNT(DISTINCT CASE WHEN l.status = "sold" THEN l.listing_id END) AS sold_listings,
        COUNT(DISTINCT o.order_id) AS total_orders,
        COALESCE(SUM(CASE WHEN o.status = "completed" THEN o.total_amount END),0) AS total_spent,
        ROUND(AVG(r.rating),1) AS avg_rating,
        COUNT(DISTINCT r.review_id) AS review_count
    FROM users u
    LEFT JOIN listings l ON l.seller_id = u.user_id
    LEFT JOIN orders o ON o.buyer_id = u.user_id
    LEFT JOIN reviews r ON r.seller_id = u.user_id
    WHERE u.user_id = :id
');
$stats->execute([':id' => $uid]);
$s = $stats->fetch();

// Fetch listings
$listings = $pdo->prepare('
    SELECT l.*, li.image_url
    FROM listings l
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE l.seller_id = :id
    ORDER BY l.created_at DESC
    LIMIT 6
');
$listings->execute([':id' => $uid]);
$userListings = $listings->fetchAll();

// Fetch orders as buyer
$orders = $pdo->prepare('
    SELECT o.*, l.title AS listing_title,
           CONCAT(s.first_name," ",s.last_name) AS seller_name
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users s ON l.seller_id = s.user_id
    WHERE o.buyer_id = :id
    ORDER BY o.created_at DESC
    LIMIT 6
');
$orders->execute([':id' => $uid]);
$userOrders = $orders->fetchAll();

// Fetch reviews received
$reviews = $pdo->prepare('
    SELECT r.*,
           CONCAT(u.first_name," ",u.last_name) AS reviewer_name
    FROM reviews r
    JOIN users u ON r.reviewer_id = u.user_id
    WHERE r.seller_id = :id
    ORDER BY r.created_at DESC
    LIMIT 5
');
$reviews->execute([':id' => $uid]);
$userReviews = $reviews->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View User – Electro Trade Admin</title>
  <link href="../assets/css/style.css" rel="stylesheet">
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
      font-size: .78rem;
      font-weight: 600;
    }

    .profile-header {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      border-radius: var(--radius);
      padding: 2rem;
      color: var(--white);
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex-wrap: wrap;
    }

    .profile-avatar-lg {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: rgba(255,255,255,.2);
      color: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      font-weight: 700;
      flex-shrink: 0;
      border: 3px solid rgba(255,255,255,.4);
    }

    .profile-info h3 { margin-bottom: .3rem; }

    .profile-meta {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      margin-top: .5rem;
      font-size: .85rem;
      opacity: .9;
    }

    .section-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }

    .section-card h5 {
      color: var(--primary);
      margin-bottom: 1rem;
      padding-bottom: .5rem;
      border-bottom: 2px solid var(--bg);
    }

    .listing-thumb {
      width: 48px;
      height: 48px;
      object-fit: cover;
      border-radius: 8px;
    }

    .status-active   { color:var(--success); font-weight:600; }
    .status-pending  { color:var(--warning); font-weight:600; }
    .status-sold     { color:var(--primary); font-weight:600; }
    .status-flagged  { color:var(--danger);  font-weight:600; }
    .status-deleted  { color:var(--text-muted); font-weight:600; }

    .order-placed    { color:var(--text-muted); font-weight:600; }
    .order-paid      { color:var(--primary);    font-weight:600; }
    .order-shipped   { color:#b7770d;            font-weight:600; }
    .order-delivered { color:var(--success);     font-weight:600; }
    .order-completed { color:var(--success);     font-weight:600; }
    .order-cancelled { color:var(--danger);      font-weight:600; }

    .stars { color: var(--accent); }

    .review-item {
      background: var(--bg);
      border-radius: var(--radius-sm);
      padding: .85rem 1rem;
      margin-bottom: .6rem;
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
      <a href="orders.php">🛒 Orders</a>
      <a href="reports.php">📊 Reports</a>
      <a href="roles.php">🔐 Roles</a>
      <a href="settings.php">⚙️ Settings</a>
      <a href="../index.php">🌐 View Site</a>
      <a href="../auth/logout.php" style="color:rgba(255,255,255,.6);">🚪 Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN ── -->
  <main class="dashboard-content">

    <!-- Back button -->
    <a href="users.php" class="btn btn-outline btn-sm"
       style="margin-bottom:1.25rem;">← Back to Users</a>

    <!-- ── PROFILE HEADER ── -->
    <div class="profile-header">
      <div class="profile-avatar-lg">
        <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
      </div>
      <div class="profile-info">
        <h3>
          <?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?>
          <?php if ($user['is_verified']): ?>
            <span style="font-size:.85rem; opacity:.8;">✅ Verified</span>
          <?php endif; ?>
        </h3>
        <div style="opacity:.85; font-size:.9rem;">
          <?= htmlspecialchars($user['email']) ?>
        </div>
        <div class="profile-meta">
          <span>
            <span class="role-badge role-<?= $user['role'] ?>">
              <?= ucfirst($user['role']) ?>
            </span>
          </span>
          <span>📍 <?= htmlspecialchars($user['province'] ?? 'Not set') ?></span>
          <span>📞 <?= htmlspecialchars($user['phone'] ?? 'Not set') ?></span>
          <span>📅 Joined <?= date('d M Y', strtotime($user['created_at'])) ?></span>
          <span style="color:<?= $user['is_active'] 
              ? 'rgba(255,255,255,.9)' : '#ffaaaa' ?>;">
            <?= $user['is_active'] ? '● Active' : '● Suspended' ?>
          </span>
        </div>
      </div>

      <!-- Quick Actions -->
      <div style="margin-left:auto; display:flex; flex-direction:column; 
                  gap:.5rem; min-width:140px;">
        <?php if ($user['user_id'] !== $_SESSION['user_id']): ?>
          <?php if ($user['is_active']): ?>
            <a href="users.php?suspend=<?= $uid ?>"
               class="btn btn-sm"
               style="background:rgba(255,255,255,.2); color:#fff; border:1px solid rgba(255,255,255,.4);"
               onclick="return confirm('Suspend this user?')">
              ⏸ Suspend
            </a>
          <?php else: ?>
            <a href="users.php?activate=<?= $uid ?>"
               class="btn btn-success btn-sm">
              ▶ Activate
            </a>
          <?php endif; ?>
          <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="users.php?delete=<?= $uid ?>"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Permanently delete this user?')">
              🗑️ Delete
            </a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── STAT CARDS ── -->
    <div class="stat-cards">
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f0fb;">📦</div>
        <div>
          <div class="stat-value"><?= $s['total_listings'] ?></div>
          <div class="stat-label">Total Listings</div>
          <div style="font-size:.75rem; color:var(--text-muted);">
            <?= $s['active_listings'] ?> active ·
            <?= $s['sold_listings'] ?> sold
          </div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef9e7;">🛒</div>
        <div>
          <div class="stat-value"><?= $s['total_orders'] ?></div>
          <div class="stat-label">Total Orders (as buyer)</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fdf0ef;">💸</div>
        <div>
          <div class="stat-value">R<?= number_format($s['total_spent'], 2) ?></div>
          <div class="stat-label">Total Spent</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef9e7;">⭐</div>
        <div>
          <div class="stat-value"><?= $s['avg_rating'] ?? 'N/A' ?></div>
          <div class="stat-label">Avg Rating</div>
          <div style="font-size:.75rem; color:var(--text-muted);">
            <?= $s['review_count'] ?> reviews
          </div>
        </div>
      </div>
    </div>

    <div class="row">

      <!-- ── LISTINGS ── -->
      <div class="col-12 col-6">
        <div class="section-card">
          <h5>📦 Listings
            <a href="listings.php?search=<?= urlencode($user['first_name']) ?>"
               style="font-size:.8rem; font-weight:400; margin-left:auto;">
              View all →
            </a>
          </h5>
          <?php if (empty($userListings)): ?>
            <p style="color:var(--text-muted); font-size:.9rem;">
              No listings yet.
            </p>
          <?php else: ?>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Image</th>
                    <th>Title</th>
                    <th>Price</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($userListings as $l): ?>
                    <tr>
                      <td>
                        <img src="../<?= htmlspecialchars($l['image_url']
                            ?? 'assets/img/placeholder.png') ?>"
                             class="listing-thumb" alt="">
                      </td>
                      <td style="font-size:.85rem; font-weight:500; max-width:150px;">
                        <?= htmlspecialchars($l['title']) ?>
                      </td>
                      <td style="color:var(--primary); font-weight:600;">
                        R<?= number_format($l['price'], 2) ?>
                      </td>
                      <td>
                        <span class="status-<?= $l['status'] ?>">
                          <?= ucfirst($l['status']) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── ORDERS ── -->
      <div class="col-12 col-6">
        <div class="section-card">
          <h5>🛒 Orders (as Buyer)</h5>
          <?php if (empty($userOrders)): ?>
            <p style="color:var(--text-muted); font-size:.9rem;">
              No orders yet.
            </p>
          <?php else: ?>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th>Amount</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($userOrders as $o): ?>
                    <tr>
                      <td>#<?= $o['order_id'] ?></td>
                      <td style="font-size:.85rem; max-width:150px;">
                        <?= htmlspecialchars($o['listing_title']) ?>
                      </td>
                      <td style="color:var(--primary); font-weight:600;">
                        R<?= number_format($o['total_amount'], 2) ?>
                      </td>
                      <td>
                        <span class="order-<?= $o['status'] ?>">
                          <?= ucfirst($o['status']) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- ── REVIEWS ── -->
    <?php if (!empty($userReviews)): ?>
      <div class="section-card">
        <h5>⭐ Reviews Received</h5>
        <?php foreach ($userReviews as $r): ?>
          <div class="review-item">
            <div style="display:flex; justify-content:space-between;
                        align-items:center; margin-bottom:.4rem;">
              <span style="font-weight:600; font-size:.9rem;">
                <?= htmlspecialchars($r['reviewer_name']) ?>
              </span>
              <div style="display:flex; align-items:center; gap:.5rem;">
                <span class="stars">
                  <?= str_repeat('★', $r['rating']) ?>
                  <?= str_repeat('☆', 5 - $r['rating']) ?>
                </span>
                <span style="font-size:.78rem; color:var(--text-muted);">
                  <?= date('d M Y', strtotime($r['created_at'])) ?>
                </span>
              </div>
            </div>
            <p style="font-size:.88rem; margin:0; color:var(--text);">
              <?= htmlspecialchars($r['comment'] ?? 'No comment left.') ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ── ACCOUNT DETAILS ── -->
    <div class="section-card">
      <h5>📋 Account Details</h5>
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr));
                  gap:1rem;">
        <?php
        $details = [
          'User ID'      => '#' . $user['user_id'],
          'Full Name'    => $user['first_name'] . ' ' . $user['last_name'],
          'Email'        => $user['email'],
          'Phone'        => $user['phone'] ?? 'Not set',
          'Province'     => $user['province'] ?? 'Not set',
          'Role'         => ucfirst($user['role']),
          'Verified'     => $user['is_verified'] ? '✅ Yes' : '❌ No',
          'Status'       => $user['is_active'] ? '✅ Active' : '🚫 Suspended',
          'Joined'       => date('d M Y H:i', strtotime($user['created_at'])),
          'Last Updated' => date('d M Y H:i', strtotime($user['updated_at'])),
        ];
        foreach ($details as $label => $value):
        ?>
          <div style="background:var(--bg); border-radius:var(--radius-sm); padding:.85rem;">
            <div style="font-size:.75rem; color:var(--text-muted); margin-bottom:.25rem;">
              <?= $label ?>
            </div>
            <div style="font-weight:600; font-size:.9rem;">
              <?= htmlspecialchars($value) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </main>
</div>

</body>
</html>