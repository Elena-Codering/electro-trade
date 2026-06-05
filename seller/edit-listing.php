<?php
session_start();
require_once '../config/db.php';

// Only sellers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header('Location: ../auth/login.php');
    exit;
}

$listing_id = (int)($_GET['id'] ?? 0);
if (!$listing_id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch listing — make sure it belongs to this seller
$stmt = $pdo->prepare('
    SELECT * FROM listings 
    WHERE listing_id = :id AND seller_id = :sid
');
$stmt->execute([':id' => $listing_id, ':sid' => $_SESSION['user_id']]);
$listing = $stmt->fetch();

if (!$listing) {
    header('Location: dashboard.php');
    exit;
}

// Fetch existing images
$imgs = $pdo->prepare('SELECT * FROM listing_images WHERE listing_id = :id ORDER BY is_primary DESC');
$imgs->execute([':id' => $listing_id]);
$existingImages = $imgs->fetchAll();

// Fetch categories
$cats = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price       = $_POST['price'];
    $category_id = (int)$_POST['category_id'];
    $condition   = $_POST['condition_type'];
    $province    = $_POST['province'];
    $status      = $_POST['status'];

    // Validation
    if (empty($title))       $errors[] = 'Title is required.';
    if (empty($description)) $errors[] = 'Description is required.';
    if (!is_numeric($price) || $price <= 0) $errors[] = 'Enter a valid price.';
    if ($category_id <= 0)   $errors[] = 'Please select a category.';

    // Handle new image uploads
    if (!empty($_FILES['images']['name'][0])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $uploadDir    = '../uploads/listings/';

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            if ($_FILES['images']['error'][$i] !== 0) continue;

            $type = mime_content_type($tmp);
            if (!in_array($type, $allowedTypes)) {
                $errors[] = 'Only JPG, PNG, WEBP and GIF images allowed.';
                break;
            }

            $ext      = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
            $filename = uniqid('img_', true) . '.' . $ext;
            $dest     = $uploadDir . $filename;

            if (move_uploaded_file($tmp, $dest)) {
                // If no existing images, make first upload the primary
                $isPrimary = empty($existingImages) && $i === 0 ? 1 : 0;
                $pdo->prepare('
                    INSERT INTO listing_images (listing_id, image_url, is_primary)
                    VALUES (:lid, :url, :primary)
                ')->execute([
                    ':lid'     => $listing_id,
                    ':url'     => 'uploads/listings/' . $filename,
                    ':primary' => $isPrimary,
                ]);
            }
        }
    }

    // Delete images if requested
    if (!empty($_POST['delete_images'])) {
        foreach ($_POST['delete_images'] as $img_id) {
            $img_id = (int)$img_id;
            // Get image path first
            $imgRow = $pdo->prepare('SELECT image_url FROM listing_images WHERE image_id = :id AND listing_id = :lid');
            $imgRow->execute([':id' => $img_id, ':lid' => $listing_id]);
            $imgData = $imgRow->fetch();
            if ($imgData) {
                // Delete file from server
                $filePath = '../' . $imgData['image_url'];
                if (file_exists($filePath)) unlink($filePath);
                // Delete from DB
                $pdo->prepare('DELETE FROM listing_images WHERE image_id = :id')
                    ->execute([':id' => $img_id]);
            }
        }
    }

    if (empty($errors)) {
        $pdo->prepare('
            UPDATE listings SET
                title        = :title,
                description  = :desc,
                price        = :price,
                category_id  = :cat,
                condition_type = :cond,
                province     = :prov,
                status       = :status
            WHERE listing_id = :id AND seller_id = :sid
        ')->execute([
            ':title'  => $title,
            ':desc'   => $description,
            ':price'  => $price,
            ':cat'    => $category_id,
            ':cond'   => $condition,
            ':prov'   => $province,
            ':status' => $status,
            ':id'     => $listing_id,
            ':sid'    => $_SESSION['user_id'],
        ]);

        $success = true;

        // Refresh listing data
        $stmt->execute([':id' => $listing_id, ':sid' => $_SESSION['user_id']]);
        $listing = $stmt->fetch();

        // Refresh images
        $imgs->execute([':id' => $listing_id]);
        $existingImages = $imgs->fetchAll();
    }
}

$provinces = ['Gauteng','Western Cape','KwaZulu-Natal','Eastern Cape',
              'Limpopo','Mpumalanga','North West','Free State','Northern Cape'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Listing – Electro Trade</title>
  <link href="../assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    .form-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.75rem;
      margin-bottom: 1.5rem;
    }

    .form-card h5 {
      color: var(--primary);
      margin-bottom: 1.25rem;
      padding-bottom: .5rem;
      border-bottom: 2px solid var(--bg);
    }

    .price-input { display: flex; align-items: stretch; }

    .price-prefix {
      background: var(--primary);
      color: var(--white);
      padding: 0 1rem;
      border-radius: var(--radius-sm) 0 0 var(--radius-sm);
      display: flex;
      align-items: center;
      font-weight: 700;
    }

    .price-input .form-control {
      border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
      font-size: 1.1rem;
      font-weight: 600;
    }

    .existing-images {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      margin-bottom: 1rem;
    }

    .img-item {
      position: relative;
      width: 90px;
      height: 90px;
    }

    .img-item img {
      width: 90px;
      height: 90px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid var(--border);
    }

    .img-item.marked-delete img {
      opacity: .35;
      border-color: var(--danger);
    }

    .img-item .delete-toggle {
      position: absolute;
      top: -6px;
      right: -6px;
      background: var(--danger);
      color: var(--white);
      border: none;
      border-radius: 50%;
      width: 22px;
      height: 22px;
      font-size: .75rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .img-item .primary-badge {
      position: absolute;
      bottom: 4px;
      left: 4px;
      background: var(--primary);
      color: var(--white);
      font-size: .62rem;
      padding: 2px 5px;
      border-radius: 4px;
    }

    .upload-area {
      border: 2px dashed var(--border);
      border-radius: var(--radius);
      padding: 1.5rem;
      text-align: center;
      cursor: pointer;
      transition: border-color var(--transition), background var(--transition);
      background: var(--bg);
    }

    .upload-area:hover { border-color: var(--primary); background: #e8f0fb; }

    .char-count {
      font-size: .78rem;
      color: var(--text-muted);
      text-align: right;
      margin-top: .25rem;
    }

    .preview-grid {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      margin-top: 1rem;
    }

    .preview-grid img {
      width: 90px;
      height: 90px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid var(--border);
    }
  </style>
</head>
<body>

<div class="dashboard-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <span class="sidebar-brand">⚡ Electro Trade</span>
    <nav class="sidebar-nav">
      <a href="dashboard.php">🏠 Dashboard</a>
      <a href="add-listing.php">➕ Add Listing</a>
      <a href="../index.php">🌐 View Store</a>
      <a href="../auth/logout.php" style="color:rgb(255, 255, 255);">🚪 Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <main class="dashboard-content">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>✏️ Edit Listing</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          Update your listing details below.
        </p>
      </div>
      <div style="display:flex; gap:.75rem;">
        <a href="../listing.php?id=<?= $listing_id ?>" class="btn btn-outline">
          👁 View Listing
        </a>
        <a href="dashboard.php" class="btn btn-outline">← Dashboard</a>
      </div>
    </div>

    <!-- Success -->
    <?php if ($success): ?>
      <div class="alert alert-success">
        ✅ Listing updated successfully!
        <a href="../listing.php?id=<?= $listing_id ?>" class="fw-bold">View it here</a>
      </div>
    <?php endif; ?>

    <!-- Errors -->
    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?>
          <div>⚠️ <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
      <div class="row">

        <!-- LEFT COLUMN -->
        <div class="col-12 col-8">

          <!-- Basic Info -->
          <div class="form-card">
            <h5>📝 Basic Information</h5>

            <div class="form-group">
              <label class="form-label">
                Title <span style="color:var(--danger);">*</span>
              </label>
              <input type="text" name="title" class="form-control"
                id="titleInput" maxlength="200"
                value="<?= htmlspecialchars($listing['title']) ?>" required>
              <div class="char-count">
                <span id="titleCount"><?= strlen($listing['title']) ?></span>/200
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">
                Description <span style="color:var(--danger);">*</span>
              </label>
              <textarea name="description" class="form-control"
                id="descInput" rows="6" maxlength="2000"
                required><?= htmlspecialchars($listing['description']) ?></textarea>
              <div class="char-count">
                <span id="descCount"><?= strlen($listing['description']) ?></span>/2000
              </div>
            </div>
          </div>

          <!-- Images -->
          <div class="form-card">
            <h5>📸 Photos</h5>

            <!-- Existing images -->
            <?php if (!empty($existingImages)): ?>
              <p style="font-size:.88rem; color:var(--text-muted); margin-bottom:.75rem;">
                Current photos — click ✕ to mark for deletion:
              </p>
              <div class="existing-images" id="existingImages">
                <?php foreach ($existingImages as $img): ?>
                  <div class="img-item" id="img-<?= $img['image_id'] ?>">
                    <img src="../<?= htmlspecialchars($img['image_url']) ?>"
                         alt="Listing image">
                    <?php if ($img['is_primary']): ?>
                      <span class="primary-badge">Main</span>
                    <?php endif; ?>
                    <button type="button" class="delete-toggle"
                            onclick="toggleDeleteImage(<?= $img['image_id'] ?>)">✕</button>
                    <!-- Hidden checkbox — checked = delete this image -->
                    <input type="checkbox" name="delete_images[]"
                           id="del-<?= $img['image_id'] ?>"
                           value="<?= $img['image_id'] ?>"
                           style="display:none;">
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Upload new images -->
            <div class="upload-area"
                 onclick="document.getElementById('imageInput').click()">
              <div style="font-size:2rem;">📷</div>
              <p><strong>Click to add more photos</strong></p>
              <p style="font-size:.8rem; color:var(--text-muted);">JPG, PNG, WEBP, GIF</p>
            </div>

            <input type="file" name="images[]" id="imageInput"
                   accept="image/*" multiple style="display:none"
                   onchange="previewNewImages(this)">

            <div class="preview-grid" id="previewGrid"></div>
          </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="col-12 col-4">

          <!-- Status -->
          <div class="form-card">
            <h5>📌 Listing Status</h5>
            <div class="form-group">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="active"  <?= $listing['status'] === 'active'  ? 'selected' : '' ?>>✅ Active</option>
                <option value="pending" <?= $listing['status'] === 'pending' ? 'selected' : '' ?>>⏳ Pending</option>
                <option value="sold"    <?= $listing['status'] === 'sold'    ? 'selected' : '' ?>>🏷️ Sold</option>
              </select>
            </div>
          </div>

          <!-- Pricing -->
          <div class="form-card">
            <h5>💰 Price</h5>
            <div class="form-group">
              <div class="price-input">
                <span class="price-prefix">R</span>
                <input type="number" name="price" class="form-control"
                  min="1" step="0.01"
                  value="<?= htmlspecialchars($listing['price']) ?>" required>
              </div>
            </div>
          </div>

          <!-- Category & Condition -->
          <div class="form-card">
            <h5>🏷️ Category & Condition</h5>

            <div class="form-group">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-control" required>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= $c['category_id'] ?>"
                    <?= $listing['category_id'] == $c['category_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Condition</label>
              <select name="condition_type" class="form-control">
                <?php
                $conditions = ['new'=>'🟢 New','like_new'=>'🔵 Like New',
                               'good'=>'🟡 Good','fair'=>'🟠 Fair','poor'=>'🔴 Poor'];
                foreach ($conditions as $val => $label):
                ?>
                  <option value="<?= $val ?>"
                    <?= $listing['condition_type'] === $val ? 'selected' : '' ?>>
                    <?= $label ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Location -->
          <div class="form-card">
            <h5>📍 Location</h5>
            <div class="form-group">
              <select name="province" class="form-control">
                <option value="">-- Select Province --</option>
                <?php foreach ($provinces as $prov): ?>
                  <option value="<?= $prov ?>"
                    <?= $listing['province'] === $prov ? 'selected' : '' ?>>
                    <?= $prov ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Save Button -->
          <button type="submit" class="btn btn-primary w-100 btn-lg">
            💾 Save Changes
          </button>
          <a href="dashboard.php" class="btn btn-outline w-100"
             style="margin-top:.75rem;">Cancel</a>

        </div>
      </div>
    </form>

  </main>
</div>

<script>
  // Character counters
  const titleInput = document.getElementById('titleInput');
  const descInput  = document.getElementById('descInput');
  titleInput.addEventListener('input', () => {
    document.getElementById('titleCount').textContent = titleInput.value.length;
  });
  descInput.addEventListener('input', () => {
    document.getElementById('descCount').textContent = descInput.value.length;
  });

  // Toggle image deletion
  function toggleDeleteImage(imgId) {
    const item     = document.getElementById('img-' + imgId);
    const checkbox = document.getElementById('del-' + imgId);
    item.classList.toggle('marked-delete');
    checkbox.checked = item.classList.contains('marked-delete');
  }

  // Preview new images
  function previewNewImages(input) {
    const grid = document.getElementById('previewGrid');
    grid.innerHTML = '';
    Array.from(input.files).forEach((file, i) => {
      const reader = new FileReader();
      reader.onload = function(e) {
        const img = document.createElement('img');
        img.src = e.target.result;
        img.alt = 'New image ' + (i + 1);
        grid.appendChild(img);
      };
      reader.readAsDataURL(file);
    });
  }
</script>
</body>
</html>