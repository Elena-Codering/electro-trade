<?php
session_start();
require_once '../config/db.php';

$allowedRoles = ['admin', 'moderator'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowedRoles)) {
    header('Location: ../auth/login.php');
    exit;
}

$role    = $_SESSION['role'];
$success = '';
$error   = '';

// ── HANDLE ACTIONS ──────────────────────────────────────
// Approve listing
if (isset($_GET['approve'])) {
    $lid = (int)$_GET['approve'];
    $pdo->prepare('UPDATE listings SET status = "active" WHERE listing_id = :id')
        ->execute([':id' => $lid]);
    $success = 'Listing approved successfully.';
}

// Flag listing
if (isset($_GET['flag'])) {
    $lid = (int)$_GET['flag'];
    $pdo->prepare('UPDATE listings SET status = "flagged" WHERE listing_id = :id')
        ->execute([':id' => $lid]);
    $success = 'Listing flagged successfully.';
}

// Delete listing
if (isset($_GET['delete'])) {
    $lid = (int)$_GET['delete'];
    $pdo->prepare('UPDATE listings SET status = "deleted" WHERE listing_id = :id')
        ->execute([':id' => $lid]);
    $success = 'Listing deleted successfully.';
}

// ── SEARCH & FILTER ──────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$filter     = $_GET['filter'] ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

$sql    = '
    SELECT l.*, 
           CONCAT(u.first_name," ",u.last_name) AS seller_name,
           u.email AS seller_email,
           c.name AS category_name,
           li.image_url
    FROM listings l
    JOIN users u ON l.seller_id = u.user_id
    JOIN categories c ON l.category_id = c.category_id
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE 1=1
';
$params = [];

if ($search) {
    $sql .= ' AND (l.title LIKE :s OR u.first_name LIKE :s OR u.last_name LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}
if ($filter) {
    $sql .= ' AND l.status = :status';
    $params[':status'] = $filter;
}

// Count total
$countSql = '
    SELECT COUNT(*) FROM listings l
    JOIN users u ON l.seller_id = u.user_id
    WHERE 1=1' .
    ($search ? ' AND (l.title LIKE :s OR u.first_name LIKE :s OR u.last_name LIKE :s)' : '') .
    ($filter ? ' AND l.status = :status' : '');

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalListings = $countStmt->fetchColumn();
$totalPages    = ceil($totalListings / $perPage);

$sql .= ' ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$listings = $stmt->fetchAll();

// Status counts for filter tabs
$counts = $pdo->query('
    SELECT status, COUNT(*) AS cnt 
    FROM listings 
    GROUP BY status
')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Listings Management – Electro Trade Admin</title>
  <link href="../assets/css/style.css" rel="stylesheet">
  <style>
    .status-active   { color: var(--success); font-weight:600; }
    .status-pending  { color: var(--warning); font-weight:600; }
    .status-sold     { color: var(--primary); font-weight:600; }
    .status-flagged  { color: var(--danger);  font-weight:600; }
    .status-deleted  { color: var(--text-muted); font-weight:600; }

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

    .listing-thumb {
      width: 52px;
      height: 52px;
      object-fit: cover;
      border-radius: 8px;
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
      <a href="listings.php" class="active">📦 Listings</a>
      <?php if (in_array($role, ['admin','support'])): ?>
        <a href="orders.php">🛒 Orders</a>
      <?php endif; ?>
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
        <h2>📦 Listings Management</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          <?= number_format($totalListings) ?> listings found
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

    <!-- ── FILTER TABS ── -->
    <div class="filter-tabs">
      <a href="listings.php" class="filter-tab <?= !$filter ? 'active' : '' ?>">
        All <span class="count"><?= array_sum($counts) ?></span>
      </a>
      <a href="listings.php?filter=active"
         class="filter-tab <?= $filter === 'active' ? 'active' : '' ?>">
        ✅ Active <span class="count"><?= $counts['active'] ?? 0 ?></span>
      </a>
      <a href="listings.php?filter=pending"
         class="filter-tab <?= $filter === 'pending' ? 'active' : '' ?>">
        ⏳ Pending <span class="count"><?= $counts['pending'] ?? 0 ?></span>
      </a>
      <a href="listings.php?filter=flagged"
         class="filter-tab <?= $filter === 'flagged' ? 'active' : '' ?>"
         style="<?= ($counts['flagged'] ?? 0) > 0 ? 'border-color:var(--danger); color:var(--danger);' : '' ?>">
        🚩 Flagged <span class="count"><?= $counts['flagged'] ?? 0 ?></span>
      </a>
      <a href="listings.php?filter=sold"
         class="filter-tab <?= $filter === 'sold' ? 'active' : '' ?>">
        🏷️ Sold <span class="count"><?= $counts['sold'] ?? 0 ?></span>
      </a>
      <a href="listings.php?filter=deleted"
         class="filter-tab <?= $filter === 'deleted' ? 'active' : '' ?>">
        🗑️ Deleted <span class="count"><?= $counts['deleted'] ?? 0 ?></span>
      </a>
    </div>

    <!-- ── SEARCH ── -->
    <div class="sz-card" style="padding:1rem; margin-bottom:1.5rem;">
      <form method="GET" style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <div style="flex:1; min-width:200px;">
          <input type="text" name="search" class="form-control"
            placeholder="Search by title or seller name..."
            value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn btn-primary">🔍 Search</button>
        <a href="listings.php" class="btn btn-outline">Clear</a>
      </form>
    </div>

    <!-- ── LISTINGS TABLE ── -->
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Image</th>
            <th>Title</th>
            <th>Seller</th>
            <th>Category</th>
            <th>Price</th>
            <th>Condition</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($listings)): ?>
            <tr>
              <td colspan="10" style="text-align:center; padding:2rem;
                                      color:var(--text-muted);">
                No listings found.
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($listings as $l): ?>
            <tr>
              <td><?= $l['listing_id'] ?></td>
              <td>
                <img src="../<?= htmlspecialchars($l['image_url'] 
                    ?? 'assets/img/placeholder.png') ?>"
                     class="listing-thumb" alt="">
              </td>
              <td style="max-width:160px;">
                <div style="font-weight:600; font-size:.88rem;">
                  <?= htmlspecialchars($l['title']) ?>
                </div>
                <div style="font-size:.75rem; color:var(--text-muted);">
                  <?= number_format($l['views_count']) ?> views
                </div>
              </td>
              <td>
                <div style="font-size:.85rem; font-weight:500;">
                  <?= htmlspecialchars($l['seller_name']) ?>
                </div>
                <div style="font-size:.75rem; color:var(--text-muted);">
                  <?= htmlspecialchars($l['seller_email']) ?>
                </div>
              </td>
              <td style="font-size:.85rem;">
                <?= htmlspecialchars($l['category_name']) ?>
              </td>
              <td style="font-weight:600; color:var(--primary);">
                R<?= number_format($l['price'], 2) ?>
              </td>
              <td style="font-size:.85rem;">
                <?= ucfirst($l['condition_type']) ?>
              </td>
              <td>
                <span class="status-<?= $l['status'] ?>">
                  <?= ucfirst($l['status']) ?>
                </span>
              </td>
              <td style="font-size:.8rem; color:var(--text-muted);">
                <?= date('d M Y', strtotime($l['created_at'])) ?>
              </td>
              <td>
                <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
                  <a href="../listing.php?id=<?= $l['listing_id'] ?>"
                     target="_blank" class="btn btn-outline btn-sm">👁</a>

                  <?php if ($l['status'] === 'pending' || $l['status'] === 'flagged'): ?>
                    <a href="listings.php?approve=<?= $l['listing_id'] ?>&filter=<?= $filter ?>"
                       class="btn btn-success btn-sm">✅</a>
                  <?php endif; ?>

                  <?php if ($l['status'] === 'active'): ?>
                    <a href="listings.php?flag=<?= $l['listing_id'] ?>&filter=<?= $filter ?>"
                       class="btn btn-accent btn-sm"
                       onclick="return confirm('Flag this listing?')">🚩</a>
                  <?php endif; ?>

                  <?php if ($l['status'] !== 'deleted'): ?>
                    <a href="listings.php?delete=<?= $l['listing_id'] ?>&filter=<?= $filter ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Delete this listing?')">🗑️</a>
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