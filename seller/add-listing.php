<?php
session_start();
require_once '../config/db.php';

// Only sellers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header('Location: ../auth/login.php');
    exit;
}

$errors  = [];
$success = '';

// Fetch categories
$cats = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price       = $_POST['price'];
    $category_id = (int)$_POST['category_id'];
    $condition   = $_POST['condition_type'];
    $province    = $_POST['province'];

    // Validation
    if (empty($title))       $errors[] = 'Title is required.';
    if (empty($description)) $errors[] = 'Description is required.';
    if (!is_numeric($price) || $price <= 0) $errors[] = 'Enter a valid price.';
    if ($category_id <= 0)   $errors[] = 'Please select a category.';

    // Handle image uploads
    $uploadedImages = [];
    if (!empty($_FILES['images']['name'][0])) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $uploadDir    = '../uploads/listings/';

        // Create upload dir if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            if ($_FILES['images']['error'][$i] !== 0) continue;

            $type = mime_content_type($tmp);
            if (!in_array($type, $allowedTypes)) {
                $errors[] = 'Only JPG, PNG, WEBP and GIF images are allowed.';
                break;
            }

            $ext      = pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION);
            $filename = uniqid('img_', true) . '.' . $ext;
            $dest     = $uploadDir . $filename;

            if (move_uploaded_file($tmp, $dest)) {
                $uploadedImages[] = 'uploads/listings/' . $filename;
            }
        }
    }

    if (empty($errors)) {
        // Insert listing
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));

        $stmt = $pdo->prepare('
            INSERT INTO listings 
            (seller_id, category_id, title, description, price, quantity, condition_type, province, status)
            VALUES (:sid, :cat, :title, :desc, :price, :qty, :cond, :prov, "active")
        ');
        $stmt->execute([
            ':sid'   => $_SESSION['user_id'],
            ':cat'   => $category_id,
            ':title' => $title,
            ':desc'  => $description,
            ':price' => $price,
            ':qty'   => $quantity,
            ':cond'  => $condition,
            ':prov'  => $province,
        ]);

        // Insert images
        if (!empty($uploadedImages)) {
            $imgStmt = $pdo->prepare('
                INSERT INTO listing_images (listing_id, image_url, is_primary)
                VALUES (:lid, :url, :primary)
            ');
            foreach ($uploadedImages as $i => $url) {
                $imgStmt->execute([
                    ':lid'     => $listing_id,
                    ':url'     => $url,
                    ':primary' => $i === 0 ? 1 : 0,
                ]);
            }
        }

        $success = $listing_id;
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
  <title>Add Listing – Electro Trade</title>
  <link href="../assets/css/style.css" rel="stylesheet">
  <style>
    .upload-area {
      border: 2px dashed var(--border);
      border-radius: var(--radius);
      padding: 2rem;
      text-align: center;
      cursor: pointer;
      transition: border-color var(--transition), background var(--transition);
      background: var(--bg);
    }

    .upload-area:hover,
    .upload-area.dragover {
      border-color: var(--primary);
      background: #e8f0fb;
    }

    .upload-area p { color: var(--text-muted); font-size: .9rem; margin: .5rem 0 0; }

    .preview-grid {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      margin-top: 1rem;
    }

    .preview-grid .preview-item {
      position: relative;
      width: 90px;
      height: 90px;
    }

    .preview-grid img {
      width: 90px;
      height: 90px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid var(--border);
    }

    .preview-grid .remove-img {
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

    .price-input {
      display: flex;
      align-items: stretch;
    }

    .price-prefix {
      background: var(--primary);
      color: var(--white);
      padding: 0 1rem;
      border-radius: var(--radius-sm) 0 0 var(--radius-sm);
      display: flex;
      align-items: center;
      font-weight: 700;
      font-size: 1rem;
    }

    .price-input .form-control {
      border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
      font-size: 1.1rem;
      font-weight: 600;
    }

    .char-count {
      font-size: .78rem;
      color: var(--text-muted);
      text-align: right;
      margin-top: .25rem;
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
      <a href="add-listing.php" class="active">➕ Add Listing</a>
      <a href="../index.php">🌐 View Store
      </a>
      <a href="../auth/logout.php" style="color:rgba(255,255,255,.6);">🚪 Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN CONTENT ── -->
  <main class="dashboard-content">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
      <div>
        <h2>➕ Add New Listing</h2>
        <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
          Fill in the details below to list your item for sale.
        </p>
      </div>
      <a href="dashboard.php" class="btn btn-outline">← Back to Dashboard</a>
    </div>

    <!-- Success Message -->
    <?php if ($success): ?>
      <div class="alert alert-success">
        ✅ Listing created successfully!
        <a href="../listing.php?id=<?= $success ?>" class="fw-bold">View it here</a>
        or <a href="add-listing.php" class="fw-bold">Add another</a>
      </div>
    <?php endif; ?>

    <!-- Error Messages -->
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
                Listing Title <span style="color:var(--danger);">*</span>
              </label>
              <input type="text" name="title" class="form-control"
                id="titleInput" maxlength="200"
                placeholder="e.g. iPhone 13 Pro 256GB – Midnight Black"
                value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
              <div class="char-count"><span id="titleCount">0</span>/200</div>
            </div>

            <div class="form-group">
              <label class="form-label">
                Description <span style="color:var(--danger);">*</span>
              </label>
              <textarea name="description" class="form-control"
                id="descInput" rows="6" maxlength="2000"
                placeholder="Describe your item in detail — condition, features, reason for selling, what's included..."
                required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
              <div class="char-count"><span id="descCount">0</span>/2000</div>
            </div>
          </div>

          <!-- Images -->
          <div class="form-card">
            <h5>📸 Photos</h5>
            <p style="font-size:.88rem; color:var(--text-muted); margin-bottom:1rem;">
              Upload up to 5 photos. The first photo will be the main image.
              Accepted: JPG, PNG, WEBP, GIF.
            </p>

            <div class="upload-area" id="uploadArea"
                 onclick="document.getElementById('imageInput').click()"
                 ondragover="handleDragOver(event)"
                 ondrop="handleDrop(event)"
                 ondragleave="this.classList.remove('dragover')">
              <div style="font-size:2.5rem;">📷</div>
              <p><strong>Click to upload</strong> or drag & drop images here</p>
              <p style="font-size:.8rem;">Max 5 images • JPG, PNG, WEBP, GIF</p>
            </div>

            <input type="file" name="images[]" id="imageInput"
                   accept="image/*" multiple style="display:none"
                   onchange="previewImages(this)">

            <div class="preview-grid" id="previewGrid"></div>
          </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="col-12 col-4">

          <!-- Pricing -->
          <div class="form-card">
            <h5>💰 Pricing</h5>
            <div class="form-group">
              <label class="form-label">
                Price <span style="color:var(--danger);">*</span>
              </label>
              <div class="price-input">
                <span class="price-prefix">R</span>
                <input type="number" name="price" class="form-control"
                  placeholder="0.00" min="1" step="0.01"
                  value="<?= htmlspecialchars($_POST['price'] ?? '') ?>" required>
              </div>
            </div>
          </div>

          <div class="form-group" style="margin-top:1rem;">
            <label class="form-label">Stock Quantity</label>
            <input type="number" name="quantity" class="form-control"
                min="1" max="999" value="1"
                style="max-width:120px;">
            <small style="color:var(--text-muted); font-size:.78rem;">
                How many of this item do you have?
            </small>
          </div>

          <!-- Category & Condition -->
          <div class="form-card">
            <h5>🏷️ Category & Condition</h5>

            <div class="form-group">
              <label class="form-label">
                Category <span style="color:var(--danger);">*</span>
              </label>
              <select name="category_id" class="form-control" required>
                <option value="">-- Select Category --</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= $c['category_id'] ?>"
                    <?= (($_POST['category_id'] ?? '') == $c['category_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">
                Condition <span style="color:var(--danger);">*</span>
              </label>
              <select name="condition_type" class="form-control" required>
                <option value="new"      <?= (($_POST['condition_type'] ?? '') === 'new')      ? 'selected' : '' ?>>🟢 New</option>
                <option value="like_new" <?= (($_POST['condition_type'] ?? '') === 'like_new') ? 'selected' : '' ?>>🔵 Like New</option>
                <option value="good"     <?= (($_POST['condition_type'] ?? 'good') === 'good') ? 'selected' : '' ?>>🟡 Good</option>
                <option value="fair"     <?= (($_POST['condition_type'] ?? '') === 'fair')     ? 'selected' : '' ?>>🟠 Fair</option>
                <option value="poor"     <?= (($_POST['condition_type'] ?? '') === 'poor')     ? 'selected' : '' ?>>🔴 Poor</option>
              </select>
            </div>
          </div>

          <!-- Location -->
          <div class="form-card">
            <h5>📍 Location</h5>
            <div class="form-group">
              <label class="form-label">Province</label>
              <select name="province" class="form-control">
                <option value="">-- Select Province --</option>
                <?php foreach ($provinces as $prov): ?>
                  <option value="<?= $prov ?>"
                    <?= (($_POST['province'] ?? '') === $prov) ? 'selected' : '' ?>>
                    <?= $prov ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Submit -->
          <button type="submit" class="btn btn-primary w-100 btn-lg">
            🚀 Publish Listing
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
  const titleCount = document.getElementById('titleCount');
  const descCount  = document.getElementById('descCount');

  titleInput.addEventListener('input', () => {
    titleCount.textContent = titleInput.value.length;
  });
  descInput.addEventListener('input', () => {
    descCount.textContent = descInput.value.length;
  });

  // Image preview
  let selectedFiles = [];

  function previewImages(input) {
    const files = Array.from(input.files).slice(0, 5);
    selectedFiles = files;
    renderPreviews();
  }

  function renderPreviews() {
    const grid = document.getElementById('previewGrid');
    grid.innerHTML = '';
    selectedFiles.forEach((file, i) => {
      const reader = new FileReader();
      reader.onload = function(e) {
        const item = document.createElement('div');
        item.className = 'preview-item';
        item.innerHTML = `
          <img src="${e.target.result}" alt="Preview ${i+1}">
          ${i === 0 ? '<span style="position:absolute;bottom:4px;left:4px;background:var(--primary);color:#fff;font-size:.65rem;padding:2px 5px;border-radius:4px;">Main</span>' : ''}
          <button type="button" class="remove-img" onclick="removeImage(${i})">✕</button>
        `;
        grid.appendChild(item);
      };
      reader.readAsDataURL(file);
    });
  }

  function removeImage(index) {
    selectedFiles.splice(index, 1);
    renderPreviews();
    // Update the file input
    const dt = new DataTransfer();
    selectedFiles.forEach(f => dt.items.add(f));
    document.getElementById('imageInput').files = dt.files;
  }

  // Drag and drop
  function handleDragOver(e) {
    e.preventDefault();
    document.getElementById('uploadArea').classList.add('dragover');
  }

  function handleDrop(e) {
    e.preventDefault();
    document.getElementById('uploadArea').classList.remove('dragover');
    const files = Array.from(e.dataTransfer.files)
                       .filter(f => f.type.startsWith('image/'))
                       .slice(0, 5);
    selectedFiles = files;
    renderPreviews();
    const dt = new DataTransfer();
    files.forEach(f => dt.items.add(f));
    document.getElementById('imageInput').files = dt.files;
  }
</script>
</body>
</html>