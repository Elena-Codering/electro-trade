<?php
session_start();
require_once '../config/db.php';

// Only buyers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: ../auth/login.php');
    exit;
}

$buyer_id = $_SESSION['user_id'];

// Fetch buyer stats
$stats = $pdo->prepare('
    SELECT
        COUNT(DISTINCT CASE WHEN o.status != "cancelled" 
              THEN o.order_id END) AS total_orders,
        COUNT(DISTINCT CASE WHEN o.status = "completed" 
              THEN o.order_id END) AS completed_orders,
        COUNT(DISTINCT CASE WHEN o.status IN ("placed","paid","shipped") 
              THEN o.order_id END) AS active_orders,
        COUNT(DISTINCT CASE WHEN o.status = "cancelled" 
              THEN o.order_id END) AS cancelled_orders,
        COALESCE(SUM(CASE WHEN o.status NOT IN ("cancelled","disputed") 
              THEN o.total_amount END), 0) AS total_spent,
        COUNT(DISTINCT w.wishlist_id) AS wishlist_count
    FROM users u
    LEFT JOIN orders o ON o.buyer_id = u.user_id
    LEFT JOIN wishlist w ON w.user_id = u.user_id
    WHERE u.user_id = :uid
');
$stats->execute([':uid' => $buyer_id]);
$s = $stats->fetch();

// Fetch orders
$orders = $pdo->prepare('
    SELECT o.*, l.title AS listing_title,
           li.image_url,
           CONCAT(u.first_name," ",u.last_name) AS seller_name
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users u ON l.seller_id = u.user_id
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE o.buyer_id = :uid
    ORDER BY o.created_at DESC
');
$orders->execute([':uid' => $buyer_id]);
$myOrders = $orders->fetchAll();

// Fetch wishlist
$wishlist = $pdo->prepare('
    SELECT w.*, l.title, l.price, l.status AS listing_status,
           li.image_url,
           CONCAT(u.first_name," ",u.last_name) AS seller_name
    FROM wishlist w
    JOIN listings l ON w.listing_id = l.listing_id
    JOIN users u ON l.seller_id = u.user_id
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE w.user_id = :uid
    ORDER BY w.added_at DESC
');
$wishlist->execute([':uid' => $buyer_id]);
$myWishlist = $wishlist->fetchAll();

// ── HANDLE PROFILE UPDATE ────────────────────────────────
$profileSuccess = '';
$profileError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fn    = trim($_POST['first_name']);
    $ln    = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $prov  = $_POST['province'];
    $email = trim($_POST['email']);

    if (empty($fn) || empty($ln)) {
        $profileError = 'First and last name are required.';
    } elseif (empty($email)) {
        $profileError = 'Email is required.';
    } else {
        // Check email not taken by another user
        $emailCheck = $pdo->prepare('
            SELECT user_id FROM users 
            WHERE email = :email AND user_id != :uid
        ');
        $emailCheck->execute([':email' => $email, ':uid' => $buyer_id]);

        if ($emailCheck->fetch()) {
            $profileError = 'That email is already used by another account.';
        } else {
            $pdo->prepare('
                UPDATE users 
                SET first_name = :fn,
                    last_name  = :ln,
                    phone      = :phone,
                    province   = :prov,
                    email      = :email
                WHERE user_id  = :uid
            ')->execute([
                ':fn'    => $fn,
                ':ln'    => $ln,
                ':phone' => $phone,
                ':prov'  => $prov,
                ':email' => $email,
                ':uid'   => $buyer_id,
            ]);

            // Update session name
            $_SESSION['user_name'] = $fn;
            $profileSuccess = 'Profile updated successfully!';
        }
    }
}

// Fetch buyer profile
$profile = $pdo->prepare('SELECT * FROM users WHERE user_id = :uid');
$profile->execute([':uid' => $buyer_id]);
$user = $profile->fetch();

// Handle remove from wishlist
if (isset($_GET['remove_wishlist'])) {
    $wid = (int)$_GET['remove_wishlist'];
    $pdo->prepare('DELETE FROM wishlist WHERE wishlist_id = :id AND user_id = :uid')
        ->execute([':id' => $wid, ':uid' => $buyer_id]);
    header('Location: dashboard.php#wishlist');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Dashboard – Electro Trade</title>
  <link href="../assets/css/style.css" rel="stylesheet">
  <style>
    .order-status {
      display: inline-block;
      padding: .25rem .7rem;
      border-radius: 20px;
      font-size: .78rem;
      font-weight: 600;
    }

    .status-placed    { background:#f0f0f0;   color:#555; }
    .status-paid      { background:#e8f0fb;   color:var(--primary); }
    .status-shipped   { background:#fef9e7;   color:#b7770d; }
    .status-delivered { background:#eafaf1;   color:var(--success); }
    .status-completed { background:#eafaf1;   color:var(--success); }
    .status-cancelled { background:#fdf0ef;   color:var(--danger); }
    .status-disputed  { background:#fdf0ef;   color:var(--danger); }

    .order-thumb {
      width: 52px;
      height: 52px;
      object-fit: cover;
      border-radius: 8px;
      flex-shrink: 0;
    }

    .wishlist-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 1.25rem;
    }

    .wishlist-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      transition: transform var(--transition);
    }

    .wishlist-card:hover { transform: translateY(-3px); }

    .wishlist-card img {
      width: 100%;
      height: 150px;
      object-fit: cover;
    }

    .wishlist-card .wc-body {
      padding: .85rem;
    }

    .wishlist-card .wc-title {
      font-size: .88rem;
      font-weight: 600;
      margin-bottom: .3rem;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .wishlist-card .wc-price {
      color: var(--primary);
      font-weight: 700;
      margin-bottom: .5rem;
    }

    .wishlist-card .wc-actions {
      display: flex;
      gap: .5rem;
    }

    .profile-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      text-align: center;
      margin-bottom: 1.5rem;
    }

    .profile-avatar {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: var(--primary);
      color: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      font-weight: 700;
      margin: 0 auto 1rem;
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

    .progress-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 1rem 0;
      position: relative;
    }

    .progress-bar::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 0;
      right: 0;
      height: 3px;
      background: var(--border);
      z-index: 0;
      transform: translateY(-50%);
    }

    .progress-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .3rem;
      z-index: 1;
    }

    .progress-dot {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: var(--border);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .75rem;
      color: var(--white);
      font-weight: 700;
    }

    .progress-dot.done { background: var(--success); }
    .progress-dot.active { background: var(--primary); }

    .progress-label {
      font-size: .7rem;
      color: var(--text-muted);
      white-space: nowrap;
    }
  </style>
</head>
<body>

<div class="dashboard-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <span class="sidebar-brand">⚡ Electro Trade</span>
    <nav class="sidebar-nav">
      <a href="dashboard.php" class="active">🏠 Dashboard</a>
      <a href="dashboard.php#orders">🛒 My Orders</a>
      <a href="dashboard.php#wishlist">❤️ Wishlist
        <?php if ($s['wishlist_count'] > 0): ?>
          <span style="background:var(--danger); color:#fff; border-radius:50%;
                       padding:0 6px; font-size:.75rem; margin-left:auto;">
            <?= $s['wishlist_count'] ?>
          </span>
        <?php endif; ?>
      </a>
      <a href="dashboard.php#profile">👤 My Profile</a>
      <a href="../index.php">🌐 Browse Listings</a>
      <a href="../auth/logout.php" style="color:rgb(255, 255, 255);">
        🚪 Logout
      </a>
    </nav>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <main class="dashboard-content">

    <!-- Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; 
                margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?>! 👋</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          Here's your buying activity at a glance.
        </p>
      </div>
      <a href="../index.php" class="btn btn-primary">🛍️ Browse Listings</a>
    </div>

    <!-- ── STAT CARDS ── -->
    <div class="stat-cards">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fdf0ef;">❌</div>
        <div>
          <div class="stat-value"><?= $s['cancelled_orders'] ?></div>
          <div class="stat-label">Cancelled Orders</div>
        </div>
        </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef9e7;">📦</div>
        <div>
          <div class="stat-value"><?= $s['active_orders'] ?></div>
          <div class="stat-label">Active Orders</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#eafaf1;">✅</div>
        <div>
          <div class="stat-value"><?= $s['completed_orders'] ?></div>
          <div class="stat-label">Completed Orders</div>
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
        <div class="stat-icon" style="background:#fdf0ef;">❤️</div>
        <div>
          <div class="stat-value"><?= $s['wishlist_count'] ?></div>
          <div class="stat-label">Saved Items</div>
        </div>
      </div>
    </div>

    <!-- ── TABS ── -->
    <div class="tab-buttons">
      <button class="tab-btn active" onclick="showTab('orders', this)">
        🛒 My Orders
      </button>
      <button class="tab-btn" onclick="showTab('wishlist', this)">
        ❤️ Wishlist (<?= $s['wishlist_count'] ?>)
      </button>
      <button class="tab-btn" onclick="showTab('profile', this)">
        👤 My Profile
      </button>
    </div>

    <!-- ── TAB: ORDERS ── -->
    <div id="tab-orders" class="tab-content active">
      <?php if (empty($myOrders)): ?>
        <div class="sz-card text-center" style="padding:3rem;">
          <p style="font-size:2.5rem;">🛒</p>
          <p style="color:var(--text-muted); margin-bottom:1rem;">
            You haven't placed any orders yet.
          </p>
          <a href="../index.php" class="btn btn-primary">Browse Listings</a>
        </div>
      <?php else: ?>
        <?php foreach ($myOrders as $o): ?>
          <div class="sz-card" style="margin-bottom:1rem;">
            <div style="display:flex; gap:1rem; align-items:flex-start; flex-wrap:wrap;">

              <!-- Image -->
              <img src="../<?= htmlspecialchars($o['image_url'] ?? 'assets/img/placeholder.png') ?>"
                   class="order-thumb" alt="">

              <!-- Info -->
              <div style="flex:1; min-width:200px;">
                <div style="display:flex; justify-content:space-between; 
                            align-items:flex-start; flex-wrap:wrap; gap:.5rem;">
                  <div>
                    <div style="font-weight:600; margin-bottom:.2rem;">
                      <?= htmlspecialchars($o['listing_title']) ?>
                    </div>
                    <div style="font-size:.82rem; color:var(--text-muted);">
                      Seller: <?= htmlspecialchars($o['seller_name']) ?>
                      &nbsp;|&nbsp; Order #<?= $o['order_id'] ?>
                      &nbsp;|&nbsp; <?= date('d M Y', strtotime($o['created_at'])) ?>
                    </div>
                  </div>
                  <div style="text-align:right;">
                    <div style="font-weight:700; color:var(--primary); font-size:1.05rem;">
                      R<?= number_format($o['total_amount'], 2) ?>
                    </div>
                    <span class="order-status status-<?= $o['status'] ?>">
                      <?= ucfirst($o['status']) ?>
                    </span>
                  </div>
                </div>

                <!-- Order Progress Bar -->
                <?php
                  $steps = ['placed','paid','shipped','delivered','completed'];
                  $currentStep = array_search($o['status'], $steps);
                  if ($currentStep !== false):
                ?>
                <div class="progress-bar" style="margin-top:1rem;">
                  <?php foreach ($steps as $i => $step): ?>
                    <div class="progress-step">
                      <div class="progress-dot 
                        <?= $i < $currentStep ? 'done' : ($i === $currentStep ? 'active' : '') ?>">
                        <?= $i < $currentStep ? '✓' : ($i + 1) ?>
                      </div>
                      <span class="progress-label"><?= ucfirst($step) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Actions -->
                <div style="display:flex; gap:.5rem; margin-top:.75rem; flex-wrap:wrap;">
                  <a href="../listing.php?id=<?= $o['listing_id'] ?>"
                     class="btn btn-outline btn-sm">View Listing</a>
                  <?php if ($o['status'] === 'completed'): ?>
                    <a href="../review.php?order=<?= $o['order_id'] ?>"
                       class="btn btn-accent btn-sm">⭐ Leave Review</a>
                  <?php endif; ?>
                  <?php if ($o['status'] === 'placed'): ?>
                    <a href="../cancel-order.php?id=<?= $o['order_id'] ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Cancel this order?')">Cancel</a>
                  <?php endif; ?>
                </div>
              </div>

            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ── TAB: WISHLIST ── -->
    <div id="tab-wishlist" class="tab-content">
      <?php if (empty($myWishlist)): ?>
        <div class="sz-card text-center" style="padding:3rem;">
          <p style="font-size:2.5rem;">❤️</p>
          <p style="color:var(--text-muted); margin-bottom:1rem;">
            Your wishlist is empty.
          </p>
          <a href="../index.php" class="btn btn-primary">Browse Listings</a>
        </div>
      <?php else: ?>
        <div class="wishlist-grid">
          <?php foreach ($myWishlist as $w): ?>
            <div class="wishlist-card">
              <a href="../listing.php?id=<?= $w['listing_id'] ?>">
                <img src="../<?= htmlspecialchars($w['image_url'] ?? 'assets/img/placeholder.png') ?>"
                     alt="<?= htmlspecialchars($w['title']) ?>">
              </a>
              <div class="wc-body">
                <div class="wc-title"><?= htmlspecialchars($w['title']) ?></div>
                <div class="wc-price">R<?= number_format($w['price'], 2) ?></div>
                <div style="font-size:.78rem; color:var(--text-muted); margin-bottom:.5rem;">
                  <?= htmlspecialchars($w['seller_name']) ?>
                  <?php if ($w['listing_status'] === 'sold'): ?>
                    <span style="color:var(--danger); font-weight:600;"> · SOLD</span>
                  <?php endif; ?>
                </div>
                <div class="wc-actions">
                  <a href="../listing.php?id=<?= $w['listing_id'] ?>"
                     class="btn btn-primary btn-sm" style="flex:1;">View</a>
                  <a href="dashboard.php?remove_wishlist=<?= $w['wishlist_id'] ?>"
                     class="btn btn-danger btn-sm"
                     onclick="return confirm('Remove from wishlist?')">✕</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── TAB: PROFILE ── -->
    <div id="tab-profile" class="tab-content">
      <div class="row">
        <div class="col-12 col-4">
          <div class="profile-card">
            <div class="profile-avatar">
              <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
            </div>
            <h4><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></h4>
            <p style="color:var(--text-muted); font-size:.88rem;">
              <?= htmlspecialchars($user['email']) ?>
            </p>
            <p style="font-size:.82rem; color:var(--text-muted);">
              📍 <?= htmlspecialchars($user['province'] ?? 'Province not set') ?><br>
              📅 Member since <?= date('M Y', strtotime($user['created_at'])) ?>
            </p>
          </div>
        </div>

        <div class="col-12 col-8">
          <div class="sz-card">
            <h5 style="color:var(--primary); margin-bottom:1.25rem;">
              ✏️ Edit Profile
            </h5>
            <?php
            $profileSuccess = '';
            $profileError   = '';
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
                $fn    = trim($_POST['first_name']);
                $ln    = trim($_POST['last_name']);
                $phone = trim($_POST['phone']);
                $prov  = $_POST['province'];

                if (empty($fn) || empty($ln)) {
                    $profileError = 'First and last name are required.';
                } else {
                    $pdo->prepare('
                        UPDATE users SET first_name=:fn, last_name=:ln, 
                               phone=:phone, province=:prov 
                        WHERE user_id=:uid
                    ')->execute([
                        ':fn' => $fn, ':ln' => $ln,
                        ':phone' => $phone, ':prov' => $prov,
                        ':uid' => $buyer_id
                    ]);
                    $_SESSION['user_name'] = $fn;
                    $profileSuccess = 'Profile updated successfully!';
                    // Refresh user data
                    $profile->execute([':uid' => $buyer_id]);
                    $user = $profile->fetch();
                }
            }
            ?>
            <?php if ($profileSuccess): ?>
              <div class="alert alert-success">✅ <?= $profileSuccess ?></div>
            <?php endif; ?>
            <?php if ($profileError): ?>
              <div class="alert alert-danger">⚠️ <?= $profileError ?></div>
            <?php endif; ?>

            <form method="POST">
              <div class="row">
                <div class="col-12 col-6">
                  <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control"
                      value="<?= htmlspecialchars($user['first_name']) ?>" required>
                  </div>
                </div>
                <div class="col-12 col-6">
                  <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control"
                      value="<?= htmlspecialchars($user['last_name']) ?>" required>
                  </div>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-control"
                  value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Province</label>
                <select name="province" class="form-control">
                  <option value="">-- Select Province --</option>
                  <?php
                  $provinces = ['Gauteng','Western Cape','KwaZulu-Natal','Eastern Cape',
                                'Limpopo','Mpumalanga','North West','Free State','Northern Cape'];
                  foreach ($provinces as $prov):
                  ?>
                    <option value="<?= $prov ?>"
                      <?= ($user['province'] ?? '') === $prov ? 'selected' : '' ?>>
                      <?= $prov ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" name="update_profile" class="btn btn-primary">
                💾 Save Changes
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

<script>
  function showTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
  }
</script>
</body>
</html>