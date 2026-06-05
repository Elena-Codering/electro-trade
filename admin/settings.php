<?php
session_start();
require_once '../config/db.php';

// Only super admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}

$success = '';
$error   = '';

// ── CREATE SETTINGS TABLE IF NOT EXISTS ─────────────────
$pdo->exec('
    CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
');

// ── DEFAULT SETTINGS ─────────────────────────────────────
$defaults = [
    'site_name'          => 'Electro Trade',
    'site_email'         => 'support@electrotrade.co.za',
    'site_phone'         => '+27 11 000 0000',
    'site_description'   => "South Africa's trusted C2C marketplace",
    'maintenance_mode'   => '0',
    'allow_registration' => '1',
    'listings_per_page'  => '12',
    'max_images'         => '5',
    'currency_symbol'    => 'R',
    'default_province'   => 'Gauteng',
    'payfast_enabled'    => '1',
    'ozow_enabled'       => '1',
    'paygate_enabled'    => '1',
    'eft_enabled'        => '1',
    'smtp_host'          => 'smtp.gmail.com',
    'smtp_port'          => '587',
    'smtp_user'          => '',
    'smtp_pass'          => '',
    'facebook_url'       => '',
    'twitter_url'        => '',
    'instagram_url'      => '',
];

// Insert defaults if not exist
foreach ($defaults as $key => $value) {
    $pdo->prepare('
        INSERT IGNORE INTO settings (setting_key, setting_value)
        VALUES (:key, :value)
    ')->execute([':key' => $key, ':value' => $value]);
}

// ── HANDLE SAVE ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    $fieldsMap = [
        'general'  => ['site_name','site_email','site_phone',
                       'site_description','default_province',
                       'listings_per_page','max_images','currency_symbol'],
        'access'   => ['maintenance_mode','allow_registration'],
        'payment'  => ['payfast_enabled','ozow_enabled',
                       'paygate_enabled','eft_enabled'],
        'email'    => ['smtp_host','smtp_port','smtp_user','smtp_pass'],
        'social'   => ['facebook_url','twitter_url','instagram_url'],
    ];

    if (isset($fieldsMap[$section])) {
        foreach ($fieldsMap[$section] as $key) {
            $val = $_POST[$key] ?? '0';
            $pdo->prepare('
                UPDATE settings SET setting_value = :val 
                WHERE setting_key = :key
            ')->execute([':val' => $val, ':key' => $key]);
        }
        $success = ucfirst($section) . ' settings saved successfully.';
    }
}

// ── FETCH ALL SETTINGS ────────────────────────────────────
$rows = $pdo->query('SELECT setting_key, setting_value FROM settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
$cfg  = array_merge($defaults, $rows);

$provinces = ['Gauteng','Western Cape','KwaZulu-Natal','Eastern Cape',
              'Limpopo','Mpumalanga','North West','Free State','Northern Cape'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings – Electro Trade Admin</title>
  <link href="../assets/css/style.css?v=20260605" rel="stylesheet">
  <style>
    .settings-layout {
      display: grid;
      grid-template-columns: 220px 1fr;
      gap: 1.5rem;
    }

    .settings-nav {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1rem;
      height: fit-content;
      position: sticky;
      top: 80px;
    }

    .settings-nav a {
      display: flex;
      align-items: center;
      gap: .6rem;
      padding: .65rem .9rem;
      border-radius: var(--radius-sm);
      color: var(--text);
      font-size: .9rem;
      margin-bottom: .2rem;
      transition: all var(--transition);
      text-decoration: none;
    }

    .settings-nav a:hover,
    .settings-nav a.active {
      background: var(--primary);
      color: var(--white);
      text-decoration: none;
    }

    .settings-card {
      background: var(--white);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.75rem;
      margin-bottom: 1.5rem;
    }

    .settings-card h5 {
      color: var(--primary);
      margin-bottom: 1.25rem;
      padding-bottom: .5rem;
      border-bottom: 2px solid var(--bg);
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .toggle-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: .75rem 0;
      border-bottom: 1px solid var(--border);
    }

    .toggle-wrap:last-child { border-bottom: none; }

    .toggle-info .toggle-label {
      font-weight: 600;
      font-size: .92rem;
    }

    .toggle-info .toggle-desc {
      font-size: .8rem;
      color: var(--text-muted);
      margin-top: .1rem;
    }

    .toggle {
      position: relative;
      width: 48px;
      height: 26px;
      flex-shrink: 0;
    }

    .toggle input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .toggle-slider {
      position: absolute;
      inset: 0;
      background: var(--border);
      border-radius: 26px;
      cursor: pointer;
      transition: background var(--transition);
    }

    .toggle-slider::before {
      content: '';
      position: absolute;
      width: 20px;
      height: 20px;
      left: 3px;
      top: 3px;
      background: var(--white);
      border-radius: 50%;
      transition: transform var(--transition);
      box-shadow: 0 1px 3px rgba(0,0,0,.2);
    }

    .toggle input:checked + .toggle-slider {
      background: var(--success);
    }

    .toggle input:checked + .toggle-slider::before {
      transform: translateX(22px);
    }

    .maintenance-banner {
      background: linear-gradient(135deg, var(--danger), #a93226);
      color: var(--white);
      border-radius: var(--radius);
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    @media (max-width: 768px) {
      .settings-layout {
        grid-template-columns: 1fr;
      }
      .settings-nav {
        position: static;
      }
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
      <a href="listings.php">📦 Listings</a>
      <a href="orders.php">🛒 Orders</a>
<!--      <a href="reports.php">📊 Reports</a> -->
      <a href="roles.php">🔐 Roles</a>
      <a href="settings.php" class="active">⚙️ Settings</a>
      <a href="../index.php">🌐 View Site</a>
      <a href="../auth/logout.php" style="color:rgb(255, 255, 255);">🚪 Logout</a>
    </nav>
  </aside>

  <!-- ── MAIN ── -->
  <main class="dashboard-content">

    <div style="margin-bottom:1.5rem;">
      <h2>⚙️ System Settings</h2>
      <p style="color:var(--text-muted); font-size:.9rem; margin:0;">
        Configure your Electro Trade platform settings
      </p>
    </div>

    <!-- Maintenance Banner -->
    <?php if ($cfg['maintenance_mode'] === '1'): ?>
      <div class="maintenance-banner">
        <span style="font-size:1.5rem;">🔧</span>
        <div>
          <div style="font-weight:700;">Maintenance Mode is ON</div>
          <div style="font-size:.88rem; opacity:.9;">
            The site is currently offline for regular users.
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="settings-layout">

      <!-- ── SETTINGS NAV ── -->
      <div class="settings-nav">
        <a href="#general"  class="active" onclick="showSection('general',this)">
          🏠 General
        </a>
        <a href="#access"   onclick="showSection('access',this)">
          🔒 Access Control
        </a>
        <a href="#payment"  onclick="showSection('payment',this)">
          💳 Payment Gateways
        </a>
        <a href="#email"    onclick="showSection('email',this)">
          📧 Email (SMTP)
        </a>
        <a href="#social"   onclick="showSection('social',this)">
          📱 Social Media
        </a>
      </div>

      <!-- ── SETTINGS CONTENT ── -->
      <div>

        <!-- ── GENERAL ── -->
        <div class="settings-card" id="section-general">
          <h5>🏠 General Settings</h5>
          <form method="POST">
            <input type="hidden" name="section" value="general">

            <div class="form-group">
              <label class="form-label">Site Name</label>
              <input type="text" name="site_name" class="form-control"
                value="<?= htmlspecialchars($cfg['site_name']) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Support Email</label>
              <input type="email" name="site_email" class="form-control"
                value="<?= htmlspecialchars($cfg['site_email']) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Support Phone</label>
              <input type="text" name="site_phone" class="form-control"
                value="<?= htmlspecialchars($cfg['site_phone']) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Site Description</label>
              <textarea name="site_description" class="form-control"
                rows="2"><?= htmlspecialchars($cfg['site_description']) ?></textarea>
            </div>

            <div class="row">
              <div class="col-12 col-4">
                <div class="form-group">
                  <label class="form-label">Listings Per Page</label>
                  <input type="number" name="listings_per_page" class="form-control"
                    min="4" max="48"
                    value="<?= htmlspecialchars($cfg['listings_per_page']) ?>">
                </div>
              </div>
              <div class="col-12 col-4">
                <div class="form-group">
                  <label class="form-label">Max Images Per Listing</label>
                  <input type="number" name="max_images" class="form-control"
                    min="1" max="10"
                    value="<?= htmlspecialchars($cfg['max_images']) ?>">
                </div>
              </div>
              <div class="col-12 col-4">
                <div class="form-group">
                  <label class="form-label">Currency Symbol</label>
                  <input type="text" name="currency_symbol" class="form-control"
                    maxlength="5"
                    value="<?= htmlspecialchars($cfg['currency_symbol']) ?>">
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Default Province</label>
              <select name="default_province" class="form-control">
                <?php foreach ($provinces as $prov): ?>
                  <option value="<?= $prov ?>"
                    <?= $cfg['default_province'] === $prov ? 'selected' : '' ?>>
                    <?= $prov ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <button type="submit" class="btn btn-primary">💾 Save General Settings</button>
          </form>
        </div>

        <!-- ── ACCESS CONTROL ── -->
        <div class="settings-card" id="section-access" style="display:none;">
          <h5>🔒 Access Control</h5>
          <form method="POST">
            <input type="hidden" name="section" value="access">

            <div class="toggle-wrap">
              <div class="toggle-info">
                <div class="toggle-label">🔧 Maintenance Mode</div>
                <div class="toggle-desc">
                  When ON, only admins can access the site.
                  Regular users see a maintenance message.
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="maintenance_mode" value="1"
                  <?= $cfg['maintenance_mode'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
            </div>

            <div class="toggle-wrap">
              <div class="toggle-info">
                <div class="toggle-label">📝 Allow New Registrations</div>
                <div class="toggle-desc">
                  When OFF, new users cannot register accounts.
                </div>
              </div>
              <label class="toggle">
                <input type="checkbox" name="allow_registration" value="1"
                  <?= $cfg['allow_registration'] === '1' ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
            </div>

            <div style="margin-top:1.5rem;">
              <button type="submit" class="btn btn-primary">💾 Save Access Settings</button>
            </div>
          </form>
        </div>

        <!-- ── PAYMENT GATEWAYS ── -->
        <div class="settings-card" id="section-payment" style="display:none;">
          <h5>💳 Payment Gateways</h5>
          <form method="POST">
            <input type="hidden" name="section" value="payment">

            <?php
            $gateways = [
              'payfast_enabled' => [
                'name' => 'PayFast',
                'icon' => '💳',
                'desc' => 'Accept credit/debit card payments via PayFast'
              ],
              'ozow_enabled' => [
                'name' => 'Ozow',
                'icon' => '🏦',
                'desc' => 'Instant EFT payments via Ozow'
              ],
              'paygate_enabled' => [
                'name' => 'PayGate',
                'icon' => '🔒',
                'desc' => 'Secure online payments via PayGate'
              ],
              'eft_enabled' => [
                'name' => 'Manual EFT',
                'icon' => '📱',
                'desc' => 'Manual bank transfer / EFT payments'
              ],
            ];
            foreach ($gateways as $key => $gw):
            ?>
              <div class="toggle-wrap">
                <div class="toggle-info">
                  <div class="toggle-label">
                    <?= $gw['icon'] ?> <?= $gw['name'] ?>
                  </div>
                  <div class="toggle-desc"><?= $gw['desc'] ?></div>
                </div>
                <label class="toggle">
                  <input type="checkbox" name="<?= $key ?>" value="1"
                    <?= $cfg[$key] === '1' ? 'checked' : '' ?>>
                  <span class="toggle-slider"></span>
                </label>
              </div>
            <?php endforeach; ?>

            <div style="margin-top:1.5rem;">
              <button type="submit" class="btn btn-primary">
                💾 Save Payment Settings
              </button>
            </div>
          </form>
        </div>

        <!-- ── EMAIL (SMTP) ── -->
        <div class="settings-card" id="section-email" style="display:none;">
          <h5>📧 Email (SMTP) Settings</h5>
          <div class="alert alert-info" style="margin-bottom:1.25rem;">
            ℹ️ Configure your SMTP settings to enable email notifications
            for orders, registrations and messages.
          </div>
          <form method="POST">
            <input type="hidden" name="section" value="email">

            <div class="row">
              <div class="col-12 col-8">
                <div class="form-group">
                  <label class="form-label">SMTP Host</label>
                  <input type="text" name="smtp_host" class="form-control"
                    placeholder="smtp.gmail.com"
                    value="<?= htmlspecialchars($cfg['smtp_host']) ?>">
                </div>
              </div>
              <div class="col-12 col-4">
                <div class="form-group">
                  <label class="form-label">SMTP Port</label>
                  <input type="number" name="smtp_port" class="form-control"
                    placeholder="587"
                    value="<?= htmlspecialchars($cfg['smtp_port']) ?>">
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">SMTP Username</label>
              <input type="email" name="smtp_user" class="form-control"
                placeholder="your@gmail.com"
                value="<?= htmlspecialchars($cfg['smtp_user']) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">SMTP Password</label>
              <input type="password" name="smtp_pass" class="form-control"
                placeholder="App password"
                value="<?= htmlspecialchars($cfg['smtp_pass']) ?>">
            </div>

            <button type="submit" class="btn btn-primary">
              💾 Save Email Settings
            </button>
          </form>
        </div>

        <!-- ── SOCIAL MEDIA ── -->
        <div class="settings-card" id="section-social" style="display:none;">
          <h5>📱 Social Media Links</h5>
          <form method="POST">
            <input type="hidden" name="section" value="social">

            <div class="form-group">
              <label class="form-label">Facebook URL</label>
              <input type="url" name="facebook_url" class="form-control"
                placeholder="https://facebook.com/electrotrade"
                value="<?= htmlspecialchars($cfg['facebook_url']) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Twitter / X URL</label>
              <input type="url" name="twitter_url" class="form-control"
                placeholder="https://twitter.com/electrotrade"
                value="<?= htmlspecialchars($cfg['twitter_url']) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Instagram URL</label>
              <input type="url" name="instagram_url" class="form-control"
                placeholder="https://instagram.com/electrotrade"
                value="<?= htmlspecialchars($cfg['instagram_url']) ?>">
            </div>

            <button type="submit" class="btn btn-primary">
              💾 Save Social Settings
            </button>
          </form>
        </div>

      </div>
    </div>

  </main>
</div>

<script>
  function showSection(name, link) {
    // Hide all sections
    document.querySelectorAll('.settings-card').forEach(s => {
      s.style.display = 'none';
    });
    // Remove active from all nav links
    document.querySelectorAll('.settings-nav a').forEach(a => {
      a.classList.remove('active');
    });
    // Show selected
    document.getElementById('section-' + name).style.display = 'block';
    link.classList.add('active');
    // Prevent default anchor jump
    event.preventDefault();
  }
</script>
</body>
</html>