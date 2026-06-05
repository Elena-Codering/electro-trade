<?php
session_start();
require_once '../config/db.php';

$allowedRoles = ['admin', 'finance'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../auth/login.php');
    exit;
}

$role = $_SESSION['role'];

// ── DATE FILTER ──────────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

// ── REVENUE SUMMARY ──────────────────────────────────────
$revenue = $pdo->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN status="completed" THEN total_amount END),0) AS completed,
        COALESCE(SUM(CASE WHEN status="paid"      THEN total_amount END),0) AS paid,
        COALESCE(SUM(CASE WHEN status="cancelled" THEN total_amount END),0) AS cancelled,
        COALESCE(SUM(CASE WHEN status="disputed"  THEN total_amount END),0) AS disputed,
        COUNT(*) AS total_orders,
        COUNT(CASE WHEN status="completed" THEN 1 END) AS completed_orders,
        COUNT(CASE WHEN status="cancelled" THEN 1 END) AS cancelled_orders
    FROM orders
    WHERE DATE(created_at) BETWEEN :from AND :to
');
$revenue->execute([':from' => $dateFrom, ':to' => $dateTo]);
$rev = $revenue->fetch();

// ── USER GROWTH ──────────────────────────────────────────
$userGrowth = $pdo->prepare('
    SELECT DATE(created_at) AS day,
           COUNT(*) AS new_users
    FROM users
    WHERE DATE(created_at) BETWEEN :from AND :to
    GROUP BY DATE(created_at)
    ORDER BY day ASC
');
$userGrowth->execute([':from' => $dateFrom, ':to' => $dateTo]);
$userGrowthData = $userGrowth->fetchAll();

// ── ORDERS PER DAY ────────────────────────────────────────
$ordersPerDay = $pdo->prepare('
    SELECT DATE(created_at) AS day,
           COUNT(*) AS total,
           COALESCE(SUM(total_amount),0) AS revenue
    FROM orders
    WHERE DATE(created_at) BETWEEN :from AND :to
    GROUP BY DATE(created_at)
    ORDER BY day ASC
');
$ordersPerDay->execute([':from' => $dateFrom, ':to' => $dateTo]);
$ordersData = $ordersPerDay->fetchAll();

// ── TOP SELLING CATEGORIES ────────────────────────────────
$topCats = $pdo->prepare('
    SELECT c.name, COUNT(o.order_id) AS total_orders,
           COALESCE(SUM(o.total_amount),0) AS revenue
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN categories c ON l.category_id = c.category_id
    WHERE DATE(o.created_at) BETWEEN :from AND :to
    GROUP BY c.category_id
    ORDER BY total_orders DESC
    LIMIT 8
');
$topCats->execute([':from' => $dateFrom, ':to' => $dateTo]);
$categories = $topCats->fetchAll();

// ── TOP SELLERS ───────────────────────────────────────────
$topSellers = $pdo->prepare('
    SELECT CONCAT(u.first_name," ",u.last_name) AS seller_name,
           u.email,
           COUNT(o.order_id) AS total_sales,
           COALESCE(SUM(o.total_amount),0) AS revenue,
           ROUND(AVG(r.rating),1) AS avg_rating
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users u ON l.seller_id = u.user_id
    LEFT JOIN reviews r ON r.seller_id = u.user_id
    WHERE DATE(o.created_at) BETWEEN :from AND :to
    AND o.status = "completed"
    GROUP BY u.user_id
    ORDER BY revenue DESC
    LIMIT 8
');
$topSellers->execute([':from' => $dateFrom, ':to' => $dateTo]);
$sellers = $topSellers->fetchAll();

// ── TOP BUYERS ────────────────────────────────────────────
$topBuyers = $pdo->prepare('
    SELECT CONCAT(u.first_name," ",u.last_name) AS buyer_name,
           u.email,
           COUNT(o.order_id) AS total_orders,
           COALESCE(SUM(o.total_amount),0) AS total_spent
    FROM orders o
    JOIN users u ON o.buyer_id = u.user_id
    WHERE DATE(o.created_at) BETWEEN :from AND :to
    AND o.status != "cancelled"
    GROUP BY u.user_id
    ORDER BY total_spent DESC
    LIMIT 8
');
$topBuyers->execute([':from' => $dateFrom, ':to' => $dateTo]);
$buyers = $topBuyers->fetchAll();

// ── PREPARE CHART DATA ────────────────────────────────────
$chartLabels  = [];
$chartOrders  = [];
$chartRevenue = [];

foreach ($ordersData as $d) {
    $chartLabels[]  = date('d M', strtotime($d['day']));
    $chartOrders[]  = (int)$d['total'];
    $chartRevenue[] = (float)$d['revenue'];
}

$userLabels = [];
$userCounts = [];
foreach ($userGrowthData as $d) {
    $userLabels[] = date('d M', strtotime($d['day']));
    $userCounts[] = (int)$d['new_users'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports – Electro Trade Admin</title>
  <link href="../assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    .report-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }

    .report-card h5 {
      color: var(--primary);
      margin-bottom: 1.25rem;
      padding-bottom: .5rem;
      border-bottom: 2px solid var(--bg);
    }

    .chart-container {
      position: relative;
      height: 260px;
      width: 100%;
    }

    .export-bar {
      display: flex;
      gap: .75rem;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }

    .progress-bar-wrap {
      background: var(--bg);
      border-radius: 20px;
      height: 10px;
      overflow: hidden;
      margin-top: .3rem;
    }

    .progress-bar-fill {
      height: 100%;
      border-radius: 20px;
      background: var(--primary);
      transition: width .6s ease;
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
      <?php if (in_array($role, ['admin','moderator'])): ?>
        <a href="users.php">👥 Users</a>
        <a href="listings.php">📦 Listings</a>
      <?php endif; ?>
      <?php if (in_array($role, ['admin','support'])): ?>
        <a href="orders.php">🛒 Orders</a>
<!--      <?php endif; ?>
      <a href="reports.php" class="active">📊 Reports</a>
      <?php if ($role === 'admin'): ?>-->
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
        <h2>📊 Reports & Analytics</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          Platform performance overview
        </p>
      </div>
    </div>

    <!-- ── DATE FILTER ── -->
    <div class="report-card" style="padding:1rem;">
      <form method="GET" style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end;">
        <div>
          <label class="form-label">From Date</label>
          <input type="date" name="date_from" class="form-control"
            value="<?= $dateFrom ?>">
        </div>
        <div>
          <label class="form-label">To Date</label>
          <input type="date" name="date_to" class="form-control"
            value="<?= $dateTo ?>">
        </div>
        <button type="submit" class="btn btn-primary">📊 Generate Report</button>
        <a href="reports.php" class="btn btn-outline">Reset</a>
      </form>
    </div>

    <!-- ── EXPORT BUTTONS ── -->
    <div class="export-bar">
      <a href="export.php?type=orders&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
         class="btn btn-success">📥 Export Orders CSV</a>
      <a href="export.php?type=users&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
         class="btn btn-primary">📥 Export Users CSV</a>
      <a href="export.php?type=revenue&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
         class="btn btn-accent">📥 Export Revenue CSV</a>
    </div>

    <!-- ── REVENUE SUMMARY CARDS ── -->
    <div class="stat-cards">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eafaf1;">💰</div>
        <div>
          <div class="stat-value" style="color:var(--success);">
            R<?= number_format($rev['completed'], 2) ?>
          </div>
          <div class="stat-label">Completed Revenue</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f0fb;">🛒</div>
        <div>
          <div class="stat-value"><?= number_format($rev['total_orders']) ?></div>
          <div class="stat-label">Total Orders</div>
          <div style="font-size:.75rem; color:var(--text-muted);">
            <?= $rev['completed_orders'] ?> completed ·
            <?= $rev['cancelled_orders'] ?> cancelled
          </div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef9e7;">⏳</div>
        <div>
          <div class="stat-value" style="color:var(--primary);">
            R<?= number_format($rev['paid'], 2) ?>
          </div>
          <div class="stat-label">Paid (Awaiting Delivery)</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fdf0ef;">⚠️</div>
        <div>
          <div class="stat-value" style="color:var(--danger);">
            R<?= number_format($rev['disputed'], 2) ?>
          </div>
          <div class="stat-label">Disputed Amount</div>
        </div>
      </div>
    </div>

    <!-- ── CHARTS ROW ── -->
    <div class="row">

      <!-- Orders & Revenue Chart -->
      <div class="col-12 col-8">
        <div class="report-card">
          <h5>📈 Daily Orders & Revenue</h5>
          <?php if (empty($ordersData)): ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem 0;">
              No order data for selected period.
            </p>
          <?php else: ?>
            <div class="chart-container">
              <canvas id="ordersChart"></canvas>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- User Growth Chart -->
      <div class="col-12 col-4">
        <div class="report-card">
          <h5>👥 New Users</h5>
          <?php if (empty($userGrowthData)): ?>
            <p style="color:var(--text-muted); text-align:center; padding:2rem 0;">
              No user data for selected period.
            </p>
          <?php else: ?>
            <div class="chart-container">
              <canvas id="usersChart"></canvas>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── TOP CATEGORIES ── -->
    <div class="row">
      <div class="col-12 col-6">
        <div class="report-card">
          <h5>🏷️ Top Categories by Orders</h5>
          <?php if (empty($categories)): ?>
            <p style="color:var(--text-muted);">No data for selected period.</p>
          <?php else: ?>
            <?php
            $maxOrders = max(array_column($categories, 'total_orders'));
            foreach ($categories as $cat):
              $pct = $maxOrders > 0 ? ($cat['total_orders'] / $maxOrders) * 100 : 0;
            ?>
              <div style="margin-bottom:1rem;">
                <div style="display:flex; justify-content:space-between;
                            font-size:.88rem; margin-bottom:.3rem;">
                  <span style="font-weight:500;">
                    <?= htmlspecialchars($cat['name']) ?>
                  </span>
                  <span style="color:var(--text-muted);">
                    <?= $cat['total_orders'] ?> orders ·
                    R<?= number_format($cat['revenue'], 2) ?>
                  </span>
                </div>
                <div class="progress-bar-wrap">
                  <div class="progress-bar-fill"
                       style="width:<?= round($pct) ?>%"></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Top Sellers -->
      <div class="col-12 col-6">
        <div class="report-card">
          <h5>🏆 Top Sellers</h5>
          <?php if (empty($sellers)): ?>
            <p style="color:var(--text-muted);">No data for selected period.</p>
          <?php else: ?>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Seller</th>
                    <th>Sales</th>
                    <th>Revenue</th>
                    <th>Rating</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($sellers as $i => $s): ?>
                    <tr>
                      <td>
                        <div style="font-weight:600; font-size:.85rem;">
                          <?= $i+1 ?>. <?= htmlspecialchars($s['seller_name']) ?>
                        </div>
                        <div style="font-size:.75rem; color:var(--text-muted);">
                          <?= htmlspecialchars($s['email']) ?>
                        </div>
                      </td>
                      <td><?= $s['total_sales'] ?></td>
                      <td style="color:var(--success); font-weight:600;">
                        R<?= number_format($s['revenue'], 2) ?>
                      </td>
                      <td>
                        <?php if ($s['avg_rating']): ?>
                          <span style="color:var(--accent);">★</span>
                          <?= $s['avg_rating'] ?>
                        <?php else: ?>
                          <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
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

    <!-- ── TOP BUYERS ── -->
    <div class="report-card">
      <h5>🛍️ Top Buyers</h5>
      <?php if (empty($buyers)): ?>
        <p style="color:var(--text-muted);">No data for selected period.</p>
      <?php else: ?>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Buyer</th>
                <th>Total Orders</th>
                <th>Total Spent</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($buyers as $i => $b): ?>
                <tr>
                  <td style="font-weight:700; color:var(--primary);">
                    <?= $i+1 ?>
                  </td>
                  <td>
                    <div style="font-weight:600; font-size:.88rem;">
                      <?= htmlspecialchars($b['buyer_name']) ?>
                    </div>
                    <div style="font-size:.75rem; color:var(--text-muted);">
                      <?= htmlspecialchars($b['email']) ?>
                    </div>
                  </td>
                  <td><?= $b['total_orders'] ?></td>
                  <td style="color:var(--primary); font-weight:700;">
                    R<?= number_format($b['total_spent'], 2) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- Chart.js from local script -->
<script>
const primaryColor = '#2F5FA8';
const successColor = '#1B6B3A';
const accentColor  = '#F4A62A';

<?php if (!empty($ordersData)): ?>
// Orders & Revenue Chart
const ordersCtx = document.getElementById('ordersChart').getContext('2d');
new Chart(ordersCtx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      {
        label: 'Orders',
        data: <?= json_encode($chartOrders) ?>,
        backgroundColor: 'rgba(47,95,168,.2)',
        borderColor: primaryColor,
        borderWidth: 2,
        yAxisID: 'y',
      },
      {
        label: 'Revenue (R)',
        data: <?= json_encode($chartRevenue) ?>,
        type: 'line',
        borderColor: successColor,
        backgroundColor: 'rgba(27,107,58,.1)',
        borderWidth: 2,
        fill: true,
        tension: .4,
        yAxisID: 'y1',
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    scales: {
      y:  { beginAtZero: true, position: 'left',
            title: { display: true, text: 'Orders' } },
      y1: { beginAtZero: true, position: 'right',
            grid: { drawOnChartArea: false },
            title: { display: true, text: 'Revenue (R)' } }
    },
    plugins: { legend: { position: 'top' } }
  }
});
<?php endif; ?>

<?php if (!empty($userGrowthData)): ?>
// User Growth Chart
const usersCtx = document.getElementById('usersChart').getContext('2d');
new Chart(usersCtx, {
  type: 'line',
  data: {
    labels: <?= json_encode($userLabels) ?>,
    datasets: [{
      label: 'New Users',
      data: <?= json_encode($userCounts) ?>,
      borderColor: accentColor,
      backgroundColor: 'rgba(244,166,42,.15)',
      borderWidth: 2,
      fill: true,
      tension: .4,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: { y: { beginAtZero: true } },
    plugins: { legend: { position: 'top' } }
  }
});
<?php endif; ?>
</script>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>