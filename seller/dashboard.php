<?php
session_start();
require_once '../config/db.php';

// Only sellers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header('Location: ../auth/login.php');
    exit;
}

$seller_id = $_SESSION['user_id'];

// Fetch seller stats
$stats = $pdo->prepare('
    SELECT 
        COUNT(DISTINCT l.listing_id) AS total_listings,
        COUNT(DISTINCT CASE WHEN l.status = "active" THEN l.listing_id END) AS active_listings,
        COUNT(DISTINCT CASE WHEN l.status = "sold" THEN l.listing_id END) AS sold_listings,
        COUNT(DISTINCT o.order_id) AS total_orders,
        COALESCE(SUM(CASE WHEN o.status in ("completed", "delivered", "paid", "sold") THEN o.total_amount END), 0) AS total_earnings,
        ROUND(AVG(r.rating), 1) AS avg_rating,
        COUNT(DISTINCT r.review_id) AS review_count
    FROM listings l
    LEFT JOIN orders o ON o.listing_id = l.listing_id
    LEFT JOIN reviews r ON r.seller_id = l.seller_id
    WHERE l.seller_id = :sid
');
$stats->execute([':sid' => $seller_id]);
$s = $stats->fetch();

// Fetch seller listings
$listings = $pdo->prepare('
    SELECT l.*, 
           li.image_url,
           COUNT(DISTINCT o.order_id) AS order_count
    FROM listings l
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    LEFT JOIN orders o ON o.listing_id = l.listing_id
    WHERE l.seller_id = :sid
    GROUP BY l.listing_id
    ORDER BY l.created_at DESC
');
$listings->execute([':sid' => $seller_id]);
$myListings = $listings->fetchAll();

// Fetch recent orders
$orders = $pdo->prepare('
    SELECT o.*, l.title AS listing_title,
           CONCAT(u.first_name," ",u.last_name) AS buyer_name
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users u ON o.buyer_id = u.user_id
    WHERE l.seller_id = :sid
    ORDER BY o.created_at DESC
    LIMIT 10
');
$orders->execute([':sid' => $seller_id]);
$recentOrders = $orders->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seller Dashboard – Electro Trade</title>
  <link href="../assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    .status-active   { color: var(--success); font-weight:600; }
    .status-pending  { color: var(--warning); font-weight:600; }
    .status-sold     { color: var(--primary); font-weight:600; }
    .status-flagged  { color: var(--danger);  font-weight:600; }
    .status-deleted  { color: var(--text-muted); font-weight:600; }

    .order-placed    { color: var(--text-muted); font-weight:600; }
    .order-paid      { color: var(--primary);    font-weight:600; }
    .order-shipped   { color: var(--accent);     font-weight:600; }
    .order-delivered { color: var(--success);    font-weight:600; }
    .order-completed { color: var(--success);    font-weight:600; }
    .order-cancelled { color: var(--danger);     font-weight:600; }

    .listing-thumb {
      width: 48px;
      height: 48px;
      object-fit: cover;
      border-radius: 8px;
    }

    .msg-card {
      background: var(--bg);
      border-radius: var(--radius-sm);
      padding: .85rem 1rem;
      margin-bottom: .6rem;
      border-left: 3px solid var(--primary);
    }

    .tab-buttons {
      display: flex;
      gap: .5rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }

    .tab-btn {
      padding: .5rem 1.2rem;
      border-radius: 20px;
      border: 1.5px solid var(--primary);
      background: transparent;
      color: var(--primary);
      font-family: inherit;
      font-size: .88rem;
      font-weight: 500;
      cursor: pointer;
      transition: all var(--transition);
    }

    .tab-btn.active,
    .tab-btn:hover {
      background: var(--primary);
      color: var(--white);
    }

    .tab-content { display: none; }
    .tab-content.active { display: block; }
  </style>
</head>
<body>

<div class="dashboard-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <a class="sidebar-brand" href="../index.php">⚡ Electro Trade</a>
    <nav class="sidebar-nav">
      <a href="dashboard.php" class="active">🏠 Dashboard</a>
      <a href="add-listing.php">➕ Add Listing</a>
      </a>
      <a href="../index.php">🌐 View Store</a>
      <a href="../auth/logout.php" style="margin-top:auto; color:rgb(255, 255, 255);">
        🚪 Logout
      </a>
    </nav>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <main class="dashboard-content">

    <!-- Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>Welcome back, <?= htmlspecialchars($_SESSION['user_name']) ?>! 👋</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          Here's what's happening with your shop today.
        </p>
      </div>
      <a href="add-listing.php" class="btn btn-primary">➕ Add New Listing</a>
    </div>

    <!-- ── STAT CARDS ── -->
    <div class="stat-cards">
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f0fb;">📦</div>
        <div>
          <div class="stat-value"><?= $s['total_listings'] ?></div>
          <div class="stat-label">Total Listings</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#eafaf1;">✅</div>
        <div>
          <div class="stat-value"><?= $s['active_listings'] ?></div>
          <div class="stat-label">Active Listings</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef9e7;">🛒</div>
        <div>
          <div class="stat-value"><?= $s['total_orders'] ?></div>
          <div class="stat-label">Total Orders</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#eafaf1;">💰</div>
        <div>
          <div class="stat-value">R<?= number_format($s['total_earnings'], 2) ?></div>
          <div class="stat-label">Total Earnings</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef9e7;">⭐</div>
        <div>
          <div class="stat-value"><?= $s['avg_rating'] ?? 'N/A' ?></div>
          <div class="stat-label">Avg Rating (<?= $s['review_count'] ?> reviews)</div>
        </div>
      </div>
    </div>

    <!-- ── TABS ── -->
    <div class="tab-buttons">
      <button class="tab-btn active" onclick="showTab('listings')">📦 My Listings</button>
      <button class="tab-btn" onclick="showTab('orders')">🛒 Recent Orders</button>
    </div>

    <!-- ── TAB: LISTINGS ── -->
    <div id="tab-listings" class="tab-content active">
      <?php if (empty($myListings)): ?>
        <div class="sz-card text-center" style="padding:3rem;">
          <p style="font-size:2rem;">📦</p>
          <p style="color:var(--text-muted);">You have no listings yet.</p>
          <a href="add-listing.php" class="btn btn-primary mt-3">Add Your First Listing</a>
        </div>
      <?php else: ?>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Image</th>
                <th>Title</th>
                <th>Price</th>
                <th>Condition</th>
                <th>Status</th>
                <th>Orders</th>
                <th>Views</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($myListings as $l): ?>
                <tr>
                  <td>
                    <img src="../<?= htmlspecialchars($l['image_url'] ?? 'assets/img/placeholder.png') ?>"
                         class="listing-thumb" alt="">
                  </td>
                  <td style="font-weight:500; max-width:200px;">
                    <?= htmlspecialchars($l['title']) ?>
                  </td>
                  <td>R<?= number_format($l['price'], 2) ?></td>
                  <td><?= ucfirst($l['condition_type']) ?></td>
                  <td>
                    <span class="status-<?= $l['status'] ?>">
                      <?= ucfirst($l['status']) ?>
                    </span>
                  </td>
                  <td><?= $l['order_count'] ?></td>
                  <td><?= number_format($l['views_count']) ?></td>
                  <td>
                    <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                      <a href="../listing.php?id=<?= $l['listing_id'] ?>"
                         class="btn btn-outline btn-sm">View</a>
                      <a href="edit-listing.php?id=<?= $l['listing_id'] ?>"
                         class="btn btn-accent btn-sm">Edit</a>
                      <a href="delete-listing.php?id=<?= $l['listing_id'] ?>"
                         class="btn btn-danger btn-sm"
                         onclick="return confirm('Are you sure you want to delete this listing?')">
                         Delete
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── TAB: ORDERS ── -->
    <div id="tab-orders" class="tab-content">
      <?php if (empty($recentOrders)): ?>
        <div class="sz-card text-center" style="padding:3rem;">
          <p style="font-size:2rem;">🛒</p>
          <p style="color:var(--text-muted);">No orders yet.</p>
        </div>
      <?php else: ?>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Listing</th>
                <th>Buyer</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentOrders as $o): ?>
                <tr>
                  <td>#<?= $o['order_id'] ?></td>
                  <td style="max-width:180px;">
                    <?= htmlspecialchars($o['listing_title']) ?>
                  </td>
                  <td><?= htmlspecialchars($o['buyer_name']) ?></td>
                  <td>R<?= number_format($o['total_amount'], 2) ?></td>
                  <td>
                    <span class="order-<?= $o['status'] ?>">
                      <?= ucfirst($o['status']) ?>
                    </span>
                  </td>
                  <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
</div>

<script>
  function showTab(name) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));

    // Show selected
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
  }
</script>
</body>
</html>