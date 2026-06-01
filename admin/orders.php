<?php
session_start();
require_once '../config/db.php';

$allowedRoles = ['admin', 'support', 'finance'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../auth/login.php');
    exit;
}

$role    = $_SESSION['role'];
$success = '';
$error   = '';

// ── HANDLE STATUS UPDATE ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $oid       = (int)$_POST['order_id'];
    $newStatus = $_POST['new_status'];
    $allowed   = ['placed','paid','shipped','delivered','completed','cancelled','disputed'];

    if (in_array($newStatus, $allowed)) {
        $pdo->prepare('UPDATE orders SET status = :status WHERE order_id = :id')
            ->execute([':status' => $newStatus, ':id' => $oid]);

        // Update payment status if order completed
        if ($newStatus === 'completed') {
            $pdo->prepare('UPDATE payments SET status = "successful" WHERE order_id = :id')
                ->execute([':id' => $oid]);
        }

        // Notify buyer
        $order = $pdo->prepare('SELECT buyer_id FROM orders WHERE order_id = :id');
        $order->execute([':id' => $oid]);
        $orderData = $order->fetch();

        if ($orderData) {
            $pdo->prepare('
                INSERT INTO notifications (user_id, type, message)
                VALUES (:uid, "order", :msg)
            ')->execute([
                ':uid' => $orderData['buyer_id'],
                ':msg' => "Your order #" . $oid . " status has been updated to: " . ucfirst($newStatus),
            ]);
        }

        $success = 'Order #' . $oid . ' status updated to ' . ucfirst($newStatus) . '.';
    }
}

// ── SEARCH & FILTER ──────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$filter  = $_GET['filter'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$sql = '
    SELECT o.*,
           l.title AS listing_title,
           l.listing_id,
           li.image_url,
           CONCAT(b.first_name," ",b.last_name) AS buyer_name,
           b.email AS buyer_email,
           CONCAT(s.first_name," ",s.last_name) AS seller_name,
           p.gateway, p.status AS payment_status
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users b ON o.buyer_id = b.user_id
    JOIN users s ON l.seller_id = s.user_id
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    LEFT JOIN payments p ON p.order_id = o.order_id
    WHERE 1=1
';
$params = [];

if ($search) {
    $sql .= ' AND (l.title LIKE :s OR b.first_name LIKE :s 
              OR b.last_name LIKE :s OR b.email LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}
if ($filter) {
    $sql .= ' AND o.status = :status';
    $params[':status'] = $filter;
}

// Count
$countSql = '
    SELECT COUNT(*) FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users b ON o.buyer_id = b.user_id
    WHERE 1=1' .
    ($search ? ' AND (l.title LIKE :s OR b.first_name LIKE :s 
                OR b.last_name LIKE :s OR b.email LIKE :s)' : '') .
    ($filter ? ' AND o.status = :status' : '');

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalOrders = $countStmt->fetchColumn();
$totalPages  = ceil($totalOrders / $perPage);

$sql .= ' ORDER BY o.created_at DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

// Status counts
$counts = $pdo->query('
    SELECT status, COUNT(*) AS cnt 
    FROM orders GROUP BY status
')->fetchAll(PDO::FETCH_KEY_PAIR);

// Revenue stats
$revenue = $pdo->query('
    SELECT 
        COALESCE(SUM(CASE WHEN status="completed" THEN total_amount END),0) AS completed,
        COALESCE(SUM(CASE WHEN status="paid" THEN total_amount END),0) AS paid,
        COALESCE(SUM(CASE WHEN status="disputed" THEN total_amount END),0) AS disputed
    FROM orders
')->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Orders Management – Electro Trade Admin</title>
  <link href="../assets/css/style.css" rel="stylesheet">
  <style>
    .filter-tabs {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }

    .filter-tab {
      padding: .45rem 1rem;
      border-radius: 20px;
      font-size: .85rem;
      font-weight: 500;
      border: 1.5px solid var(--border);
      color: var(--text-muted);
      text-decoration: none;
      transition: all var(--transition);
    }

    .filter-tab:hover {
      border-color: var(--primary);
      color: var(--primary);
      text-decoration: none;
    }

    .filter-tab.active {
      background: var(--primary);
      border-color: var(--primary);
      color: var(--white);
    }

    .filter-tab .count {
      background: rgba(0,0,0,.1);
      border-radius: 20px;
      padding: 0 6px;
      font-size: .75rem;
      margin-left: .3rem;
    }

    .order-thumb {
      width: 48px;
      height: 48px;
      object-fit: cover;
      border-radius: 8px;
    }

    .payment-badge {
      display: inline-block;
      padding: .2rem .5rem;
      border-radius: 6px;
      font-size: .72rem;
      font-weight: 600;
    }

    .pay-successful { background:#eafaf1; color:var(--success); }
    .pay-pending    { background:#fef9e7; color:#b7770d; }
    .pay-failed     { background:#fdf0ef; color:var(--danger); }
    .pay-refunded   { background:#e8f0fb; color:var(--primary); }

    .status-placed    { color:var(--text-muted); font-weight:600; }
    .status-paid      { color:var(--primary);    font-weight:600; }
    .status-shipped   { color:#b7770d;            font-weight:600; }
    .status-delivered { color:var(--success);     font-weight:600; }
    .status-completed { color:var(--success);     font-weight:600; }
    .status-cancelled { color:var(--danger);      font-weight:600; }
    .status-disputed  { color:var(--danger);      font-weight:600; }
  </style>
</head>
<body>

<div class="dashboard-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <span class="sidebar-brand">⚡ Electro Trade Admin</span>
    <nav class="sidebar-nav">
      <a href="dashboard.php">🏠 Dashboard</a>
      <?php if (in_array($role, ['admin','moderator'])): ?>
        <a href="users.php">👥 Users</a>
        <a href="listings.php">📦 Listings</a>
      <?php endif; ?>
      <a href="orders.php" class="active">🛒 Orders</a>
      <?php if (in_array($role, ['admin','finance'])): ?>
        <a href="reports.php">📊 Reports</a>
      <?php endif; ?>
      <?php if ($role === 'admin'): ?>
        <a href="roles.php">🔐 Roles</a>
        <a href="settings.php">⚙️ Settings</a>
      <?php endif; ?>
      <a href="../index.php">🌐 View Site</a>
      <a href="../auth/logout.php" style="color:rgba(255,255,255,.6);">🚪 Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN ── -->
  <main class="dashboard-content">

    <div style="display:flex; justify-content:space-between; align-items:center;
                margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>🛒 Orders Management</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          <?= number_format($totalOrders) ?> orders found
        </p>
      </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── REVENUE STATS ── -->
    <div class="stat-cards" style="margin-bottom:1.5rem;">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eafaf1;">💰</div>
        <div>
          <div class="stat-value" style="color:var(--success);">
            R<?= number_format($revenue['completed'], 2) ?>
          </div>
          <div class="stat-label">Completed Revenue</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f0fb;">⏳</div>
        <div>
          <div class="stat-value" style="color:var(--primary);">
            R<?= number_format($revenue['paid'], 2) ?>
          </div>
          <div class="stat-label">Paid (Pending Delivery)</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fdf0ef;">⚠️</div>
        <div>
          <div class="stat-value" style="color:var(--danger);">
            R<?= number_format($revenue['disputed'], 2) ?>
          </div>
          <div class="stat-label">Disputed Amount</div>
        </div>
      </div>
    </div>

    <!-- ── FILTER TABS ── -->
    <div class="filter-tabs">
      <a href="orders.php" class="filter-tab <?= !$filter ? 'active' : '' ?>">
        All <span class="count"><?= array_sum($counts) ?></span>
      </a>
      <?php
      $tabConfig = [
        'placed'    => '📋 Placed',
        'paid'      => '💳 Paid',
        'shipped'   => '📦 Shipped',
        'delivered' => '🚚 Delivered',
        'completed' => '✅ Completed',
        'cancelled' => '❌ Cancelled',
        'disputed'  => '⚠️ Disputed',
      ];
      foreach ($tabConfig as $status => $label):
      ?>
        <a href="orders.php?filter=<?= $status ?>"
           class="filter-tab <?= $filter === $status ? 'active' : '' ?>"
           <?= $status === 'disputed' && ($counts['disputed'] ?? 0) > 0
               ? 'style="border-color:var(--danger);color:var(--danger);"' : '' ?>>
          <?= $label ?>
          <span class="count"><?= $counts[$status] ?? 0 ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- ── SEARCH ── -->
    <div class="sz-card" style="padding:1rem; margin-bottom:1.5rem;">
      <form method="GET" style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <div style="flex:1; min-width:200px;">
          <input type="text" name="search" class="form-control"
            placeholder="Search by item title or buyer name..."
            value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn btn-primary">🔍 Search</button>
        <a href="orders.php" class="btn btn-outline">Clear</a>
      </form>
    </div>

    <!-- ── ORDERS TABLE ── -->
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Item</th>
            <th>Buyer</th>
            <th>Seller</th>
            <th>Amount</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Date</th>
            <th>Update Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
            <tr>
              <td colspan="9" style="text-align:center; padding:2rem;
                                     color:var(--text-muted);">
                No orders found.
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td style="font-weight:600;">#<?= $o['order_id'] ?></td>
              <td>
                <div style="display:flex; align-items:center; gap:.6rem;">
                  <img src="../<?= htmlspecialchars($o['image_url']
                      ?? 'assets/img/placeholder.png') ?>"
                       class="order-thumb" alt="">
                  <div>
                    <div style="font-size:.85rem; font-weight:600; max-width:130px;">
                      <?= htmlspecialchars($o['listing_title']) ?>
                    </div>
                    <div style="font-size:.75rem; color:var(--text-muted);">
                      Qty: <?= $o['quantity'] ?>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <div style="font-size:.85rem; font-weight:500;">
                  <?= htmlspecialchars($o['buyer_name']) ?>
                </div>
                <div style="font-size:.75rem; color:var(--text-muted);">
                  <?= htmlspecialchars($o['buyer_email']) ?>
                </div>
              </td>
              <td style="font-size:.85rem;">
                <?= htmlspecialchars($o['seller_name']) ?>
              </td>
              <td style="font-weight:700; color:var(--primary);">
                R<?= number_format($o['total_amount'], 2) ?>
              </td>
              <td>
                <div style="font-size:.8rem; color:var(--text-muted); margin-bottom:.2rem;">
                  <?= ucfirst($o['gateway'] ?? 'N/A') ?>
                </div>
                <span class="payment-badge pay-<?= $o['payment_status'] ?? 'pending' ?>">
                  <?= ucfirst($o['payment_status'] ?? 'Pending') ?>
                </span>
              </td>
              <td>
                <span class="status-<?= $o['status'] ?>">
                  <?= ucfirst($o['status']) ?>
                </span>
              </td>
              <td style="font-size:.8rem; color:var(--text-muted);">
                <?= date('d M Y', strtotime($o['created_at'])) ?><br>
                <span style="font-size:.72rem;">
                  <?= date('H:i', strtotime($o['created_at'])) ?>
                </span>
              </td>
              <td>
                <form method="POST" style="display:flex; gap:.4rem; align-items:center;">
                  <input type="hidden" name="order_id" value="<?= $o['order_id'] ?>">
                  <select name="new_status" class="form-control"
                          style="padding:.3rem .5rem; font-size:.8rem; width:auto;">
                    <?php
                    $statuses = ['placed','paid','shipped','delivered',
                                 'completed','cancelled','disputed'];
                    foreach ($statuses as $st):
                    ?>
                      <option value="<?= $st ?>"
                        <?= $o['status'] === $st ? 'selected' : '' ?>>
                        <?= ucfirst($st) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" name="update_status"
                          class="btn btn-primary btn-sm">
                    Save
                  </button>
                </form>
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
          <a href="?page=<?= $page-1 ?>&filter=<?= $filter ?>&search=<?= urlencode($search) ?>">
            ← Prev
          </a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
          <?php else: ?>
            <a href="?page=<?= $i ?>&filter=<?= $filter ?>&search=<?= urlencode($search) ?>">
              <?= $i ?>
            </a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=<?= $page+1 ?>&filter=<?= $filter ?>&search=<?= urlencode($search) ?>">
            Next →
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

</body>
</html>