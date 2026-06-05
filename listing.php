<?php
session_start();
require_once 'config/db.php';

$listing_id = (int)($_GET['id'] ?? 0);
if (!$listing_id) {
    header('Location: index.php');
    exit;
}

// Fetch listing with seller info
$stmt = $pdo->prepare('
    SELECT l.*, 
           u.first_name, u.last_name, u.phone, u.province AS seller_province,
           u.profile_photo, u.created_at AS member_since,
           c.name AS category_name,
           ROUND(AVG(r.rating), 1) AS avg_rating,
           COUNT(DISTINCT r.review_id) AS review_count
    FROM listings l
    JOIN users u ON l.seller_id = u.user_id
    JOIN categories c ON l.category_id = c.category_id
    LEFT JOIN reviews r ON r.seller_id = l.seller_id
    WHERE l.listing_id = :id AND l.status = "active"
    GROUP BY l.listing_id
');
$stmt->execute([':id' => $listing_id]);
$listing = $stmt->fetch();

if (!$listing) {
    header('Location: index.php');
    exit;
}

// Increment view count
$pdo->prepare('UPDATE listings SET views_count = views_count + 1 WHERE listing_id = :id')
    ->execute([':id' => $listing_id]);

// Fetch all images
$imgs = $pdo->prepare('SELECT * FROM listing_images WHERE listing_id = :id ORDER BY is_primary DESC');
$imgs->execute([':id' => $listing_id]);
$images = $imgs->fetchAll();

// Fetch seller reviews
$revs = $pdo->prepare('
    SELECT r.*, CONCAT(u.first_name," ",u.last_name) AS reviewer_name
    FROM reviews r
    JOIN users u ON r.reviewer_id = u.user_id
    WHERE r.seller_id = :sid
    ORDER BY r.created_at DESC
    LIMIT 5
');
$revs->execute([':sid' => $listing['seller_id']]);
$reviews = $revs->fetchAll();

// Fetch related listings
$related = $pdo->prepare('
    SELECT l.*, li.image_url
    FROM listings l
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE l.category_id = :cat AND l.listing_id != :id AND l.status = "active"
    LIMIT 3
');
$related->execute([':cat' => $listing['category_id'], ':id' => $listing_id]);
$relatedListings = $related->fetchAll();

// Handle message form
$msg_sent = false;
$msg_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: auth/login.php');
        exit;
    }
    $body = trim($_POST['message_body']);
    if (empty($body)) {
        $msg_error = 'Message cannot be empty.';
    } else {
        $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, listing_id, body) 
                       VALUES (:sid, :rid, :lid, :body)')
            ->execute([
                ':sid'  => $_SESSION['user_id'],
                ':rid'  => $listing['seller_id'],
                ':lid'  => $listing_id,
                ':body' => $body
            ]);
        $msg_sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($listing['title']) ?> – Electro Trade</title>
  <link href="assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    /* ── Listing detail specific styles ── */
    .listing-detail { padding: 2rem 0; }

    .img-gallery { margin-bottom: 1.5rem; }

    .img-main {
      width: 100%;
      height: 380px;
      object-fit: contain;
      object-position: center;
      background: #f7f7f7;
      border-radius: var(--radius);
      cursor: pointer;
    }

    .img-thumbs {
      display: flex;
      gap: .5rem;
      margin-top: .75rem;
      flex-wrap: wrap;
    }

    .img-thumbs img {
      width: 75px;
      height: 75px;
      object-fit: contain;
      object-position: center;
      background: #f7f7f7;
      border-radius: 8px;
      cursor: pointer;
      border: 2px solid transparent;
      transition: border-color var(--transition);
    }

    .img-thumbs img:hover,
    .img-thumbs img.active { border-color: var(--primary); }

    .listing-info { padding-left: 1.5rem; }

    .listing-price {
      font-size: 2rem;
      font-weight: 700;
      color: var(--primary);
      margin: .5rem 0;
    }

    .listing-meta {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      margin: 1rem 0;
      font-size: .88rem;
      color: var(--text-muted);
    }

    .listing-meta span {
      display: flex;
      align-items: center;
      gap: .3rem;
    }

    .seller-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.25rem;
      margin-top: 1.5rem;
    }

    .seller-avatar {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: var(--primary);
      color: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      font-weight: 700;
      flex-shrink: 0;
    }

    .stars { color: var(--accent); font-size: 1rem; }

    .review-card {
      background: var(--bg);
      border-radius: var(--radius-sm);
      padding: 1rem;
      margin-bottom: .75rem;
    }

    .section-title {
      font-size: 1.1rem;
      font-weight: 700;
      margin-bottom: 1rem;
      padding-bottom: .5rem;
      border-bottom: 2px solid var(--primary);
      display: inline-block;
    }

    .action-buttons {
      display: flex;
      gap: .75rem;
      margin-top: 1.25rem;
      flex-wrap: wrap;
    }

    .action-buttons .btn { flex: 1; min-width: 140px; }

    @media (max-width: 768px) {
      .listing-info { padding-left: 0; margin-top: 1.5rem; }
      .img-main { height: 260px; }
    }
  </style>
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
        <?php endif; ?>
        <a href="auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
      <?php else: ?>
        <a href="auth/login.php">Login</a>
        <a href="auth/register.php" class="btn btn-accent btn-sm">Register</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- ── BREADCRUMB ── -->
<div style="background:#fff; border-bottom:1px solid var(--border); padding:.6rem 0;">
  <div class="container" style="font-size:.85rem; color:var(--text-muted);">
    <a href="index.php">Home</a> &rsaquo;
    <a href="index.php?cat=<?= $listing['category_id'] ?>">
      <?= htmlspecialchars($listing['category_name']) ?>
    </a> &rsaquo;
    <span><?= htmlspecialchars($listing['title']) ?></span>
  </div>
</div>

<!-- ── MAIN CONTENT ── -->
<div class="container listing-detail">
  <div class="row">

    <!-- LEFT: Images -->
    <div class="col-12 col-6">
      <div class="img-gallery">
        <?php
          $mainImg = !empty($images) ? $images[0]['image_url'] : 'assets/img/placeholder.png';
        ?>
        <img src="<?= htmlspecialchars($mainImg) ?>"
             id="mainImage" class="img-main"
             alt="<?= htmlspecialchars($listing['title']) ?>">

        <?php if (count($images) > 1): ?>
          <div class="img-thumbs">
            <?php foreach ($images as $i => $img): ?>
              <img src="<?= htmlspecialchars($img['image_url']) ?>"
                   class="<?= $i === 0 ? 'active' : '' ?>"
                   onclick="switchImage(this, '<?= htmlspecialchars($img['image_url']) ?>')"
                   alt="Image <?= $i+1 ?>">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Info -->
    <div class="col-12 col-6">
      <div class="listing-info">

        <!-- Category & Status -->
        <div style="display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem;">
          <span class="badge badge-primary"><?= htmlspecialchars($listing['category_name']) ?></span>
          <span class="badge badge-<?= $listing['condition_type'] ?>">
            <?= ucfirst($listing['condition_type']) ?>
          </span>
        </div>

        <!-- Title -->
        <h1 style="font-size:1.6rem; margin-bottom:.25rem;">
          <?= htmlspecialchars($listing['title']) ?>
        </h1>

        <!-- Price -->
        <div class="listing-price">R<?= number_format($listing['price'], 2) ?></div>

        <!-- Meta info -->
        <div class="listing-meta">
          <span>📍 <?= htmlspecialchars($listing['province'] ?? 'South Africa') ?></span>
          <span>👁 <?= number_format($listing['views_count']) ?> views</span>
          <span>📅 <?= date('d M Y', strtotime($listing['created_at'])) ?></span>
        </div>

        <!-- Description -->
        <div class="sz-card" style="margin-top:1rem;">
          <span class="section-title">Description</span>
          <p style="white-space:pre-line; font-size:.92rem; color:var(--text); margin:0;">
            <?= htmlspecialchars($listing['description'] ?? 'No description provided.') ?>
          </p>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
          <?php if (!isset($_SESSION['user_id'])): ?>
            <a href="auth/login.php" class="btn btn-primary">
              🛒 Buy Now
            </a>
            <a href="auth/login.php" class="btn btn-outline">
              💬 Contact Seller
            </a>
          <?php elseif ($_SESSION['user_id'] != $listing['seller_id']): ?>
            <a href="checkout.php?listing=<?= $listing_id ?>" class="btn btn-primary">
              🛒 Buy Now
            </a>
            <button class="btn btn-outline" onclick="toggleMessageForm()">
              💬 Contact Seller
            </button>
          <?php else: ?>
            <a href="seller/edit-listing.php?id=<?= $listing_id ?>" class="btn btn-accent">
              ✏️ Edit Listing
            </a>
          <?php endif; ?>
          <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'buyer'): ?>
            <?php
            // Check if already in wishlist
            $wCheck = $pdo->prepare('
                SELECT wishlist_id FROM wishlist 
                WHERE user_id = :uid AND listing_id = :lid
            ');
            $wCheck->execute([':uid' => $_SESSION['user_id'], ':lid' => $listing_id]);
            $inWishlist = $wCheck->fetch();
            ?>
            <button class="btn-wishlist btn btn-outline"
                    data-listing-id="<?= $listing_id ?>"
                    id="wishlistBtn"
                    style="display:flex; align-items:center; gap:.4rem;">
              <span class="wish-icon"><?= $inWishlist ? '❤️' : '🤍' ?></span>
              <span id="wishlistText">
                <?= $inWishlist ? 'Saved' : 'Save' ?>
              </span>
            </button>
          <?php endif; ?>
        </div>

        <!-- Message Form (hidden by default) -->
        <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $listing['seller_id']): ?>
          <div id="messageForm" style="display:none; margin-top:1rem;">
            <div class="sz-card">
              <span class="section-title">Send a Message</span>

              <?php if ($msg_sent): ?>
                <div class="alert alert-success">✅ Message sent successfully!</div>
              <?php endif; ?>
              <?php if ($msg_error): ?>
                <div class="alert alert-danger">⚠️ <?= htmlspecialchars($msg_error) ?></div>
              <?php endif; ?>

              <form method="POST">
                <div class="form-group">
                  <label class="form-label">Your message to the seller</label>
                  <textarea name="message_body" class="form-control"
                    placeholder="Hi, is this still available?..." rows="4"></textarea>
                </div>
                <button type="submit" name="send_message" class="btn btn-primary w-100">
                  📤 Send Message
                </button>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <!-- Seller Card -->
        <div class="seller-card">
          <div style="display:flex; align-items:center; gap:1rem;">
            <div class="seller-avatar">
              <?= strtoupper(substr($listing['first_name'], 0, 1)) ?>
            </div>
            <div>
              <div style="font-weight:600;">
                <?= htmlspecialchars($listing['first_name'].' '.$listing['last_name']) ?>
              </div>
              <div style="font-size:.82rem; color:var(--text-muted);">
                📍 <?= htmlspecialchars($listing['seller_province'] ?? '') ?>
                &nbsp;|&nbsp;
                Member since <?= date('M Y', strtotime($listing['member_since'])) ?>
              </div>
              <?php if ($listing['avg_rating']): ?>
                <div class="stars" style="font-size:.85rem;">
                  <?= str_repeat('★', round($listing['avg_rating'])) ?>
                  <?= str_repeat('☆', 5 - round($listing['avg_rating'])) ?>
                  <span style="color:var(--text-muted);">
                    (<?= $listing['review_count'] ?> reviews)
                  </span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ── REVIEWS ── -->
  <?php if (!empty($reviews)): ?>
    <div style="margin-top:2.5rem;">
      <span class="section-title">⭐ Seller Reviews</span>
      <div class="row">
        <?php foreach ($reviews as $rev): ?>
          <div class="col-12 col-6 mb-3">
            <div class="review-card">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:.5rem;">
                <span style="font-weight:600; font-size:.9rem;">
                  <?= htmlspecialchars($rev['reviewer_name']) ?>
                </span>
                <span class="stars">
                  <?= str_repeat('★', $rev['rating']) ?><?= str_repeat('☆', 5 - $rev['rating']) ?>
                </span>
              </div>
              <p style="font-size:.88rem; color:var(--text); margin:0;">
                <?= htmlspecialchars($rev['comment'] ?? '') ?>
              </p>
              <div style="font-size:.78rem; color:var(--text-muted); margin-top:.4rem;">
                <?= date('d M Y', strtotime($rev['created_at'])) ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── RELATED LISTINGS ── -->
  <?php if (!empty($relatedListings)): ?>
    <div style="margin-top:2.5rem; margin-bottom:2rem;">
      <span class="section-title">🔍 Related Listings</span>
      <div class="row">
        <?php foreach ($relatedListings as $r): ?>
          <div class="col-12 col-4 mb-4">
            <div class="listing-card">
              <img src="<?= htmlspecialchars($r['image_url'] ?? 'assets/img/placeholder.png') ?>"
                   alt="<?= htmlspecialchars($r['title']) ?>">
              <div class="card-body">
                <div class="card-title"><?= htmlspecialchars($r['title']) ?></div>
                <div class="price">R<?= number_format($r['price'], 2) ?></div>
              </div>
              <div class="card-footer">
                <a href="listing.php?id=<?= $r['listing_id'] ?>" class="btn btn-primary btn-sm w-100">
                  View
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<!-- ── FOOTER ── -->
<footer class="site-footer">
  <div class="container">
    <div class="row">
      <div class="col-12 col-4 mb-3">
        <h5>⚡ Electro Trade</h5>
        <p>South Africa's trusted C2C marketplace.</p>
      </div>
      <div class="col-12 col-4 mb-3">
        <h6>Quick Links</h6>
        <ul>
          <li><a href="index.php">Home</a></li>
          <li><a href="auth/register.php">Register</a></li>
          <li><a href="auth/login.php">Login</a></li>
        </ul>
      </div>
      <div class="col-12 col-4 mb-3">
        <h6>Contact</h6>
        <p>support@electrotrade.co.za</p>
        <p>🇿🇦 South Africa</p>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> Electro Trade. All rights reserved.</p>
    </div>
  </div>
</footer>

<script src="assets/js/electrotrade.js?v=20260605"></script>
<script>
  // Switch main image on thumbnail click
  function switchImage(thumb, src) {
    document.getElementById('mainImage').src = src;
    document.querySelectorAll('.img-thumbs img').forEach(i => i.classList.remove('active'));
    thumb.classList.add('active');
  }

  // Toggle contact message form
  function toggleMessageForm() {
    const form = document.getElementById('messageForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
  }
</script>
</body>
</html>