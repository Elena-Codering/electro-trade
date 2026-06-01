<?php
session_start();
require_once 'config/db.php';

// Must be logged in as buyer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: auth/login.php');
    exit;
}

$buyer_id   = $_SESSION['user_id'];
$listing_id = (int)($_GET['listing'] ?? 0);

if (!$listing_id) {
    header('Location: index.php');
    exit;
}

// Fetch listing
$stmt = $pdo->prepare('
    SELECT l.*, 
           CONCAT(u.first_name," ",u.last_name) AS seller_name,
           u.user_id AS seller_id,
           li.image_url
    FROM listings l
    JOIN users u ON l.seller_id = u.user_id
    LEFT JOIN listing_images li ON li.listing_id = l.listing_id AND li.is_primary = 1
    WHERE l.listing_id = :id AND l.status = "active"
');
$stmt->execute([':id' => $listing_id]);
$listing = $stmt->fetch();

if (!$listing) {
    header('Location: index.php');
    exit;
}

// Prevent seller buying own listing
if ($listing['seller_id'] == $buyer_id) {
    header('Location: listing.php?id=' . $listing_id);
    exit;
}

// Fetch buyer details
$buyer = $pdo->prepare('SELECT * FROM users WHERE user_id = :uid');
$buyer->execute([':uid' => $buyer_id]);
$buyerData = $buyer->fetch();

$errors  = [];
$success = false;
$order_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $address = trim($_POST['delivery_address']);
    $gateway = $_POST['gateway'];
    $qty     = (int)($_POST['quantity'] ?? 1);

    if (empty($address)) $errors[] = 'Delivery address is required.';
    if ($qty < 1)        $errors[] = 'Quantity must be at least 1.';
    if (!in_array($gateway, ['payfast','ozow','paygate','eft']))
        $errors[] = 'Please select a payment method.';

    if (empty($errors)) {
        $total = $listing['price'] * $qty;

        // Create order
        $pdo->prepare('
            INSERT INTO orders (buyer_id, listing_id, quantity, total_amount, status, delivery_address)
            VALUES (:bid, :lid, :qty, :total, "placed", :addr)
        ')->execute([
            ':bid'   => $buyer_id,
            ':lid'   => $listing_id,
            ':qty'   => $qty,
            ':total' => $total,
            ':addr'  => $address,
        ]);
        $order_id = $pdo->lastInsertId();

        // Create payment record
        $pdo->prepare('
            INSERT INTO payments (order_id, buyer_id, gateway, amount, status)
            VALUES (:oid, :bid, :gw, :amount, "pending")
        ')->execute([
            ':oid'    => $order_id,
            ':bid'    => $buyer_id,
            ':gw'     => $gateway,
            ':amount' => $total,
        ]);

        // Create notification for seller
        $pdo->prepare('
            INSERT INTO notifications (user_id, type, message)
            VALUES (:uid, "order", :msg)
        ')->execute([
            ':uid' => $listing['seller_id'],
            ':msg' => "New order received for: " . $listing['title'],
        ]);

        // Mark listing as sold
        // Reduce quantity, mark sold if quantity reaches 0
        $pdo->prepare('
            UPDATE listings 
            SET quantity = GREATEST(quantity - :qty, 0),
                status = CASE WHEN quantity - :qty2 <= 0 THEN "sold" ELSE status END
            WHERE listing_id = :id
        ')->execute([
            ':qty'  => $qty,
            ':qty2' => $qty,
            ':id'   => $listing_id
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
  <title>Checkout – Electro Trade</title>
  <link href="assets/css/style.css" rel="stylesheet">
  <style>
    .checkout-layout {
      display: grid;
      grid-template-columns: 1fr 380px;
      gap: 1.5rem;
      padding: 2rem 0;
    }

    .checkout-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.75rem;
      margin-bottom: 1.5rem;
    }

    .checkout-card h5 {
      color: var(--primary);
      margin-bottom: 1.25rem;
      padding-bottom: .5rem;
      border-bottom: 2px solid var(--bg);
    }

    .order-summary {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.5rem;
      position: sticky;
      top: 80px;
    }

    .order-summary h5 {
      color: var(--primary);
      margin-bottom: 1.25rem;
      padding-bottom: .5rem;
      border-bottom: 2px solid var(--bg);
    }

    .summary-img {
      width: 100%;
      height: 180px;
      object-fit: cover;
      border-radius: var(--radius-sm);
      margin-bottom: 1rem;
    }

    .summary-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .5rem 0;
      font-size: .9rem;
      border-bottom: 1px solid var(--border);
    }

    .summary-row:last-child { border-bottom: none; }

    .summary-total {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .75rem 0;
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--primary);
      border-top: 2px solid var(--border);
      margin-top: .5rem;
    }

    .payment-options {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .75rem;
    }

    .payment-option {
      border: 2px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 1rem;
      cursor: pointer;
      transition: all var(--transition);
      text-align: center;
    }

    .payment-option:hover {
      border-color: var(--primary);
      background: #e8f0fb;
    }

    .payment-option input[type="radio"] {
      display: none;
    }

    .payment-option.selected {
      border-color: var(--primary);
      background: #e8f0fb;
    }

    .payment-option .pay-icon { font-size: 1.8rem; margin-bottom: .3rem; }
    .payment-option .pay-name { font-weight: 600; font-size: .88rem; }
    .payment-option .pay-desc { font-size: .75rem; color: var(--text-muted); }

    .steps {
      display: flex;
      gap: 0;
      margin-bottom: 2rem;
    }

    .step {
      flex: 1;
      text-align: center;
      padding: .75rem;
      font-size: .82rem;
      font-weight: 600;
      color: var(--text-muted);
      border-bottom: 3px solid var(--border);
      transition: all var(--transition);
    }

    .step.active {
      color: var(--primary);
      border-bottom-color: var(--primary);
    }

    .step.done {
      color: var(--success);
      border-bottom-color: var(--success);
    }

    .success-box {
      text-align: center;
      padding: 3rem 2rem;
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }

    .success-icon {
      font-size: 4rem;
      margin-bottom: 1rem;
    }

    @media (max-width: 768px) {
      .checkout-layout {
        grid-template-columns: 1fr;
      }
      .payment-options {
        grid-template-columns: 1fr 1fr;
      }
      .order-summary {
        position: static;
      }
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

<div class="container">

  <!-- ── STEPS ── -->
  <div class="steps" style="margin-top:1.5rem;">
    <div class="step <?= !$success ? 'active' : 'done' ?>">1. Delivery & Payment</div>
    <div class="step <?= $success ? 'active' : '' ?>">2. Confirmation</div>
  </div>

  <?php if ($success): ?>
    <!-- ── SUCCESS ── -->
    <div class="success-box">
      <div class="success-icon">🎉</div>
      <h2 style="color:var(--success); margin-bottom:.5rem;">Order Placed!</h2>
      <p style="color:var(--text-muted); margin-bottom:1.5rem;">
        Your order <strong>#<?= $order_id ?></strong> has been placed successfully.<br>
        The seller has been notified and will be in touch soon.
      </p>

      <div style="background:var(--bg); border-radius:var(--radius); 
                  padding:1.25rem; max-width:400px; margin:0 auto 1.5rem; text-align:left;">
        <div class="summary-row">
          <span>Item</span>
          <span style="font-weight:600;"><?= htmlspecialchars($listing['title']) ?></span>
        </div>
        <div class="summary-row">
          <span>Order ID</span>
          <span>#<?= $order_id ?></span>
        </div>
        <div class="summary-row">
          <span>Amount</span>
          <span style="color:var(--primary); font-weight:700;">
            R<?= number_format($listing['price'], 2) ?>
          </span>
        </div>
        <div class="summary-row">
          <span>Status</span>
          <span style="color:var(--warning); font-weight:600;">Awaiting Payment</span>
        </div>
      </div>

      <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">
        <a href="buyer/dashboard.php" class="btn btn-primary">
          📦 View My Orders
        </a>
        <a href="index.php" class="btn btn-outline">
          🛍️ Continue Shopping
        </a>
      </div>
    </div>

  <?php else: ?>
    <!-- ── CHECKOUT FORM ── -->
    <div class="checkout-layout">

      <!-- LEFT: Form -->
      <div>
        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?>
              <div>⚠️ <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST" id="checkoutForm">

          <!-- Delivery Address -->
          <div class="checkout-card">
            <h5>📍 Delivery Address</h5>

            <div class="form-group">
              <label class="form-label">Full Delivery Address
                <span style="color:var(--danger);">*</span>
              </label>
              <textarea name="delivery_address" class="form-control"
                rows="3" placeholder="e.g. 12 Main Road, Sandton, Johannesburg, 2196"
                required><?= htmlspecialchars($buyerData['delivery_address'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
              <label class="form-label">Quantity</label>
                <?php
                // Check if listing has a quantity field, default to 1
                $maxQty = isset($listing['quantity']) ? (int)$listing['quantity'] : 1;
                ?>
                <input type="number" name="quantity" class="form-control"
                id="qtyInput" min="1" max="<?= $maxQty ?>" value="1"
                style="max-width:120px;"
                onchange="updateTotal()"
                <?= $maxQty <= 1 ? 'readonly' : '' ?>>
                <?php if ($maxQty <= 1): ?>
                <small style="color:var(--text-muted); font-size:.78rem;">
                    ⚠️ Only 1 item available
                </small>
                <?php endif; ?>
            </div>
          </div>

          <!-- Payment Method -->
          <div class="checkout-card">
            <h5>💳 Payment Method</h5>
            <div class="payment-options">

              <label class="payment-option" id="pay-payfast">
                <input type="radio" name="gateway" value="payfast"
                       onchange="selectPayment('payfast')" required>
                <div class="pay-icon">💳</div>
                <div class="pay-name">PayFast</div>
                <div class="pay-desc">Credit/Debit Card</div>
              </label>

              <label class="payment-option" id="pay-ozow">
                <input type="radio" name="gateway" value="ozow"
                       onchange="selectPayment('ozow')">
                <div class="pay-icon">🏦</div>
                <div class="pay-name">Ozow</div>
                <div class="pay-desc">Instant EFT</div>
              </label>

              <label class="payment-option" id="pay-paygate">
                <input type="radio" name="gateway" value="paygate"
                       onchange="selectPayment('paygate')">
                <div class="pay-icon">🔒</div>
                <div class="pay-name">PayGate</div>
                <div class="pay-desc">Secure Payment</div>
              </label>

              <label class="payment-option" id="pay-eft">
                <input type="radio" name="gateway" value="eft"
                       onchange="selectPayment('eft')">
                <div class="pay-icon">📱</div>
                <div class="pay-name">Manual EFT</div>
                <div class="pay-desc">Bank Transfer</div>
              </label>

            </div>
          </div>

          <!-- Contact Info -->
          <div class="checkout-card">
            <h5>👤 Contact Information</h5>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
              <div>
                <div style="font-size:.82rem; color:var(--text-muted);">Name</div>
                <div style="font-weight:600;">
                  <?= htmlspecialchars($buyerData['first_name'].' '.$buyerData['last_name']) ?>
                </div>
              </div>
              <div>
                <div style="font-size:.82rem; color:var(--text-muted);">Email</div>
                <div style="font-weight:600;">
                  <?= htmlspecialchars($buyerData['email']) ?>
                </div>
              </div>
              <div>
                <div style="font-size:.82rem; color:var(--text-muted);">Phone</div>
                <div style="font-weight:600;">
                  <?= htmlspecialchars($buyerData['phone'] ?? 'Not set') ?>
                </div>
              </div>
              <div>
                <div style="font-size:.82rem; color:var(--text-muted);">Province</div>
                <div style="font-weight:600;">
                  <?= htmlspecialchars($buyerData['province'] ?? 'Not set') ?>
                </div>
              </div>
            </div>
          </div>

        </form>
      </div>

      <!-- RIGHT: Order Summary -->
      <div>
        <div class="order-summary">
          <h5>🧾 Order Summary</h5>

          <img src="<?= htmlspecialchars($listing['image_url'] ?? 'assets/img/placeholder.png') ?>"
               class="summary-img"
               alt="<?= htmlspecialchars($listing['title']) ?>">

          <div class="summary-row">
            <span style="color:var(--text-muted);">Item</span>
            <span style="font-weight:600; max-width:200px; text-align:right;">
              <?= htmlspecialchars($listing['title']) ?>
            </span>
          </div>
          <div class="summary-row">
            <span style="color:var(--text-muted);">Seller</span>
            <span><?= htmlspecialchars($listing['seller_name']) ?></span>
          </div>
          <div class="summary-row">
            <span style="color:var(--text-muted);">Condition</span>
            <span><?= ucfirst($listing['condition_type']) ?></span>
          </div>
          <div class="summary-row">
            <span style="color:var(--text-muted);">Price</span>
            <span>R<?= number_format($listing['price'], 2) ?></span>
          </div>
          <div class="summary-row">
            <span style="color:var(--text-muted);">Quantity</span>
            <span id="summaryQty">1</span>
          </div>

          <div class="summary-total">
            <span>Total</span>
            <span id="summaryTotal">R<?= number_format($listing['price'], 2) ?></span>
          </div>

          <button type="submit" form="checkoutForm"
                  class="btn btn-primary w-100 btn-lg"
                  style="margin-top:1rem;">
            🛒 Place Order
          </button>

          <a href="listing.php?id=<?= $listing_id ?>"
             class="btn btn-outline w-100" style="margin-top:.75rem;">
            ← Back to Listing
          </a>

          <p style="font-size:.75rem; color:var(--text-muted); 
                    text-align:center; margin-top:1rem;">
            🔒 Your information is secure and encrypted
          </p>
        </div>
      </div>

    </div>
  <?php endif; ?>

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
  const basePrice = <?= $listing['price'] ?>;

  function updateTotal() {
    const qty   = parseInt(document.getElementById('qtyInput').value) || 1;
    const total = basePrice * qty;
    document.getElementById('summaryQty').textContent   = qty;
    document.getElementById('summaryTotal').textContent =
      'R' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function selectPayment(method) {
    document.querySelectorAll('.payment-option').forEach(el => {
      el.classList.remove('selected');
    });
    document.getElementById('pay-' + method).classList.add('selected');
  }
</script>
</body>
</html>