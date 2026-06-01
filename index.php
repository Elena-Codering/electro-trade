<?php
session_start();
require_once 'config/db.php';

// Fetch categories
$cats = $pdo->query('SELECT * FROM categories')->fetchAll();

// Fetch latest active listings with primary image
$keyword  = trim($_GET['q'] ?? '');
$cat_id   = (int)($_GET['cat'] ?? 0);
$sql      = 'SELECT l.*, li.image_url, 
                    CONCAT(u.first_name," ",u.last_name) AS seller_name,
                    ROUND(AVG(r.rating),1) AS avg_rating
             FROM listings l
             JOIN users u ON l.seller_id = u.user_id
             LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
             LEFT JOIN reviews r ON r.seller_id = l.seller_id
             WHERE l.status = "active"';

$params = [];
if ($keyword) {
    $sql .= ' AND (l.title LIKE :kw OR l.description LIKE :kw)';
    $params[':kw'] = '%' . $keyword . '%';
}
if ($cat_id > 0) {
    $sql .= ' AND l.category_id = :cat';
    $params[':cat'] = $cat_id;
}
$sql .= ' GROUP BY l.listing_id ORDER BY l.created_at DESC LIMIT 12';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listings = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Electro Trade – Buy & Sell Anything</title>
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar navbar-expand-lg navbar">
  <div class="container">
    <a class="navbar-brand" href="index.php">⚡ Electro Trade</a>
	<button class="hamburger" onclick="toggleNav()" id="hamburgerBtn">☰</button>
	<div class="nav-links" id="navMenu">
      <ul class="navbar-nav ms-auto align-items-center gap-2">
        <?php if (isset($_SESSION['user_id'])): ?>
          <li class="nav-item">
            <span class="nav-link text-white">
              👋 <?= htmlspecialchars($_SESSION['user_name']) ?>
            </span>
          </li>
          <?php if ($_SESSION['role'] === 'seller'): ?>
            <li class="nav-item">
              <a class="nav-link" href="seller/dashboard.php">
                <i class="bi bi-shop"></i> My Shop
              </a>
            </li>
          <?php elseif ($_SESSION['role'] === 'buyer'): ?>
            <li class="nav-item">
              <a class="nav-link" href="buyer/dashboard.php">
                <i class="bi bi-bag"></i> My Orders
              </a>
            </li>
          <?php elseif (in_array($_SESSION['role'], ['admin','moderator','support','finance'])): ?>
            <li class="nav-item">
              <a class="nav-link" href="admin/dashboard.php">
                <i class="bi bi-shield"></i> Admin
              </a>
            </li>
          <?php endif; ?>
          <li class="nav-item">
            <a class="btn btn-sm btn-outline-light" href="auth/logout.php">Logout</a>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link" href="auth/login.php">Login</a>
          </li>
          <li class="nav-item">
            <a class="btn btn-sm btn-accent text-white" href="auth/register.php">Register</a>
          </li>
        <?php endif; ?>
      </ul>
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
    <form method="GET" action="index.php" class="d-flex justify-content-center gap-2 flex-wrap">
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
      <button class="btn btn-accent text-white px-4">
        <i class="bi bi-search"></i> Search
      </button>
    </form>
  </div>
</section>

<!-- ── CATEGORY PILLS ── -->
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

<!-- ── LISTINGS GRID ── -->
<section class="container mb-5">
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

<script src="assets/js/electrotrade.js"></script>
</body>
</html>