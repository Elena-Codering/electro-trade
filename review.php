<?php
session_start();
require_once 'config/db.php';

// Must be logged in as buyer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: auth/login.php');
    exit;
}

$buyer_id = $_SESSION['user_id'];
$order_id = (int)($_GET['order'] ?? 0);

if (!$order_id) {
    header('Location: buyer/dashboard.php');
    exit;
}

// Fetch order — must belong to buyer and be completed
$stmt = $pdo->prepare('
    SELECT o.*, l.title AS listing_title,
           l.listing_id, l.seller_id,
           CONCAT(s.first_name," ",s.last_name) AS seller_name,
           s.profile_photo,
           li.image_url
    FROM orders o
    JOIN listings l ON o.listing_id = l.listing_id
    JOIN users s ON l.seller_id = s.user_id
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE o.order_id = :oid 
    AND o.buyer_id = :bid 
    AND o.status = "completed"
');
$stmt->execute([':oid' => $order_id, ':bid' => $buyer_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: buyer/dashboard.php');
    exit;
}

// Check if already reviewed
$existing = $pdo->prepare('
    SELECT review_id FROM reviews 
    WHERE order_id = :oid AND reviewer_id = :bid
');
$existing->execute([':oid' => $order_id, ':bid' => $buyer_id]);
$alreadyReviewed = $existing->fetch();

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyReviewed) {
    $rating  = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);

    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating between 1 and 5 stars.';
    } else {
        // Insert review
        $pdo->prepare('
            INSERT INTO reviews 
            (reviewer_id, seller_id, order_id, rating, comment)
            VALUES (:rid, :sid, :oid, :rating, :comment)
        ')->execute([
            ':rid'     => $buyer_id,
            ':sid'     => $order['seller_id'],
            ':oid'     => $order_id,
            ':rating'  => $rating,
            ':comment' => $comment,
        ]);

        // Notify seller
        $pdo->prepare('
            INSERT INTO notifications (user_id, type, message)
            VALUES (:uid, "review", :msg)
        ')->execute([
            ':uid' => $order['seller_id'],
            ':msg' => "You received a new " . $rating . "-star review for order #" . $order_id,
        ]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Leave a Review – Electro Trade</title>
  <link href="assets/css/style.css" rel="stylesheet">
  <style>
    .review-wrapper {
      min-height: 80vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }

    .review-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 2.5rem;
      width: 100%;
      max-width: 560px;
    }

    /* Star Rating */
    .star-rating {
      display: flex;
      flex-direction: row-reverse;
      justify-content: flex-end;
      gap: .25rem;
      margin: 1rem 0;
    }

    .star-rating input {
      display: none;
    }

    .star-rating label {
      font-size: 2.5rem;
      color: var(--border);
      cursor: pointer;
      transition: color var(--transition);
      line-height: 1;
    }

    .star-rating label:hover,
    .star-rating label:hover ~ label,
    .star-rating input:checked ~ label {
      color: var(--accent);
    }

    .star-rating input:checked + label {
      color: var(--accent);
    }

    .rating-text {
      font-size: .88rem;
      color: var(--text-muted);
      margin-bottom: 1rem;
      min-height: 1.2rem;
      font-style: italic;
    }

    .order-summary {
      background: var(--bg);
      border-radius: var(--radius-sm);
      padding: 1rem;
      margin-bottom: 1.5rem;
      display: flex;
      gap: 1rem;
      align-items: center;
    }

    .order-summary img {
      width: 60px;
      height: 60px;
      object-fit: cover;
      border-radius: 8px;
      flex-shrink: 0;
    }

    .seller-avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--primary);
      color: var(--white);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      font-weight: 700;
      flex-shrink: 0;
    }

    .char-counter {
      font-size: .78rem;
      color: var(--text-muted);
      text-align: right;
      margin-top: .25rem;
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

<div class="review-wrapper">
  <div class="review-card">

    <?php if ($success): ?>
      <!-- ── SUCCESS ── -->
      <div style="text-align:center;">
        <div style="font-size:4rem; margin-bottom:1rem;">⭐</div>
        <h3 style="color:var(--success); margin-bottom:.5rem;">
          Review Submitted!
        </h3>
        <p style="color:var(--text-muted); margin-bottom:1.5rem;">
          Thank you for your feedback! Your review helps other buyers
          make better decisions.
        </p>
        <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">
          <a href="buyer/dashboard.php" class="btn btn-primary">
            📦 My Orders
          </a>
          <a href="index.php" class="btn btn-outline">
            🛍️ Browse More
          </a>
        </div>
      </div>

    <?php elseif ($alreadyReviewed): ?>
      <!-- ── ALREADY REVIEWED ── -->
      <div style="text-align:center;">
        <div style="font-size:3rem; margin-bottom:1rem;">✅</div>
        <h3 style="color:var(--primary); margin-bottom:.5rem;">
          Already Reviewed
        </h3>
        <p style="color:var(--text-muted); margin-bottom:1.5rem;">
          You have already submitted a review for this order.
        </p>
        <a href="buyer/dashboard.php" class="btn btn-primary">
          ← Back to Dashboard
        </a>
      </div>

    <?php else: ?>
      <!-- ── REVIEW FORM ── -->
      <h3 style="color:var(--primary); margin-bottom:.25rem;">
        ⭐ Leave a Review
      </h3>
      <p style="color:var(--text-muted); font-size:.88rem; margin-bottom:1.5rem;">
        Share your experience to help other buyers on Electro Trade.
      </p>

      <!-- Order Summary -->
      <div class="order-summary">
        <img src="<?= htmlspecialchars($order['image_url'] 
            ?? 'assets/img/placeholder.png') ?>"
             alt="<?= htmlspecialchars($order['listing_title']) ?>">
        <div style="flex:1;">
          <div style="font-weight:600; font-size:.92rem; margin-bottom:.2rem;">
            <?= htmlspecialchars($order['listing_title']) ?>
          </div>
          <div style="font-size:.82rem; color:var(--text-muted);">
            Order #<?= $order_id ?> ·
            R<?= number_format($order['total_amount'], 2) ?>
          </div>
        </div>
      </div>

      <!-- Seller Info -->
      <div style="display:flex; align-items:center; gap:.75rem; margin-bottom:1.5rem;">
        <div class="seller-avatar">
          <?= strtoupper(substr($order['seller_name'], 0, 1)) ?>
        </div>
        <div>
          <div style="font-size:.78rem; color:var(--text-muted);">
            You are reviewing
          </div>
          <div style="font-weight:700;">
            <?= htmlspecialchars($order['seller_name']) ?>
          </div>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">

        <!-- Star Rating -->
        <div class="form-group">
          <label class="form-label">
            Your Rating <span style="color:var(--danger);">*</span>
          </label>

          <div class="star-rating">
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <input type="radio" name="rating"
                     id="star<?= $i ?>" value="<?= $i ?>"
                     onchange="updateRatingText(<?= $i ?>)">
              <label for="star<?= $i ?>">★</label>
            <?php endfor; ?>
          </div>

          <div class="rating-text" id="ratingText">
            Click a star to rate
          </div>
        </div>

        <!-- Comment -->
        <div class="form-group">
          <label class="form-label">Your Review</label>
          <textarea name="comment" class="form-control"
            id="commentInput" rows="5" maxlength="1000"
            placeholder="Tell others about your experience with this seller — 
was the item as described? Was communication good? 
Would you recommend them?"></textarea>
          <div class="char-counter">
            <span id="charCount">0</span>/1000
          </div>
        </div>

        <!-- Tips -->
        <div style="background:var(--bg); border-radius:var(--radius-sm);
                    padding:.85rem 1rem; margin-bottom:1.25rem;
                    font-size:.82rem; color:var(--text-muted);">
          💡 <strong>Tips for a helpful review:</strong>
          Mention item condition, seller communication,
          packaging and delivery speed.
        </div>

        <button type="submit" class="btn btn-primary w-100 btn-lg">
          ⭐ Submit Review
        </button>

        <a href="buyer/dashboard.php"
           class="btn btn-outline w-100" style="margin-top:.75rem;">
          Cancel
        </a>

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

<script src="assets/js/electrotrade.js"></script>
<script>
  const ratingTexts = {
    1: '😞 Poor – Very disappointed',
    2: '😐 Fair – Below expectations',
    3: '🙂 Good – Met expectations',
    4: '😊 Very Good – Exceeded expectations',
    5: '🤩 Excellent – Outstanding experience!'
  };

  function updateRatingText(rating) {
    document.getElementById('ratingText').textContent = ratingTexts[rating];
  }

  // Character counter
  const commentInput = document.getElementById('commentInput');
  commentInput.addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
  });
</script>
</body>
</html>