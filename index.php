<?php
session_start();
require_once 'config/db.php';

// Fetch categories
$cats = $pdo->query('SELECT * FROM categories')->fetchAll();

// Fetch latest active listings with a usable image
$keyword    = trim($_GET['q'] ?? '');
$cat_id     = (int)($_GET['cat'] ?? 0);
$condition  = trim($_GET['condition'] ?? '');
$sort       = $_GET['sort'] ?? 'newest';
$sql        = 'SELECT l.*,
                    CONCAT(u.first_name, " ", u.last_name) AS seller_name,
                    (
                      SELECT li.image_url
                      FROM listing_images li
                      WHERE li.listing_id = l.listing_id
                      ORDER BY li.is_primary DESC, li.image_id ASC
                      LIMIT 1
                    ) AS image_url,
                    (
                      SELECT ROUND(AVG(r2.rating), 1)
                      FROM reviews r2
                      WHERE r2.seller_id = l.seller_id
                    ) AS avg_rating
             FROM listings l
             JOIN users u ON l.seller_id = u.user_id
             WHERE l.status = "active"';

$params = [];
if ($keyword) {
    $sql .= ' AND (l.title LIKE ? OR l.description LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
if ($cat_id > 0) {
    $sql .= ' AND l.category_id = ?';
    $params[] = $cat_id;
}
if ($condition !== '') {
    $sql .= ' AND l.condition_type = ?';
    $params[] = $condition;
}

if ($sort === 'price_low') {
    $sql .= ' ORDER BY l.price ASC';
} elseif ($sort === 'price_high') {
    $sql .= ' ORDER BY l.price DESC';
} else {
    $sql .= ' ORDER BY l.created_at DESC';
}
$sql .= ' LIMIT 12';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Electro Trade – Buy & Sell Electronics</title>
  <link href="assets/css/style.css?v=20260605" rel="stylesheet">
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <div class="container">
    <a class="navbar-brand" href="index.php">⚡ Electro Trade</a>
    <button class="hamburger" onclick="toggleNav()" id="hamburgerBtn">☰</button>
    <div class="nav-links" id="navMenu">
      <?php if (isset($_SESSION['user_id'])): ?>
        <span class="nav-greeting">👋 <?= htmlspecialchars($_SESSION['user_name']) ?></span>
        <?php if ($_SESSION['role'] === 'seller'): ?>
          <a href="seller/dashboard.php" class="btn btn-outline-light btn-sm">My Shop</a>
        <?php elseif ($_SESSION['role'] === 'buyer'): ?>
          <a href="buyer/dashboard.php" class="btn btn-outline-light btn-sm">My Orders</a>
        <?php elseif (in_array($_SESSION['role'], ['admin','moderator','support','finance'])): ?>
          <a href="admin/dashboard.php" class="btn btn-outline-light btn-sm">Admin</a>
        <?php endif; ?>
        <a href="auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
      <?php else: ?>
        <a href="auth/login.php">Login</a>
        <a href="auth/register.php" class="btn btn-accent btn-sm">Register</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ── HERO ── -->
<section style="background: linear-gradient(135deg, #c05a15 0%, #3268bb 100%); padding: 4rem 0;">
  <div class="container text-center text-white">
    <h1 class="fw-bold mb-2" style="font-size:2.5rem">
      Buy & Sell Electronics Safely 🇿🇦
    </h1>
    <p class="mb-4 opacity-75">
      South Africa's #1 electronics marketplace — phones, laptops, gaming & more
    </p>
    <!-- Search Bar -->
    <form method="GET" action="index.php" class="d-flex justify-content-center gap-2 flex-wrap" style="margin-bottom: .5rem;">
      <input type="text" name="q" class="form-control" style="max-width:400px; border-radius:8px;"
        placeholder="Search listings..." value="<?= htmlspecialchars($keyword) ?>">
      <select name="cat" class="form-control" style="max-width:180px; border-radius:8px;">
        <option value="0">All Categories</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= $c['category_id'] ?>" <?= $cat_id == $c['category_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="condition" class="form-control" style="max-width:170px; border-radius:8px;">
        <option value="">Any Condition</option>
        <option value="new" <?= $condition === 'new' ? 'selected' : '' ?>>New</option>
        <option value="like_new" <?= $condition === 'like_new' ? 'selected' : '' ?>>Like New</option>
        <option value="good" <?= $condition === 'good' ? 'selected' : '' ?>>Good</option>
        <option value="fair" <?= $condition === 'fair' ? 'selected' : '' ?>>Fair</option>
        <option value="poor" <?= $condition === 'poor' ? 'selected' : '' ?>>Poor</option>
      </select>
      <select name="sort" class="form-control" style="max-width:170px; border-radius:8px;">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
      </select>
      <button class="btn btn-accent text-white px-4">
        <i class="bi bi-search"></i> Search
      </button>
      <a href="index.php" class="btn btn-outline-light px-4">Clear</a>
    </form>
  </div>
</section>

<!-- ── CATEGORY PILLS ── 
<section class="container my-4">
  <div class="d-flex flex-wrap gap-2">
    <a href="index.php" class="btn btn-sm <?= $cat_id == 0 ? 'btn-primary' : 'btn-outline-primary' ?>">
      All
    </a>
    <?php foreach ($cats as $c): ?>
      <a href="index.php?cat=<?= $c['category_id'] ?>"
         class="btn btn-sm <?= $cat_id == $c['category_id'] ? 'btn-primary' : 'btn-outline-primary' ?>">
        <?= htmlspecialchars($c['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
-->

<!-- ── LISTINGS GRID ── -->
<section class="container mb-5" style="margin-top: 2rem;">
  <h5 class="fw-bold mb-3">
    <?= $keyword ? 'Results for "'.htmlspecialchars($keyword).'"' : '✨ Latest Listings' ?>
    <span class="text-muted fw-normal" style="font-size:.9rem">
      (<?= count($listings) ?> found)
    </span>
  </h5>

  <?php if (empty($listings)): ?>
    <div class="text-center py-5 text-muted">
      <i class="bi bi-search" style="font-size:3rem"></i>
      <p class="mt-3">No listings found. 
        <?php if (!isset($_SESSION['user_id'])): ?>
          <a href="auth/register.php">Register as a seller</a> to add the first one!
        <?php endif; ?>
      </p>
    </div>
  <?php else: ?>
    <div class="row" id="listingsGrid">
      <?php foreach ($listings as $l): ?>
        <div class="col-12 col-sm-6 col-lg-4 mb-4">
          <div class="card listing-card h-100">
            <div style="position:relative; overflow:hidden; height:200px;">
              <img src="<?= htmlspecialchars($l['image_url'] ?? 'assets/img/placeholder.png') ?>"
                  class="card-img-top" style="height:200px; object-fit:cover;"
                  alt="<?= htmlspecialchars($l['title']) ?>">
              <span class="badge" style="position:absolute; top:.5rem; right:.5rem;"
                    class="badge-<?= $l['condition_type'] ?>">
                <?= ucfirst($l['condition_type']) ?>
              </span>
              <!-- Wishlist Button -->
              <button class="btn-wishlist"
                      data-listing-id="<?= $l['listing_id'] ?>"
                      style="position:absolute; top:.5rem; left:.5rem;
                            background:rgba(255,255,255,.9);
                            border:none; border-radius:50%;
                            width:36px; height:36px;
                            display:flex; align-items:center;
                            justify-content:center;
                            cursor:pointer; font-size:1.1rem;
                            box-shadow:0 2px 8px rgba(0,0,0,.15);
                            transition: transform .2s ease;"
                      title="Add to Wishlist">
                <span class="wish-icon">🤍</span>
              </button>
            </div>
            <div class="card-body d-flex flex-column">
              <h6 class="card-title fw-semibold"><?= htmlspecialchars($l['title']) ?></h6>
              <p class="price mb-1">R<?= number_format($l['price'], 2) ?></p>
              <div class="d-flex align-items-center gap-2 mt-auto text-muted" style="font-size:.82rem">
                <span><i class="bi bi-person-circle"></i> <?= htmlspecialchars($l['seller_name']) ?></span>
                <?php if ($l['avg_rating']): ?>
                  <span><i class="bi bi-star-fill text-warning"></i> <?= $l['avg_rating'] ?></span>
                <?php endif; ?>
                <span class="ms-auto"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($l['province'] ?? '') ?></span>
              </div>
            </div>
            <div class="card-footer border-0 bg-transparent pb-3">
              <a href="listing.php?id=<?= $l['listing_id'] ?>" class="btn btn-primary btn-sm w-100">
                View Listing
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- ── FOOTER ── -->
<footer class="site-footer">
  <div class="container">
    <div class="row align-items-start">

      <div class="col-md-4 mb-3" style="margin-right:auto; text-align:left;">
        <h5 class="text-white fw-bold">⚡ Electro Trade</h5>
        <p>Join thousands of South Africans buying & selling electronics.</p>
      </div>
      
      <div class="col-md-4 mb-3">
        <h6 class="text-white">Quick Links</h6>
        <ul class="list-unstyled">
          <li><a href="index.php">Home</a></li>
          <li><a href="auth/register.php">Register</a></li>
          <li><a href="auth/login.php">Login</a></li>
        </ul>
      </div>

      <div class="col-md-4 mb-3" style="margin-left:auto; text-align:right;">
        <h6 class="text-white">Contact</h6>
        <p>support@electrotrade.co.za</p>
        <p>South Africa</p>
      </div>

    </div>
    <hr style="border-color:rgba(255,255,255,.2)">
    <p class="text-center mb-0">© <?= date('Y') ?> Electro Trade. All rights reserved.</p>
  </div>
  
</footer>

<script src="assets/js/electrotrade.js?v=20260605"></script>
</body>
</html>