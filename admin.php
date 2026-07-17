<?php
/**
 * EthioTractors — Admin Panel
 * Login-protected management of products, client inquiries and site settings.
 */
require __DIR__ . '/db.php';

$view = $_GET['view'] ?? 'dashboard';

/* ---------- Helpers ---------- */

function et_when(string $utc): string
{
    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Africa/Addis_Ababa'))
            ->format('M j, Y · H:i');
    } catch (Exception) {
        return $utc;
    }
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/** True when the image path points at a file we stored in uploads/. */
function et_is_local_upload(string $url): bool
{
    return str_starts_with($url, 'uploads/') && !str_contains($url, '..');
}

/**
 * Validate an uploaded product photo and move it into uploads/.
 * Returns the relative path, or null when the file is not an acceptable image.
 */
function et_save_photo(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return null;
    $ext = match ($info[2]) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if ($ext === null) return null;

    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Never let anything in uploads/ execute as a script on Apache hosts.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "<FilesMatch \"\\.(?i:php|phar|phtml|cgi|pl)$\">\nRequire all denied\n</FilesMatch>\n");
    }

    $name = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return null;
    return 'uploads/' . $name;
}

function et_delete_local_photo(string $url): void
{
    if (et_is_local_upload($url)) {
        @unlink(__DIR__ . '/' . $url);
    }
}

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_check()) {
        flash_set('error', 'Session expired — please try again.');
        redirect('admin.php' . ($action === 'login' ? '?view=login' : ''));
    }

    /* ----- Login (rate-limited per IP, database-backed) ----- */
    if ($action === 'login') {
        if (et_throttled('login', 6, 600)) {
            flash_set('error', 'Too many failed attempts — please wait a few minutes and try again.');
            redirect('admin.php?view=login');
        }

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id' => $user['id'], 'username' => $user['username']];
            $_SESSION['admin_last_seen'] = time();
            redirect('admin.php');
        }
        et_throttle_hit('login', 600);
        flash_set('error', 'Invalid username or password.');
        redirect('admin.php?view=login');
    }

    auth_require(); // everything below needs a logged-in admin

    switch ($action) {
        case 'logout':
            session_regenerate_id(true);
            unset($_SESSION['admin']);
            flash_set('success', 'You have been signed out.');
            redirect('admin.php?view=login');

        /* ----- Products ----- */
        case 'product_save': {
            $id     = (int)($_POST['id'] ?? 0);
            $name   = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 160);
            $sector = $_POST['sector'] ?? '';
            if ($name === '' || !isset(ET_SECTORS[$sector])) {
                flash_set('error', 'A product needs at least a name and a sector.');
                redirect('admin.php?view=product' . ($id ? "&id=$id" : ''));
            }
            $icons = et_icons();
            $icon  = isset($icons[$_POST['icon'] ?? '']) ? $_POST['icon'] : 'machine';

            $specs = [];
            $keys   = (array)($_POST['spec_k'] ?? []);
            $values = (array)($_POST['spec_v'] ?? []);
            foreach ($keys as $i => $k) {
                $k = trim((string)$k);
                $v = trim((string)($values[$i] ?? ''));
                if ($k !== '' && $v !== '') {
                    $specs[] = [mb_substr($k, 0, 60), mb_substr($v, 0, 100)];
                }
            }

            // Resolve the product photo: uploaded file wins, then the pasted link.
            $oldImage = '';
            if ($id > 0) {
                $stmt = db()->prepare('SELECT image_url FROM products WHERE id = ?');
                $stmt->execute([$id]);
                $oldImage = (string)$stmt->fetchColumn();
            }
            $imageUrl = mb_substr(trim((string)($_POST['image_url'] ?? '')), 0, 500);
            if (!empty($_POST['remove_photo'])) {
                $imageUrl = '';
            }
            if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                $saved = et_save_photo($_FILES['photo']);
                if ($saved === null) {
                    flash_set('error', 'Photo not saved — please use a JPG, PNG, WebP or GIF image under 5 MB.');
                    redirect('admin.php?view=product' . ($id ? "&id=$id" : ''));
                }
                $imageUrl = $saved;
            }
            if ($oldImage !== '' && $oldImage !== $imageUrl) {
                et_delete_local_photo($oldImage);
            }

            $row = [
                $name,
                $sector,
                mb_substr(trim((string)($_POST['category'] ?? '')), 0, 80),
                mb_substr(trim((string)($_POST['brand'] ?? '')), 0, 80),
                mb_substr(trim((string)($_POST['description'] ?? '')), 0, 600),
                json_encode($specs, JSON_UNESCAPED_UNICODE),
                mb_substr(trim((string)($_POST['tags'] ?? '')), 0, 200),
                $icon,
                $imageUrl,
            ];

            if ($id > 0) {
                db()->prepare('UPDATE products SET name=?, sector=?, category=?, brand=?, description=?, specs=?, tags=?, icon=?, image_url=? WHERE id=?')
                    ->execute([...$row, $id]);
                flash_set('success', "“{$name}” has been updated.");
            } else {
                db()->prepare('INSERT INTO products (name, sector, category, brand, description, specs, tags, icon, image_url, sort)
                               VALUES (?,?,?,?,?,?,?,?,?, (SELECT s FROM (SELECT COALESCE(MAX(sort),0)+1 AS s FROM products) t))')
                    ->execute($row);
                flash_set('success', "“{$name}” has been added to the catalog.");
            }
            redirect('admin.php?view=products');
        }

        /* ----- Products: display order (drag and drop) ----- */
        case 'product_reorder': {
            header('Content-Type: application/json');
            $ids = [];
            foreach ((array)($_POST['ids'] ?? []) as $raw) {
                $id = (int)$raw;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if (!$ids) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'No products were supplied.']);
                exit;
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Renumber the whole catalog first: seeded rows can share a sort value,
                // and swapping slots is only unambiguous once every row has its own.
                $all = $pdo->query('SELECT id FROM products ORDER BY sort, id')->fetchAll(PDO::FETCH_COLUMN);
                $upd = $pdo->prepare('UPDATE products SET sort = ? WHERE id = ?');
                $slotOf = [];
                foreach ($all as $i => $rid) {
                    $slotOf[(int)$rid] = $i + 1;
                    $upd->execute([$i + 1, (int)$rid]);
                }
                // The reordered rows reuse the slots they already occupied, so a sector
                // view only shuffles its own products and the rest of the catalog stays put.
                $slots = [];
                foreach ($ids as $id) {
                    if (!isset($slotOf[$id])) {
                        throw new RuntimeException('unknown product id');
                    }
                    $slots[] = $slotOf[$id];
                }
                sort($slots);
                foreach ($ids as $i => $id) {
                    $upd->execute([$slots[$i], $id]);
                }
                $pdo->commit();
            } catch (Throwable) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'The catalog changed — reload the page and try again.']);
                exit;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'product_delete': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT name, image_url FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $prod = $stmt->fetch();
            if ($prod) {
                et_delete_local_photo($prod['image_url']);
                db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                flash_set('success', "“{$prod['name']}” has been deleted from the catalog.");
            }
            redirect('admin.php?view=products');
        }

        /* ----- Inquiries ----- */
        case 'inquiry_status': {
            $id     = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['new', 'contacted', 'closed'], true) ? $_POST['status'] : 'new';
            $back   = in_array($_POST['back'] ?? '', ['', '&status=new', '&status=contacted', '&status=closed'], true) ? $_POST['back'] : '';
            db()->prepare('UPDATE inquiries SET status = ? WHERE id = ?')->execute([$status, $id]);
            flash_set('success', "Inquiry marked as {$status}.");
            redirect('admin.php?view=inquiries' . $back);
        }

        case 'inquiry_delete': {
            $back = in_array($_POST['back'] ?? '', ['', '&status=new', '&status=contacted', '&status=closed'], true) ? $_POST['back'] : '';
            db()->prepare('DELETE FROM inquiries WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Inquiry deleted.');
            redirect('admin.php?view=inquiries' . $back);
        }

        /* ----- Settings ----- */
        case 'settings_save': {
            $fields = ['company_name','tagline','phone','phone2','phone3','email','address','branches','hours','map_query','telegram','facebook','linkedin','trade_license'];
            foreach ($fields as $f) {
                et_save_setting($f, mb_substr(trim((string)($_POST[$f] ?? '')), 0, 400));
            }
            flash_set('success', 'Site settings saved — the public website is updated.');
            redirect('admin.php?view=settings');
        }

        case 'account_save': {
            $current = (string)($_POST['current_password'] ?? '');
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([auth_user()['id']]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($current, $user['pass_hash'])) {
                flash_set('error', 'Your current password is incorrect — no changes were made.');
                redirect('admin.php?view=settings');
            }
            $username = trim((string)($_POST['username'] ?? ''));
            $new      = (string)($_POST['new_password'] ?? '');
            if ($username !== '' && $username !== $user['username']) {
                db()->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$username, $user['id']]);
                $_SESSION['admin']['username'] = $username;
            }
            if ($new !== '') {
                if (strlen($new) < 8) {
                    flash_set('error', 'New password must be at least 8 characters.');
                    redirect('admin.php?view=settings');
                }
                db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            }
            flash_set('success', 'Account credentials updated.');
            redirect('admin.php?view=settings');
        }

        case 'reset_catalog':
            foreach (db()->query("SELECT image_url FROM products WHERE image_url LIKE 'uploads/%'") as $r) {
                et_delete_local_photo($r['image_url']);
            }
            db()->exec('DELETE FROM products');
            et_seed_products(db());
            flash_set('success', 'Product catalog restored to the default import list.');
            redirect('admin.php?view=products');

        case 'clear_inquiries':
            db()->exec("DELETE FROM inquiries WHERE status = 'closed'");
            flash_set('success', 'All closed inquiries have been removed.');
            redirect('admin.php?view=inquiries');
    }
    redirect('admin.php');
}

/* ---------- CSV export ---------- */
if ($view === 'export') {
    auth_require();
    $rows = db()->query('SELECT id, created_at, status, source, name, company, email, phone, industry, interest, message FROM inquiries ORDER BY id DESC')->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ethiotractors-inquiries-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
    fputcsv($out, ['ID', 'Date (UTC)', 'Status', 'Source', 'Name', 'Company', 'Email', 'Phone', 'Industry', 'Interest', 'Message']);
    foreach ($rows as $r) {
        // Neutralize spreadsheet formula injection (values starting with = + - @ etc.).
        fputcsv($out, array_map(
            static fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v,
            array_values($r)
        ));
    }
    fclose($out);
    exit;
}

/* ---------- View data ---------- */
$flash    = flash_get();
$loggedIn = (bool)auth_user();

if (!$loggedIn && $view !== 'login') {
    redirect('admin.php?view=login');
}
if ($loggedIn && $view === 'login') {
    redirect('admin.php');
}

$newCount = 0;
if ($loggedIn) {
    $newCount = (int)db()->query("SELECT COUNT(*) FROM inquiries WHERE status = 'new'")->fetchColumn();
}

/* ============================================================
   LOGIN VIEW
   ============================================================ */
if ($view === 'login'):
    // Show the default credentials only while they still work — once the
    // password is changed, nothing about the account is revealed here.
    $defaultHash = db()->prepare('SELECT pass_hash FROM users WHERE username = ?');
    $defaultHash->execute(['admin']);
    $hash = $defaultHash->fetchColumn();
    $showDefaultHint = $hash !== false && password_verify('ethio2026', (string)$hash);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Login — EthioTractors Admin</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/logo.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php // Version the styles by mtime so browsers can't pair cached CSS with new markup. ?>
<link rel="stylesheet" href="assets/tokens.css?v=<?= filemtime(__DIR__ . '/assets/tokens.css') ?>">
<link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="login-body">
  <div>
    <div class="login-card">
      <div class="login-logo">
        <img src="assets/logo.png" alt="Ethio Tractor" class="login-logo-img" width="200" height="58">
      </div>
      <div class="login-sub">Staff Portal — Authorized Access Only</div>
      <?php if ($flash): ?><div class="<?= $flash['type'] === 'error' ? 'login-error' : 'login-ok' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
      <form method="post" action="admin.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="login">
        <div class="field">
          <label for="l-user">Username</label>
          <input id="l-user" name="username" type="text" autocomplete="username" required autofocus>
        </div>
        <div class="field">
          <label for="l-pass">Password</label>
          <input id="l-pass" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-solid" style="justify-content:center">Sign In →</button>
      </form>
      <?php if ($showDefaultHint): ?>
      <p class="login-hint">First time here? The default account is <code>admin</code> / <code>ethio2026</code> — change it under <strong>Settings → Account</strong> right after signing in.</p>
      <?php else: ?>
      <p class="login-hint">Access is restricted to authorized EthioTractors staff.</p>
      <?php endif; ?>
    </div>
    <a class="login-back" href="index.php">← Back to the public website</a>
  </div>
</body>
</html>
<?php exit; endif;

/* ============================================================
   ADMIN SHELL DATA
   ============================================================ */
$icons = et_icons();

$dash = null;
if ($view === 'dashboard') {
    $dash = [
        'products'   => (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'total_inq'  => (int)db()->query('SELECT COUNT(*) FROM inquiries')->fetchColumn(),
        'news'       => (int)db()->query("SELECT COUNT(*) FROM inquiries WHERE source = 'newsletter'")->fetchColumn(),
        'recent'     => db()->query('SELECT * FROM inquiries ORDER BY id DESC LIMIT 6')->fetchAll(),
        'by_sector'  => db()->query('SELECT sector, COUNT(*) c FROM products GROUP BY sector')->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

$productList = null;
if ($view === 'products') {
    $sectorFilter = $_GET['sector'] ?? 'all';
    if (isset(ET_SECTORS[$sectorFilter])) {
        $stmt = db()->prepare('SELECT * FROM products WHERE sector = ? ORDER BY sort, id');
        $stmt->execute([$sectorFilter]);
        $productList = $stmt->fetchAll();
    } else {
        $sectorFilter = 'all';
        $productList = db()->query('SELECT * FROM products ORDER BY sort, id')->fetchAll();
    }
}

$editProduct = null;
if ($view === 'product') {
    $pid = (int)($_GET['id'] ?? 0);
    if ($pid > 0) {
        $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$pid]);
        $editProduct = $stmt->fetch() ?: null;
        if (!$editProduct) {
            flash_set('error', 'That product no longer exists.');
            redirect('admin.php?view=products');
        }
    }
}

$inquiryList = null;
if ($view === 'inquiries') {
    $statusFilter = in_array($_GET['status'] ?? '', ['new', 'contacted', 'closed'], true) ? $_GET['status'] : 'all';
    if ($statusFilter !== 'all') {
        $stmt = db()->prepare('SELECT * FROM inquiries WHERE status = ? ORDER BY id DESC');
        $stmt->execute([$statusFilter]);
        $inquiryList = $stmt->fetchAll();
    } else {
        $inquiryList = db()->query('SELECT * FROM inquiries ORDER BY id DESC')->fetchAll();
    }
    $inqCounts = ['all' => 0, 'new' => 0, 'contacted' => 0, 'closed' => 0];
    foreach (db()->query('SELECT status, COUNT(*) c FROM inquiries GROUP BY status') as $r) {
        $inqCounts[$r['status']] = (int)$r['c'];
        $inqCounts['all'] += (int)$r['c'];
    }
}

/* ----- Site engagement report ----- */
$report = null;
if ($view === 'reports') {
    $d = db();

    $count = static fn(string $sql): int => (int)$d->query($sql)->fetchColumn();
    $report = [
        'views_30'    => $count("SELECT COUNT(*) FROM events WHERE type='pageview' AND created_at >= NOW() - INTERVAL 30 DAY"),
        'visitors_30' => $count("SELECT COUNT(DISTINCT visitor) FROM events WHERE type='pageview' AND created_at >= NOW() - INTERVAL 30 DAY"),
        'quotes_30'   => $count("SELECT COUNT(*) FROM events WHERE type='quote_click' AND created_at >= NOW() - INTERVAL 30 DAY"),
        'inq_30'      => $count("SELECT COUNT(*) FROM inquiries WHERE created_at >= NOW() - INTERVAL 30 DAY"),
    ];
    $report['conversion'] = $report['visitors_30'] > 0
        ? round($report['inq_30'] / $report['visitors_30'] * 100, 1)
        : 0.0;

    // Daily series for the last 14 days (missing days filled with zeros).
    $series = [];
    for ($i = 13; $i >= 0; $i--) {
        $day = (new DateTime("-{$i} days", new DateTimeZone('UTC')))->format('Y-m-d');
        $series[$day] = ['views' => 0, 'quotes' => 0, 'inquiries' => 0];
    }
    $sql = "SELECT DATE(created_at) d, type, COUNT(*) c FROM events
            WHERE created_at >= NOW() - INTERVAL 14 DAY GROUP BY d, type";
    foreach ($d->query($sql) as $r) {
        if (!isset($series[$r['d']])) continue;
        if ($r['type'] === 'pageview')    $series[$r['d']]['views']  = (int)$r['c'];
        if ($r['type'] === 'quote_click') $series[$r['d']]['quotes'] = (int)$r['c'];
    }
    foreach ($d->query("SELECT DATE(created_at) d, COUNT(*) c FROM inquiries
                        WHERE created_at >= NOW() - INTERVAL 14 DAY GROUP BY d") as $r) {
        if (isset($series[$r['d']])) $series[$r['d']]['inquiries'] = (int)$r['c'];
    }
    $report['series']    = $series;
    $report['series_max'] = max(1, ...array_values(array_map(static fn($x) => $x['views'], $series)));

    $report['top_quoted'] = $d->query(
        "SELECT label, COUNT(*) c, COUNT(DISTINCT visitor) v FROM events
         WHERE type='quote_click' AND label != '' AND created_at >= NOW() - INTERVAL 30 DAY
         GROUP BY label ORDER BY c DESC LIMIT 8"
    )->fetchAll();
    $report['top_interests'] = $d->query(
        "SELECT interest, COUNT(*) c FROM inquiries
         WHERE interest != '' AND created_at >= NOW() - INTERVAL 30 DAY
         GROUP BY interest ORDER BY c DESC LIMIT 8"
    )->fetchAll();
    $report['sources'] = $d->query(
        "SELECT source, COUNT(*) c FROM inquiries GROUP BY source ORDER BY c DESC"
    )->fetchAll();
    $report['statuses'] = $d->query(
        "SELECT status, COUNT(*) c FROM inquiries GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
}

/* ----- Coming-soon modules ----- */
$comingSoon = [
    'proforma' => [
        'crumb' => 'Proforma',
        'title' => 'Proforma Invoices',
        'head'  => 'Proforma invoicing is on the way',
        'desc'  => 'Soon you will be able to build proforma invoices for client quotes right here — pick products from the catalog, set prices and quantities, and export a ready-to-send PDF.',
        'icon'  => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
    ],
    'inventory' => [
        'crumb' => 'Stock',
        'title' => 'Stock & Inventory',
        'head'  => 'Inventory tracking is on the way',
        'desc'  => 'Soon you will be able to track machine stock levels, units on order and warehouse locations for every product in the catalog — with low-stock alerts on the dashboard.',
        'icon'  => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8M7.5 5.5l9 5"/>',
    ],
    'sales' => [
        'crumb' => 'Sales',
        'title' => 'Sales',
        'head'  => 'Sales records are on the way',
        'desc'  => 'Soon you will be able to log completed sales, link them to client inquiries and proformas, and follow revenue by sector, brand and month — right from this panel.',
        'icon'  => '<circle cx="9" cy="21" r="1.5"/><circle cx="19" cy="21" r="1.5"/><path d="M2 3h3l3 13h11l2-9H6"/>',
    ],
];

$settings = et_settings();
$titles = [
    'dashboard' => 'Overview',
    'products'  => 'Products',
    'product'   => $editProduct ? 'Edit Product' : 'Add Product',
    'inquiries' => 'Client Inquiries',
    'reports'   => 'Site Reports',
    'proforma'  => 'Proforma',
    'inventory' => 'Stock & Inventory',
    'sales'     => 'Sales',
    'settings'  => 'Settings',
];
$pageTitle = $titles[$view] ?? 'Overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — EthioTractors Admin</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/logo.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php // Version the styles by mtime so browsers can't pair cached CSS with new markup. ?>
<link rel="stylesheet" href="assets/tokens.css?v=<?= filemtime(__DIR__ . '/assets/tokens.css') ?>">
<link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
<div class="shell">

  <!-- ======= Sidebar ======= -->
  <aside class="sidebar" id="sidebar">
    <a class="sb-logo" href="admin.php">
      <img src="assets/logo.png" alt="Ethio Tractor" class="sb-logo-img" width="160" height="46">
      <span><small>Admin Panel</small></span>
    </a>
    <nav class="sb-nav">
      <a href="admin.php" class="<?= $view === 'dashboard' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
        Overview
      </a>
      <a href="admin.php?view=products" class="<?= in_array($view, ['products', 'product'], true) ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v8"/></svg>
        Products
      </a>
      <a href="admin.php?view=inquiries" class="<?= $view === 'inquiries' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        Inquiries
        <?php if ($newCount): ?><span class="badge"><?= $newCount ?></span><?php endif; ?>
      </a>
      <a href="admin.php?view=reports" class="<?= $view === 'reports' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M7 15l4-6 3 4 5-8"/></svg>
        Site Reports
      </a>
      <a href="admin.php?view=proforma" class="<?= $view === 'proforma' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
        Proforma
        <span class="badge soon">Soon</span>
      </a>
      <a href="admin.php?view=inventory" class="<?= $view === 'inventory' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8M7.5 5.5l9 5"/></svg>
        Stock &amp; Inventory
        <span class="badge soon">Soon</span>
      </a>
      <a href="admin.php?view=sales" class="<?= $view === 'sales' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><circle cx="9" cy="21" r="1.5"/><circle cx="19" cy="21" r="1.5"/><path d="M2 3h3l3 13h11l2-9H6"/></svg>
        Sales
        <span class="badge soon">Soon</span>
      </a>
      <a href="admin.php?view=settings" class="<?= $view === 'settings' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1-1.6 1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.6-1 1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></svg>
        Settings
      </a>
      <div class="sb-sep"></div>
      <a href="index.php" target="_blank">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 010 20 15 15 0 010-20z"/></svg>
        View Website ↗
      </a>
    </nav>
    <div class="sb-foot">
      <div class="who">Signed in as <strong><?= e(auth_user()['username']) ?></strong></div>
      <form method="post" action="admin.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit">Sign Out</button>
      </form>
    </div>
  </aside>

  <!-- ======= Content ======= -->
  <main class="content">
    <?php if ($flash): ?>
    <div class="flash <?= $flash['type'] === 'error' ? 'error' : '' ?>">
      <?php if ($flash['type'] === 'error'): ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
      <?php else: ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.1V12a10 10 0 11-5.9-9.1"/><path d="M22 4L12 14l-3-3"/></svg>
      <?php endif; ?>
      <?= e($flash['message']) ?>
    </div>
    <?php endif; ?>

<?php /* ============ DASHBOARD ============ */ if ($view === 'dashboard'): ?>
    <div class="page-head">
      <div>
        <div class="crumb">Admin / Overview</div>
        <h1>Dashboard</h1>
      </div>
      <div class="page-actions">
        <a class="btn btn-solid" href="admin.php?view=product">+ Add Product</a>
        <a class="btn" href="admin.php?view=inquiries">Open Inbox</a>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="k">Catalog</div>
        <div class="v"><?= $dash['products'] ?></div>
        <div class="d">Published product lines</div>
      </div>
      <div class="stat-card gold">
        <div class="k">New Inquiries</div>
        <div class="v"><?= $newCount ?></div>
        <div class="d">Awaiting your response</div>
      </div>
      <div class="stat-card steel">
        <div class="k">All Inquiries</div>
        <div class="v"><?= $dash['total_inq'] ?></div>
        <div class="d">Received in total</div>
      </div>
      <div class="stat-card green">
        <div class="k">Newsletter</div>
        <div class="v"><?= $dash['news'] ?></div>
        <div class="d">Subscribed contacts</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Latest Client Inquiries</h2>
        <a class="btn btn-sm btn-ghost" href="admin.php?view=inquiries">View all →</a>
      </div>
      <div class="table-scroll">
        <table>
          <thead><tr><th>Client</th><th>Interest</th><th>Contact</th><th>Received</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (!$dash['recent']): ?>
            <tr><td colspan="5" class="t-empty">No inquiries yet — they will appear here as soon as someone uses the contact form.</td></tr>
            <?php endif; ?>
            <?php foreach ($dash['recent'] as $q): ?>
            <tr>
              <td><strong><?= e($q['name']) ?></strong><?= $q['company'] ? '<span class="sub">' . e($q['company']) . '</span>' : '' ?></td>
              <td><?= e($q['interest'] ?: '—') ?><span class="sub"><?= e($q['industry']) ?></span></td>
              <td><?= e($q['email'] ?: $q['phone'] ?: '—') ?></td>
              <td class="mono" style="font-size:.74rem"><?= e(et_when($q['created_at'])) ?></td>
              <td><span class="chip <?= e($q['status']) ?>"><?= e($q['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h2>Catalog by Sector</h2></div>
      <div class="panel-body" style="display:flex;gap:14px;flex-wrap:wrap">
        <?php foreach (ET_SECTORS as $key => $label): ?>
        <a class="filter-tab" href="admin.php?view=products&sector=<?= $key ?>">
          <?= $label ?> — <?= (int)($dash['by_sector'][$key] ?? 0) ?> products
        </a>
        <?php endforeach; ?>
      </div>
    </div>

<?php /* ============ PRODUCTS LIST ============ */ elseif ($view === 'products'): ?>
    <div class="page-head">
      <div>
        <div class="crumb">Admin / Catalog</div>
        <h1>Products</h1>
      </div>
      <div class="page-actions">
        <a class="btn btn-solid" href="admin.php?view=product">+ Add Product</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="filter-tabs">
          <a class="filter-tab <?= $sectorFilter === 'all' ? 'active' : '' ?>" href="admin.php?view=products">All</a>
          <?php foreach (ET_SECTORS as $key => $label): ?>
          <a class="filter-tab <?= $sectorFilter === $key ? 'active' : '' ?>" href="admin.php?view=products&sector=<?= $key ?>"><?= $label ?></a>
          <?php endforeach; ?>
        </div>
        <input type="search" id="prodSearch" placeholder="Filter by name…" aria-label="Filter products">
      </div>
      <div class="reorder-bar">
        <span class="reorder-hint">Drag the <span class="rh-grip" aria-hidden="true">⠿</span> handle to set the order products appear in on the website. Keyboard: focus a handle and press ↑ or ↓.</span>
        <span class="reorder-status" id="reorderStatus" role="status" aria-live="polite"></span>
      </div>
      <div class="table-scroll">
        <table id="prodTable" data-csrf="<?= e(csrf_token()) ?>">
          <thead><tr><th style="width:44px"><span class="sr-only">Reorder</span></th><th style="width:56px"></th><th>Product</th><th>Sector</th><th>Brand</th><th>Tags</th><th style="text-align:right">Actions</th></tr></thead>
          <tbody id="prodRows">
            <?php if (!$productList): ?>
            <tr><td colspan="7" class="t-empty">No products in this view — add one with the button above.</td></tr>
            <?php endif; ?>
            <?php foreach ($productList as $p): ?>
            <tr data-id="<?= (int)$p['id'] ?>" data-name="<?= e(mb_strtolower($p['name'] . ' ' . $p['category'] . ' ' . $p['brand'])) ?>">
              <td class="t-grip">
                <button type="button" class="drag-handle" aria-label="Reorder “<?= e($p['name']) ?>” — hold to drag, or press arrow up and down">⠿</button>
              </td>
              <td>
                <?php if ($p['image_url']): ?>
                <img class="t-thumb" src="<?= e($p['image_url']) ?>" alt="" loading="lazy">
                <?php else: ?>
                <span style="display:inline-block;width:38px;height:38px;color:var(--ink)"><?= et_icon($p['icon']) ?></span>
                <?php endif; ?>
              </td>
              <td><strong><?= e($p['name']) ?></strong><span class="sub"><?= e($p['category'] ?: '—') ?></span></td>
              <td><span class="chip <?= e($p['sector']) ?>"><?= e(ET_SECTORS[$p['sector']]) ?></span></td>
              <td><?= e($p['brand'] ?: '—') ?></td>
              <td style="font-size:.76rem;color:var(--muted)"><?= e($p['tags'] ?: '—') ?></td>
              <td>
                <div class="t-actions">
                  <a class="btn btn-sm" href="admin.php?view=product&id=<?= $p['id'] ?>">Edit</a>
                  <form method="post" action="admin.php" data-confirm="Delete “<?= e($p['name']) ?>” from the catalog? This cannot be undone.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="product_delete">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php /* ============ PRODUCT FORM ============ */ elseif ($view === 'product'):
    $p = $editProduct ?: ['id' => 0, 'name' => '', 'sector' => 'agriculture', 'category' => '', 'brand' => '', 'description' => '', 'specs' => '[]', 'tags' => '', 'icon' => 'machine', 'image_url' => ''];
    $pSpecs = $editProduct ? et_product_specs($editProduct) : [];
?>
    <div class="page-head">
      <div>
        <div class="crumb">Admin / Catalog / <?= $editProduct ? 'Edit' : 'New' ?></div>
        <h1><?= $editProduct ? 'Edit Product' : 'Add Product' ?></h1>
      </div>
      <div class="page-actions">
        <a class="btn btn-ghost" href="admin.php?view=products">← Back to list</a>
      </div>
    </div>

    <form method="post" action="admin.php" id="productForm" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="product_save">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <div class="form-grid">
        <div class="form-card">
          <h2>Product Details</h2>
          <div class="field">
            <label for="p-name">Product Name *</label>
            <input id="p-name" name="name" type="text" required maxlength="160" value="<?= e($p['name']) ?>" placeholder="e.g. Disc Harrow">
          </div>
          <div class="row-3">
            <div class="field">
              <label for="p-sector">Sector *</label>
              <select id="p-sector" name="sector" required>
                <?php foreach (ET_SECTORS as $key => $label): ?>
                <option value="<?= $key ?>" <?= $p['sector'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="p-category">Category</label>
              <input id="p-category" name="category" type="text" maxlength="80" value="<?= e($p['category']) ?>" placeholder="e.g. Ploughs">
            </div>
            <div class="field">
              <label for="p-brand">Brand</label>
              <input id="p-brand" name="brand" type="text" maxlength="80" value="<?= e($p['brand']) ?>" placeholder="e.g. Zoomlion">
            </div>
          </div>
          <div class="field">
            <label for="p-desc">Description</label>
            <textarea id="p-desc" name="description" maxlength="600" placeholder="One or two sentences shown on the product card"><?= e($p['description']) ?></textarea>
          </div>
          <div class="field">
            <label for="p-tags">Tags <span style="text-transform:none;letter-spacing:0">(comma separated, first two are shown)</span></label>
            <input id="p-tags" name="tags" type="text" maxlength="200" value="<?= e($p['tags']) ?>" placeholder="e.g. Earthmoving, Heavy Duty">
          </div>
          <div class="field">
            <label>Specifications <span style="text-transform:none;letter-spacing:0">(shown as a mini spec sheet)</span></label>
            <div id="specRows" style="display:flex;flex-direction:column;gap:10px">
              <?php foreach ($pSpecs as $sp): ?>
              <div class="spec-row">
                <input type="text" name="spec_k[]" maxlength="60" value="<?= e($sp[0] ?? '') ?>" placeholder="Label e.g. Power req.">
                <input type="text" name="spec_v[]" maxlength="100" value="<?= e($sp[1] ?? '') ?>" placeholder="Value e.g. 45 – 130 hp">
                <button type="button" class="rm" aria-label="Remove specification">×</button>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" id="addSpec" style="align-self:flex-start;margin-top:8px">+ Add specification</button>
          </div>
        </div>

        <div class="form-card">
          <h2>Card Artwork</h2>
          <div class="field">
            <label for="p-photo">Product Photo</label>
            <div class="photo-box">
              <img id="photoPreview" src="<?= e($p['image_url']) ?>" alt="Product photo preview" <?= $p['image_url'] ? '' : 'hidden' ?>>
              <div class="photo-empty" id="photoEmpty" <?= $p['image_url'] ? 'hidden' : '' ?>>No photo yet — the fallback icon below is shown on the card.</div>
            </div>
            <input id="p-photo" name="photo" type="file" class="file-input" accept="image/jpeg,image/png,image/webp,image/gif">
            <span class="fhelp">Upload a JPG, PNG, WebP or GIF up to 5 MB. The photo replaces the icon on the product card.</span>
            <?php if ($p['image_url']): ?>
            <label class="check-line"><input type="checkbox" name="remove_photo" value="1" id="removePhoto"> Remove the current photo</label>
            <?php endif; ?>
          </div>
          <div class="field">
            <label for="p-image">…or paste an image link <span style="font-weight:500">(optional)</span></label>
            <input id="p-image" name="image_url" type="text" maxlength="500" value="<?= e($p['image_url']) ?>" placeholder="https://…/machine.jpg">
          </div>
          <div class="field">
            <label for="p-icon">Fallback Line-Art Icon</label>
            <select id="p-icon" name="icon">
              <?php foreach (array_keys($icons) as $key): ?>
              <option value="<?= $key ?>" <?= $p['icon'] === $key ? 'selected' : '' ?>><?= ucfirst($key) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="icon-preview">
            <div class="ipv" id="iconPreview"><?= et_icon($p['icon']) ?></div>
            <span>Shown on the product card whenever no photo is set.</span>
          </div>
          <button type="submit" class="btn btn-solid" style="justify-content:center;margin-top:6px">
            <?= $editProduct ? 'Save Changes' : 'Add to Catalog' ?> →
          </button>
        </div>
      </div>
    </form>
    <script>window.ET_ICONS = <?= json_encode($icons, JSON_UNESCAPED_SLASHES) ?>;</script>

<?php /* ============ INQUIRIES ============ */ elseif ($view === 'inquiries'):
    $back = $statusFilter !== 'all' ? '&status=' . $statusFilter : '';
?>
    <div class="page-head">
      <div>
        <div class="crumb">Admin / Inbox</div>
        <h1>Client Inquiries</h1>
      </div>
      <div class="page-actions">
        <a class="btn" href="admin.php?view=export">⬇ Export CSV</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div class="filter-tabs">
          <a class="filter-tab <?= $statusFilter === 'all' ? 'active' : '' ?>" href="admin.php?view=inquiries">All (<?= $inqCounts['all'] ?>)</a>
          <a class="filter-tab <?= $statusFilter === 'new' ? 'active' : '' ?>" href="admin.php?view=inquiries&status=new">New (<?= $inqCounts['new'] ?>)</a>
          <a class="filter-tab <?= $statusFilter === 'contacted' ? 'active' : '' ?>" href="admin.php?view=inquiries&status=contacted">Contacted (<?= $inqCounts['contacted'] ?>)</a>
          <a class="filter-tab <?= $statusFilter === 'closed' ? 'active' : '' ?>" href="admin.php?view=inquiries&status=closed">Closed (<?= $inqCounts['closed'] ?>)</a>
        </div>
        <span class="fhelp">Click a row to read the full message and respond.</span>
      </div>
      <div class="table-scroll">
        <table>
          <thead><tr><th>Client</th><th>Interest</th><th>Source</th><th>Received</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (!$inquiryList): ?>
            <tr><td colspan="5" class="t-empty">Nothing here — inquiries from the website contact form will land in this inbox.</td></tr>
            <?php endif; ?>
            <?php foreach ($inquiryList as $q): ?>
            <tr class="inq-row <?= $q['status'] === 'new' ? 'unread' : '' ?>" data-inq="<?= $q['id'] ?>" tabindex="0" role="button" aria-expanded="false">
              <td><strong><?= e($q['name']) ?></strong><?= $q['company'] ? '<span class="sub">' . e($q['company']) . '</span>' : '' ?></td>
              <td><?= e($q['interest'] ?: '—') ?><?= $q['industry'] ? '<span class="sub">' . e($q['industry']) . '</span>' : '' ?></td>
              <td><span class="chip <?= e($q['source']) ?>"><?= e($q['source']) ?></span></td>
              <td class="mono" style="font-size:.74rem;white-space:nowrap"><?= e(et_when($q['created_at'])) ?></td>
              <td><span class="chip <?= e($q['status']) ?>"><?= e($q['status']) ?></span></td>
            </tr>
            <tr class="inq-detail" id="inq-<?= $q['id'] ?>" hidden>
              <td colspan="5">
                <div class="inq-detail-inner">
                  <div>
                    <div class="mono" style="font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:8px">Message</div>
                    <div class="inq-msg"><?= e($q['message'] ?: '(no message)') ?></div>
                  </div>
                  <dl class="inq-meta">
                    <div><dt>Email</dt><dd><?= $q['email'] ? '<a href="mailto:' . e($q['email']) . '">' . e($q['email']) . '</a>' : '—' ?></dd></div>
                    <div><dt>Phone</dt><dd><?= $q['phone'] ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $q['phone'])) . '">' . e($q['phone']) . '</a>' : '—' ?></dd></div>
                    <div><dt>Company / Farm</dt><dd><?= e($q['company'] ?: '—') ?></dd></div>
                    <div><dt>Industry</dt><dd><?= e($q['industry'] ?: '—') ?></dd></div>
                    <div><dt>Product of interest</dt><dd><?= e($q['interest'] ?: '—') ?></dd></div>
                  </dl>
                  <div class="inq-detail-actions">
                    <?php if ($q['email']): ?>
                    <a class="btn btn-sm btn-solid" href="mailto:<?= e($q['email']) ?>?subject=<?= rawurlencode('Re: Your inquiry to ' . $settings['company_name'] . ($q['interest'] ? ' — ' . $q['interest'] : '')) ?>">✉ Reply by Email</a>
                    <?php endif; ?>
                    <?php if ($q['phone']): ?>
                    <a class="btn btn-sm" href="tel:<?= e(preg_replace('/[^+\d]/', '', $q['phone'])) ?>">✆ Call Client</a>
                    <?php endif; ?>
                    <?php foreach ([['contacted', 'Mark Contacted'], ['closed', 'Mark Closed'], ['new', 'Mark New']] as [$st, $lbl]): if ($q['status'] === $st) continue; ?>
                    <form method="post" action="admin.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="inquiry_status">
                      <input type="hidden" name="id" value="<?= $q['id'] ?>">
                      <input type="hidden" name="status" value="<?= $st ?>">
                      <input type="hidden" name="back" value="<?= e($back) ?>">
                      <button type="submit" class="btn btn-sm btn-ghost"><?= $lbl ?></button>
                    </form>
                    <?php endforeach; ?>
                    <form method="post" action="admin.php" data-confirm="Delete this inquiry from <?= e($q['name']) ?>? This cannot be undone." style="margin-left:auto">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="inquiry_delete">
                      <input type="hidden" name="id" value="<?= $q['id'] ?>">
                      <input type="hidden" name="back" value="<?= e($back) ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php /* ============ SITE REPORTS ============ */ elseif ($view === 'reports'): ?>
    <div class="page-head">
      <div>
        <div class="crumb">Admin / Reports</div>
        <h1>Site Engagement</h1>
      </div>
      <div class="page-actions">
        <a class="btn" href="admin.php?view=export">⬇ Export Inquiries CSV</a>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card steel">
        <div class="k">Page Views</div>
        <div class="v"><?= $report['views_30'] ?></div>
        <div class="d">Last 30 days</div>
      </div>
      <div class="stat-card">
        <div class="k">Unique Visitors</div>
        <div class="v"><?= $report['visitors_30'] ?></div>
        <div class="d">Last 30 days</div>
      </div>
      <div class="stat-card gold">
        <div class="k">Quote Clicks</div>
        <div class="v"><?= $report['quotes_30'] ?></div>
        <div class="d">“Get a quote” taps, last 30 days</div>
      </div>
      <div class="stat-card green">
        <div class="k">Conversion</div>
        <div class="v"><?= $report['conversion'] ?>%</div>
        <div class="d"><?= $report['inq_30'] ?> inquiries / <?= $report['visitors_30'] ?> visitors</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Daily Activity — Last 14 Days</h2>
        <div class="chart-legend">
          <span><i class="sw views"></i> Page views</span>
          <span><i class="sw quotes"></i> Quote clicks</span>
          <span><i class="sw inqs"></i> Inquiries</span>
        </div>
      </div>
      <div class="panel-body">
        <div class="chart">
          <?php foreach ($report['series'] as $day => $row):
              $h = static fn(int $n) => max($n > 0 ? 4 : 0, (int)round($n / $report['series_max'] * 100));
          ?>
          <div class="chart-day" title="<?= e($day) ?> — <?= $row['views'] ?> views · <?= $row['quotes'] ?> quote clicks · <?= $row['inquiries'] ?> inquiries">
            <div class="bars">
              <div class="bar views" style="height:<?= $h($row['views']) ?>%"></div>
              <div class="bar quotes" style="height:<?= $h($row['quotes']) ?>%"></div>
              <div class="bar inqs" style="height:<?= $h($row['inquiries']) ?>%"></div>
            </div>
            <span class="lbl"><?= e((new DateTime($day))->format('j M')) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($report['views_30'] === 0): ?>
        <p class="t-empty" style="padding:18px 0 0 !important">No visits recorded yet — data starts collecting from the moment someone opens the public website.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="report-cols">
      <div class="panel">
        <div class="panel-head"><h2>Most Quoted Products</h2><span class="fhelp">Last 30 days</span></div>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Product</th><th style="text-align:right">Clicks</th><th style="text-align:right">Visitors</th></tr></thead>
            <tbody>
              <?php if (!$report['top_quoted']): ?>
              <tr><td colspan="3" class="t-empty">No “Get a quote” clicks recorded yet.</td></tr>
              <?php endif; ?>
              <?php foreach ($report['top_quoted'] as $r): ?>
              <tr>
                <td><strong><?= e($r['label']) ?></strong></td>
                <td style="text-align:right"><?= (int)$r['c'] ?></td>
                <td style="text-align:right"><?= (int)$r['v'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2>Top Inquiry Interests</h2><span class="fhelp">Last 30 days</span></div>
        <div class="table-scroll">
          <table>
            <thead><tr><th>Product of interest</th><th style="text-align:right">Inquiries</th></tr></thead>
            <tbody>
              <?php if (!$report['top_interests']): ?>
              <tr><td colspan="2" class="t-empty">No inquiries with a named product yet.</td></tr>
              <?php endif; ?>
              <?php foreach ($report['top_interests'] as $r): ?>
              <tr>
                <td><strong><?= e($r['interest']) ?></strong></td>
                <td style="text-align:right"><?= (int)$r['c'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2>Inquiry Pipeline</h2><span class="fhelp">All time</span></div>
        <div class="panel-body report-pills">
          <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $st => $lbl): ?>
          <div class="report-pill">
            <span class="chip <?= $st ?>"><?= $lbl ?></span>
            <strong><?= (int)($report['statuses'][$st] ?? 0) ?></strong>
          </div>
          <?php endforeach; ?>
          <div class="pill-sep"></div>
          <?php foreach ($report['sources'] as $r): ?>
          <div class="report-pill">
            <span class="chip <?= e($r['source']) ?>"><?= e(ucfirst($r['source'])) ?></span>
            <strong><?= (int)$r['c'] ?></strong>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

<?php /* ============ COMING SOON MODULES ============ */ elseif (isset($comingSoon[$view])):
    $cs = $comingSoon[$view];
?>
    <div class="page-head">
      <div>
        <div class="crumb">Admin / <?= e($cs['crumb']) ?></div>
        <h1><?= e($cs['title']) ?></h1>
      </div>
    </div>

    <div class="panel">
      <div class="coming-soon">
        <div class="cs-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><?= $cs['icon'] ?></svg>
        </div>
        <span class="cs-tag">Coming Soon</span>
        <h2><?= e($cs['head']) ?></h2>
        <p><?= e($cs['desc']) ?></p>
        <a class="btn btn-ghost" href="admin.php?view=inquiries">Review inquiries in the meantime →</a>
      </div>
    </div>

<?php /* ============ SETTINGS ============ */ elseif ($view === 'settings'): ?>
    <div class="page-head">
      <div>
        <div class="crumb">Admin / Configuration</div>
        <h1>Settings</h1>
      </div>
    </div>

    <div class="settings-grid">
      <form method="post" action="admin.php" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="settings_save">
        <h2>Company &amp; Contact Info</h2>
        <p class="fhelp" style="margin-top:-6px">Shown on the public website — top bar, contact section, footer and map.</p>
        <div class="row-2">
          <div class="field"><label>Company Name</label><input name="company_name" maxlength="120" value="<?= e($settings['company_name']) ?>"></div>
          <div class="field"><label>Trade License No.</label><input name="trade_license" maxlength="80" value="<?= e($settings['trade_license']) ?>" placeholder="Optional"></div>
        </div>
        <div class="row-3">
          <div class="field"><label>Phone</label><input name="phone" maxlength="60" value="<?= e($settings['phone'] ?? '') ?>" placeholder="0960 ..."></div>
          <div class="field"><label>Phone 2</label><input name="phone2" maxlength="60" value="<?= e($settings['phone2'] ?? '') ?>" placeholder="Optional"></div>
          <div class="field"><label>Phone 3</label><input name="phone3" maxlength="60" value="<?= e($settings['phone3'] ?? '') ?>" placeholder="Optional"></div>
        </div>
        <div class="field"><label>Email</label><input name="email" type="email" maxlength="160" value="<?= e($settings['email']) ?>"></div>
        <div class="field"><label>Head Office Address</label><input name="address" maxlength="240" value="<?= e($settings['address']) ?>"></div>
        <div class="row-2">
          <div class="field"><label>Branches</label><input name="branches" maxlength="240" value="<?= e($settings['branches']) ?>" placeholder="e.g. Adama · Bahir Dar (optional)"></div>
          <div class="field"><label>Working Hours</label><input name="hours" maxlength="120" value="<?= e($settings['hours']) ?>"></div>
        </div>
        <div class="field">
          <label>Map Location</label>
          <input name="map_query" maxlength="240" value="<?= e($settings['map_query']) ?>" placeholder="e.g. Bole, Addis Ababa, Ethiopia">
          <span class="fhelp">Address or place name used for the Google Map on the contact page.</span>
        </div>
        <div class="row-3">
          <div class="field"><label>Telegram URL</label><input name="telegram" maxlength="240" value="<?= e($settings['telegram']) ?>" placeholder="https://t.me/…"></div>
          <div class="field"><label>Facebook URL</label><input name="facebook" maxlength="240" value="<?= e($settings['facebook']) ?>" placeholder="https://facebook.com/…"></div>
          <div class="field"><label>LinkedIn URL</label><input name="linkedin" maxlength="240" value="<?= e($settings['linkedin']) ?>" placeholder="https://linkedin.com/…"></div>
        </div>
        <button type="submit" class="btn btn-solid" style="align-self:flex-start">Save Settings →</button>
      </form>

      <div style="display:flex;flex-direction:column;gap:26px">
        <form method="post" action="admin.php" class="form-card">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="account_save">
          <h2>Admin Account</h2>
          <div class="field"><label>Username</label><input name="username" maxlength="60" value="<?= e(auth_user()['username']) ?>" autocomplete="username"></div>
          <div class="field">
            <label>New Password</label>
            <input name="new_password" type="password" minlength="8" placeholder="Leave empty to keep current" autocomplete="new-password">
            <span class="fhelp">Minimum 8 characters.</span>
          </div>
          <div class="field">
            <label>Current Password *</label>
            <input name="current_password" type="password" required autocomplete="current-password" placeholder="Required to confirm any change">
          </div>
          <button type="submit" class="btn btn-solid" style="align-self:flex-start">Update Account →</button>
        </form>

        <div class="form-card danger-zone">
          <h2>Danger Zone</h2>
          <p>Restore the catalog to the original import list (removes every product you added or edited).</p>
          <form method="post" action="admin.php" data-confirm="Replace the entire catalog with the default product list? All custom products and edits will be lost.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_catalog">
            <button type="submit" class="btn btn-sm btn-danger">Restore Default Catalog</button>
          </form>
          <p style="margin-top:8px">Remove all inquiries that are marked as closed.</p>
          <form method="post" action="admin.php" data-confirm="Permanently delete all closed inquiries?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_inquiries">
            <button type="submit" class="btn btn-sm btn-danger">Delete Closed Inquiries</button>
          </form>
        </div>
      </div>
    </div>

<?php endif; ?>
  </main>
</div>

<button class="admin-menu-btn" id="adminMenuBtn" aria-label="Toggle admin menu">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
</button>

<dialog class="confirm" id="confirmDialog">
  <div class="confirm-card">
    <h3>Are you sure?</h3>
    <p id="confirmText"></p>
    <div class="row">
      <button type="button" class="btn btn-sm btn-ghost" id="confirmCancel">Cancel</button>
      <button type="button" class="btn btn-sm btn-danger" id="confirmOk">Yes, do it</button>
    </div>
  </div>
</dialog>

<script src="assets/admin.js?v=<?= filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
