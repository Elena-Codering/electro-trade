<?php
session_start();
require_once 'config/db.php';

// Must be logged in as buyer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: auth/login.php');
    exit;
}

$buyer_id = $_SESSION['user_id'];
$order_id = (int)($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: buyer/dashboard.php');
    exit;
}

// Fetch order — make sure it belongs to this buyer
$stmt = $pdo->prepare('
    SELECT o.*, l.title AS listing_title, l.listing_id,
           l.seller_id,
           CONCAT(u.first_name," ",u.last_name) AS seller_name
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users u ON l.seller_id = u.user_id
    WHERE o.order_id = :oid AND o.buyer_id = :bid
');
$stmt->execute([':oid' => $order_id, ':bid' => $buyer_id]);
$order = $stmt->fetch();

// Order not found or doesn't belong to buyer
if (!$order) {
    header('Location: buyer/dashboard.php');
    exit;
}

// Only placed orders can be cancelled
if ($order['status'] !== 'placed') {
    $_SESSION['error'] = 'Only orders with status "Placed" can be cancelled.';
    header('Location: buyer/dashboard.php');
    exit;
}

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason'] ?? '');

    // Cancel the order
    $pdo->prepare('
        UPDATE orders SET status = "cancelled" 
        WHERE order_id = :oid AND buyer_id = :bid
    ')->execute([':oid' => $order_id, ':bid' => $buyer_id]);

    // Update payment to failed
    $pdo->prepare('
        UPDATE payments SET status = "failed" 
        WHERE order_id = :oid
    ')->execute([':oid' => $order_id]);

    // Restore quantity and re-activate listing
    $pdo->prepare('
        UPDATE listings 
        SET quantity = quantity + :qty,
            status = "active"
        WHERE listing_id = :lid
    ')->execute([
        ':qty' => $order['quantity'],
        ':lid' => $order['listing_id']
    ]);

    // Notify seller
    $pdo->prepare('
        INSERT INTO notifications (user_id, type, message)
        VALUES (:uid, "order", :msg)
    ')->execute([
        ':uid' => $order['seller_id'],
        ':msg' => "Order #" . $order_id . " for \"" . $order['listing_title'] . "\" was cancelled by the buyer."
        . ($reason ? " Reason: " . $reason : ""),
    ]);

    $success = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cancel Order – Electro Trade</title>
  <link href="assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    .cancel-wrapper {
      min-height: 80vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }

    .cancel-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 2.5rem;
      width: 100%;
      max-width: 520px;
    }

    .order-detail-row {
      display: flex;
      justify-content: space-between;
      padding: .6rem 0;
      font-size: .9rem;
      border-bottom: 1px solid var(--border);
    }

    .order-detail-row:last-child {
      border-bottom: none;
    }

    .warning-box {
      background: #fef9e7;
      border-left: 4px solid var(--warning);
      border-radius: var(--radius-sm);
      padding: 1rem;
      margin: 1.25rem 0;
      font-size: .88rem;
      color: #7d6608;
    }
  </style>
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <div class="container">
    <a class="navbar-brand" href="index.php">⚡ Electro Trade</a>
    <button class="hamburger" onclick="toggleNav()">☰</button>
    <div class="nav-links" id="navMenu">
      <a href="buyer/dashboard.php">My Dashboard</a>
      <a href="auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </div>
</nav>

<div class="cancel-wrapper">
  <div class="cancel-card">

    <?php if ($success): ?>
      <!-- ── SUCCESS ── -->
      <div style="text-align:center;">
        <div style="font-size:3.5rem; margin-bottom:1rem;">✅</div>
        <h3 style="color:var(--success); margin-bottom:.5rem;">
          Order Cancelled
        </h3>
        <p style="color:var(--text-muted); margin-bottom:1.5rem;">
          Order <strong>#<?= $order_id ?></strong> has been cancelled successfully.
          The listing has been made available again for other buyers.
        </p>
        <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">
          <a href="buyer/dashboard.php" class="btn btn-primary">
            📦 My Orders
          </a>
          <a href="index.php" class="btn btn-outline">
            🛍️ Browse Listings
          </a>
        </div>
      </div>

    <?php else: ?>
      <!-- ── CANCEL FORM ── -->
      <div style="text-align:center; margin-bottom:1.5rem;">
        <div style="font-size:3rem;">⚠️</div>
        <h3 style="color:var(--danger);">Cancel Order</h3>
        <p style="color:var(--text-muted); font-size:.9rem;">
          Are you sure you want to cancel this order?
        </p>
      </div>

      <!-- Order Details -->
      <div style="background:var(--bg); border-radius:var(--radius-sm); 
                  padding:1rem; margin-bottom:1.25rem;">
        <div class="order-detail-row">
          <span style="color:var(--text-muted);">Order ID</span>
          <span style="font-weight:600;">#<?= $order_id ?></span>
        </div>
        <div class="order-detail-row">
          <span style="color:var(--text-muted);">Item</span>
          <span style="font-weight:600; max-width:280px; text-align:right;">
            <?= htmlspecialchars($order['listing_title']) ?>
          </span>
        </div>
        <div class="order-detail-row">
          <span style="color:var(--text-muted);">Seller</span>
          <span><?= htmlspecialchars($order['seller_name']) ?></span>
        </div>
        <div class="order-detail-row">
          <span style="color:var(--text-muted);">Amount</span>
          <span style="color:var(--primary); font-weight:700;">
            R<?= number_format($order['total_amount'], 2) ?>
          </span>
        </div>
        <div class="order-detail-row">
          <span style="color:var(--text-muted);">Status</span>
          <span style="color:var(--text-muted); font-weight:600;">
            <?= ucfirst($order['status']) ?>
          </span>
        </div>
        <div class="order-detail-row">
          <span style="color:var(--text-muted);">Date</span>
          <span><?= date('d M Y H:i', strtotime($order['created_at'])) ?></span>
        </div>
      </div>

      <!-- Warning -->
      <div class="warning-box">
        ⚠️ <strong>Please note:</strong> Once cancelled, this action cannot be undone.
        The listing will be made available to other buyers again.
      </div>

      <!-- Cancel Form -->
      <form method="POST">
        <div class="form-group">
          <label class="form-label">
            Reason for cancellation
            <span style="color:var(--text-muted); font-weight:400;">(optional)</span>
          </label>
          <select name="reason" class="form-control">
            <option value="">-- Select a reason --</option>
            <option value="Changed my mind">Changed my mind</option>
            <option value="Found a better price elsewhere">
              Found a better price elsewhere
            </option>
            <option value="Ordered by mistake">Ordered by mistake</option>
            <option value="Seller not responding">Seller not responding</option>
            <option value="Other">Other</option>
          </select>
        </div>

        <div style="display:flex; gap:.75rem; margin-top:1.5rem;">
          <a href="buyer/dashboard.php"
             class="btn btn-outline w-100">
            ← Keep Order
          </a>
          <button type="submit" class="btn btn-danger w-100">
            🗑️ Cancel Order
          </button>
        </div>
      </form>

    <?php endif; ?>

  </div>
</div>

<!-- ── FOOTER ── -->
<footer class="site-footer">
  <div class="container">
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> Electro Trade. All rights reserved.</p>
    </div>
  </div>
</footer>

<script src="assets/js/electrotrade.js?v=20260605"></script>
</body>
</html>