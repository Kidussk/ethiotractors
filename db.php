<?php
/**
 * EthioTractors — shared bootstrap: PDO/MySQL connection, schema, seed data, helpers.
 * Requires PHP 8+ with pdo_mysql. Credentials live in config.php.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie: no JS access, no cross-site sends,
    // HTTPS-only when available, and reject uninitialized session IDs.
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('ethiotractors_sid');
    session_start();
}

require __DIR__ . '/config.php';

/** HTML-escape shortcut. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** PDO singleton. Creates schema + seeds on first run. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . ET_DB_HOST . ';dbname=' . ET_DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, ET_DB_USER, ET_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Store timestamps in UTC (the admin panel converts to Africa/Addis_Ababa).
    $pdo->exec("SET time_zone = '+00:00'");

    et_migrate($pdo);
    // Seed only when the database is empty (first run).
    $fresh = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    if ($fresh) {
        et_seed($pdo);
    }
    return $pdo;
}

function et_migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(255) NOT NULL,
            sector      VARCHAR(20) NOT NULL,
            category    VARCHAR(120) NOT NULL DEFAULT '',
            brand       VARCHAR(120) NOT NULL DEFAULT '',
            description TEXT NOT NULL,
            specs       TEXT NOT NULL,
            tags        VARCHAR(255) NOT NULL DEFAULT '',
            icon        VARCHAR(40) NOT NULL DEFAULT 'machine',
            image_url   VARCHAR(500) NOT NULL DEFAULT '',
            sort        INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inquiries (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(120) NOT NULL,
            company    VARCHAR(120) NOT NULL DEFAULT '',
            email      VARCHAR(160) NOT NULL DEFAULT '',
            phone      VARCHAR(60) NOT NULL DEFAULT '',
            industry   VARCHAR(40) NOT NULL DEFAULT '',
            interest   VARCHAR(200) NOT NULL DEFAULT '',
            message    TEXT NOT NULL,
            source     VARCHAR(20) NOT NULL DEFAULT 'contact',
            status     VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            username  VARCHAR(120) NOT NULL UNIQUE,
            pass_hash VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            `key`   VARCHAR(64) PRIMARY KEY,
            `value` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            type       VARCHAR(40) NOT NULL,
            label      VARCHAR(200) NOT NULL DEFAULT '',
            visitor    VARCHAR(32) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_events_type_date (type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function et_seed(PDO $pdo): void
{
    // Default admin account — change the password in Admin → Settings after first login.
    $stmt = $pdo->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)');
    $stmt->execute(['admin', password_hash('ethio2026', PASSWORD_DEFAULT)]);

    $defaults = [
        'company_name'  => 'EthioTractors PLC',
        'tagline'       => 'Imported Machinery — Built for Ethiopia’s Work',
        'phone'         => '0921692915',
        'phone2'        => '',
        'email'         => 'info@ethiotractors.com',
        'address'       => 'Bole Road, Addis Ababa, Ethiopia',
        'branches'      => '',
        'hours'         => 'Mon – Sat · 8:00 AM – 6:00 PM',
        'map_query'     => 'Bole, Addis Ababa, Ethiopia',
        'telegram'      => '',
        'facebook'      => '',
        'linkedin'      => '',
        'trade_license' => '',
    ];
    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    et_seed_products($pdo);
}

/** Insert the default product catalog (used on first run and by "restore defaults"). */
function et_seed_products(PDO $pdo): void
{
    $S = static fn(array $pairs) => json_encode($pairs, JSON_UNESCAPED_UNICODE);
    $seed = [
        // ---- Agriculture — power & harvest (Zoomlion) ----
        ['Zoomlion Tractor Series', 'agriculture', 'Tractors', 'Zoomlion',
            'Row-crop and utility tractors sized for smallholder plots up to commercial farm operations.',
            $S([]), 'Power Unit', 'tractor'],
        ['Combine Harvester', 'agriculture', 'Harvesters', 'Zoomlion',
            'Grain harvesting with integrated threshing and cleaning for cereal crops.',
            $S([]), 'Harvest', 'harvester'],
        ['Sugarcane Harvester', 'agriculture', 'Harvesters', 'Zoomlion',
            'Purpose-built cane cutting and billeting for large-scale plantation operations.',
            $S([]), 'Harvest', 'cane'],
        ['Baler', 'agriculture', 'Post-Harvest', 'Zoomlion',
            'Compresses hay or straw into transportable, storable bales after harvest.',
            $S([]), 'Post-Harvest', 'baler'],
        ['Grain Dryer', 'agriculture', 'Post-Harvest', 'Zoomlion',
            'Reduces post-harvest moisture loss and supports safe long-term grain storage.',
            $S([]), 'Post-Harvest', 'dryer'],

        // ---- Agriculture — ploughs (Doğanlar) ----
        ['Gas-Safe Reversible Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Hydraulic-piston escape mechanism lets each body clear rocky ground without damage — adjustable 12"–18" working width.',
            $S([['Bodies', '4 / 5 / 6'], ['Weight', '940 – 1,120 kg'], ['Power req.', '110 – 250 hp']]),
            '140×120 Profile', 'plough'],
        ['Gas-Safe Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Fixed gas-safe plough with hydraulic body protection for rocky and demanding soils.',
            $S([['Bodies', '4 – 6'], ['Weight', '1,420 – 1,840 kg'], ['Power req.', '110 – 250 hp']]),
            '16" Blade', 'plough'],
        ['Spring Profile Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Independent spring-body protection with hydraulic working-width adjustment — 3 to 5 body configurations.',
            $S([['Bodies', '3 / 4 / 5'], ['Weight', '600 – 995 kg'], ['Power req.', '45 – 130 hp']]),
            '100–140 Profile', 'plough'],
        ['Rotary Spring Profile Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Rotary-reset spring bodies for continuous ploughing in stony fields without stopping to reset.',
            $S([['Bodies', '4 – 5'], ['Weight', '1,315 – 1,550 kg'], ['Power req.', '85 – 165 hp']]),
            '12–14" Blade', 'plough'],
        ['Pin Cutting Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Shear-pin protected bodies in 4 to 7 body configurations for general field ploughing.',
            $S([['Bodies', '4 – 7'], ['Weight', '685 – 1,782 kg'], ['Power req.', '80 – 245 hp']]),
            '12–16" Blade', 'plough'],
        ['Rotary Mounted Pin Cutting Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Rotary-mounted shear-pin plough for smaller tractors and tighter field patterns.',
            $S([['Bodies', '2 – 5'], ['Weight', '750 – 1,355 kg'], ['Power req.', '65 – 175 hp']]),
            '12–16" Blade', 'plough'],
        ['Standard Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Economical fixed mouldboard plough for routine primary tillage.',
            $S([['Bodies', '2 – 6'], ['Weight', '160 – 795 kg'], ['Power req.', '30 – 130 hp']]),
            '8–16" Blade', 'plough'],

        // ---- Agriculture — chisels & harrows (Doğanlar) ----
        ['Spring Chisel', 'agriculture', 'Chisels & Harrows', 'Doğanlar',
            'Breaks up hardpan without inverting the soil layer. Independent spring tines deflect off rock and reset automatically.',
            $S([['Feet', '5 – 11'], ['Depth', '375 – 450 mm'], ['Power req.', '50 – 140 hp']]),
            'Deep Tillage', 'chisel'],
        ['Pin Cutting Chisel — Field / Garden', 'agriculture', 'Chisels & Harrows', 'Doğanlar',
            'Heat-treated pin-and-clamp assemblies for stubble and deep tillage in open-field and orchard/vineyard rows.',
            $S([['Feet', '7 – 11'], ['Depth', '250 – 450 mm'], ['Power req.', '45 – 130 hp']]),
            'Deep Tillage', 'chisel'],
        ['Disc Harrow', 'agriculture', 'Chisels & Harrows', 'Doğanlar',
            'Suspension-mounted discs for seedbed prep, residue cutting and post-harvest tillage.',
            $S([['Discs', '16 – 32'], ['Depth', '225 – 250 mm'], ['Power req.', '45 – 180 hp']]),
            'Seedbed', 'harrow'],

        // ---- Agriculture — cultivators & finishing (Doğanlar) ----
        ['Spring Cultivator', 'agriculture', 'Cultivators & Tillers', 'Doğanlar',
            'Loosens and aerates soil, cuts weed roots, and inter-cultivates row crops like maize, potato and sunflower.',
            $S([['Feet', '7 – 22'], ['Depth', '175 – 250 mm'], ['Power req.', '40 – 200 hp']]),
            'Row Crop', 'cultivator'],
        ['Tiller / Scissor Spring Tiller', 'agriculture', 'Cultivators & Tillers', 'Doğanlar',
            'Final seedbed preparation before planting — self-vibrating spring mechanism levels the field in one pass.',
            $S([['Width', '21 – 40 ft'], ['Power req.', '50 – 140 hp']]),
            'Seedbed', 'tiller'],
        ['Rotovator — Field / Garden / Vertical', 'agriculture', 'Cultivators & Tillers', 'Doğanlar',
            'Mixes and breaks down soil, weeds and residue into organic matter. Field, garden and vertical-tine configurations.',
            $S([['Width', '160 – 400 cm'], ['Power req.', '45 – 180 hp']]),
            'Residue', 'rotovator'],

        // ---- Construction (Zoomlion) ----
        ['Excavators — Mini to Large', 'construction', 'Earthmoving', 'Zoomlion',
            'Mini, small, medium, large and wheeled excavators for sites of every scale.',
            $S([]), 'Earthmoving', 'excavator'],
        ['Wheel & Crawler Loaders', 'construction', 'Earthmoving', 'Zoomlion',
            'Skid steer, crawler, wheel and compact track loaders for material handling and site prep.',
            $S([]), 'Earthmoving', 'loader'],
        ['Crawler Bulldozer', 'construction', 'Earthmoving', 'Zoomlion',
            'Heavy blade grading and earthmoving for road building and site clearance.',
            $S([]), 'Earthmoving', 'dozer'],
        ['Truck & Crawler Cranes', 'construction', 'Cranes & Hoisting', 'Zoomlion',
            'Truck, rough terrain, all terrain and crawler cranes for lifting and placement.',
            $S([]), 'Mobile Crane', 'crane'],
        ['Tower Cranes — Flat-Top & Luffing Jib', 'construction', 'Cranes & Hoisting', 'Zoomlion',
            'Fixed-site vertical lift for multi-storey construction projects.',
            $S([]), 'Hoisting', 'tower'],
        ['Construction Hoist', 'construction', 'Cranes & Hoisting', 'Zoomlion',
            'Personnel and material lifts for high-rise site logistics.',
            $S([]), 'Hoisting', 'hoist'],
        ['Rotary Drilling Rig', 'construction', 'Foundation & Concrete', 'Zoomlion',
            'Bored pile foundation drilling for high-load structures.',
            $S([]), 'Foundation', 'drillrig'],
        ['Truck Mixer & Concrete Pumps', 'construction', 'Foundation & Concrete', 'Zoomlion',
            'Truck mixers, truck-mounted pumps, trailer pumps and placing booms.',
            $S([]), 'Concrete', 'mixer'],
        ['Concrete Batching Plant', 'construction', 'Foundation & Concrete', 'Zoomlion',
            'On-site or centralized concrete batching for continuous supply.',
            $S([]), 'Concrete', 'plant'],

        // ---- Mining (Zoomlion) ----
        ['Mining Dump Truck', 'mining', 'Haulage', 'Zoomlion',
            'High-payload haulage built for continuous pit-to-stockpile cycles.',
            $S([]), 'Mining', 'dumptruck'],
        ['Large Mining Excavator', 'mining', 'Excavation', 'Zoomlion',
            'Mine-class excavators for overburden removal and bulk material loading.',
            $S([]), 'Mining', 'excavator'],
        ['Mobile Crushers & Screens', 'mining', 'Processing', 'Zoomlion',
            'On-site crushing and screening, plus fixed crushers for aggregate production.',
            $S([]), 'Mining', 'crusher'],
        ['Surface DTH Drill Rig', 'mining', 'Drilling', 'Zoomlion',
            'Down-the-hole drilling rigs for blast-hole and surface mining programs.',
            $S([]), 'Mining', 'drillrig'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO products (name, sector, category, brand, description, specs, tags, icon, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($seed as $i => $p) {
        $stmt->execute([...$p, $i]);
    }
}

/** All settings as key => value. */
function et_settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT `key`, `value` FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return $cache;
}

function et_save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$key, $value]);
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/* ---------- Flash messages ---------- */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/* ---------- Auth ---------- */

function auth_user(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function auth_require(): void
{
    if (!auth_user()) {
        header('Location: admin.php?view=login');
        exit;
    }
}

/* ---------- Engagement tracking ---------- */

/** Anonymous per-browser visitor key — no IP or personal data is stored. */
function et_visitor_key(): string
{
    if (empty($_SESSION['visitor_key'])) {
        $_SESSION['visitor_key'] = bin2hex(random_bytes(8));
    }
    return $_SESSION['visitor_key'];
}

/** Record an engagement event. Analytics must never break the page. */
function et_track(string $type, string $label = ''): void
{
    try {
        db()->prepare('INSERT INTO events (type, label, visitor) VALUES (?, ?, ?)')
            ->execute([$type, mb_substr(trim($label), 0, 200), et_visitor_key()]);
    } catch (Throwable) {
    }
}

/** Count one page view per request, skipping obvious bots and non-GET requests. */
function et_track_pageview(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|httpclient|preview/', $ua)) {
        return;
    }
    et_track('pageview');
}

/* ---------- Product helpers ---------- */

const ET_SECTORS = [
    'agriculture'  => 'Agriculture',
    'construction' => 'Construction',
    'mining'       => 'Mining',
];

function et_products(?string $sector = null): array
{
    if ($sector !== null && isset(ET_SECTORS[$sector])) {
        $stmt = db()->prepare('SELECT * FROM products WHERE sector = ? ORDER BY sort, id');
        $stmt->execute([$sector]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM products ORDER BY sort, id')->fetchAll();
}

function et_product_specs(array $product): array
{
    $specs = json_decode($product['specs'] ?: '[]', true);
    return is_array($specs) ? $specs : [];
}

/**
 * Line-art SVG icons keyed by machine type (inherit stroke color from CSS).
 */
function et_icons(): array
{
    $w = 'fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"';
    return [
        'tractor'    => "<svg viewBox='0 0 64 64' $w><circle cx='18' cy='46' r='9'/><circle cx='46' cy='46' r='6'/><path d='M18 37V22h14l8 15M18 22h-6M40 37h8'/></svg>",
        'harvester'  => "<svg viewBox='0 0 64 64' $w><rect x='8' y='30' width='26' height='14' rx='2'/><circle cx='16' cy='48' r='6'/><circle cx='30' cy='48' r='6'/><path d='M34 34h14l6 8v6H34'/></svg>",
        'cane'       => "<svg viewBox='0 0 64 64' $w><rect x='10' y='28' width='30' height='16' rx='2'/><circle cx='18' cy='48' r='6'/><circle cx='34' cy='48' r='6'/><path d='M40 32h12v12H40'/></svg>",
        'baler'      => "<svg viewBox='0 0 64 64' $w><rect x='14' y='20' width='24' height='24' rx='2'/><path d='M14 28h24M14 36h24'/><circle cx='46' cy='46' r='6'/><circle cx='18' cy='46' r='6'/></svg>",
        'dryer'      => "<svg viewBox='0 0 64 64' $w><rect x='12' y='14' width='40' height='30' rx='2'/><path d='M20 44v6h24v-6M22 22h20M22 30h20M22 38h20'/></svg>",
        'plough'     => "<svg viewBox='0 0 64 64' $w><path d='M6 20h20l30 10M12 26l6 14 6-12 6 10 6-8'/></svg>",
        'chisel'     => "<svg viewBox='0 0 64 64' $w><path d='M8 20h48M14 20v14l4 10M24 20v14l4 10M34 20v14l4 10M44 20v14l4 10'/></svg>",
        'harrow'     => "<svg viewBox='0 0 64 64' $w><circle cx='16' cy='34' r='8'/><circle cx='30' cy='34' r='8'/><circle cx='44' cy='34' r='8'/></svg>",
        'cultivator' => "<svg viewBox='0 0 64 64' $w><path d='M10 20h44M16 20v10l4 14M28 20v10l4 14M40 20v10l4 14'/></svg>",
        'tiller'     => "<svg viewBox='0 0 64 64' $w><rect x='10' y='26' width='44' height='10'/><path d='M14 36l4 10M24 36l4 10M34 36l4 10M44 36l4 10'/></svg>",
        'rotovator'  => "<svg viewBox='0 0 64 64' $w><rect x='10' y='24' width='44' height='12' rx='2'/><path d='M16 36v6M24 36v6M32 36v6M40 36v6M48 36v6'/></svg>",
        'excavator'  => "<svg viewBox='0 0 64 64' $w><rect x='10' y='30' width='16' height='12' rx='2'/><path d='M26 34h10l10-14 6 2-6 16H26'/><circle cx='16' cy='46' r='5'/><circle cx='34' cy='46' r='5'/></svg>",
        'loader'     => "<svg viewBox='0 0 64 64' $w><path d='M8 40h40M14 40V26h20l8 8v6M20 26v-6h10v6'/><circle cx='20' cy='46' r='4'/><circle cx='40' cy='46' r='4'/></svg>",
        'dozer'      => "<svg viewBox='0 0 64 64' $w><path d='M8 44h30l10-8h4M18 44V30h16v14'/><circle cx='16' cy='50' r='4'/><circle cx='34' cy='50' r='4'/></svg>",
        'crane'      => "<svg viewBox='0 0 64 64' $w><path d='M12 48V16h4M16 20h30M40 20v10l6 4'/><circle cx='16' cy='52' r='4'/><circle cx='26' cy='52' r='4'/></svg>",
        'tower'      => "<svg viewBox='0 0 64 64' $w><path d='M16 54V10M16 14h34M42 14l6 6M16 22h20'/></svg>",
        'hoist'      => "<svg viewBox='0 0 64 64' $w><rect x='24' y='8' width='10' height='48' rx='2'/><rect x='18' y='52' width='22' height='6'/></svg>",
        'drillrig'   => "<svg viewBox='0 0 64 64' $w><path d='M28 6v40M20 46h16M24 46l-4 10M32 46l4 10'/></svg>",
        'mixer'      => "<svg viewBox='0 0 64 64' $w><rect x='10' y='14' width='30' height='22' rx='2'/><circle cx='18' cy='42' r='4'/><path d='M40 20h12l4 6v6H40'/></svg>",
        'plant'      => "<svg viewBox='0 0 64 64' $w><rect x='8' y='10' width='20' height='30' rx='2'/><rect x='32' y='20' width='20' height='20' rx='2'/></svg>",
        'dumptruck'  => "<svg viewBox='0 0 64 64' $w><rect x='8' y='24' width='28' height='16' rx='2'/><path d='M36 28h12l6 6v6H36'/><circle cx='16' cy='46' r='5'/><circle cx='46' cy='46' r='5'/></svg>",
        'crusher'    => "<svg viewBox='0 0 64 64' $w><path d='M8 40l8-20h8l-6 20M28 40l8-20h8l-6 20M8 40h44'/></svg>",
        'machine'    => "<svg viewBox='0 0 64 64' $w><rect x='12' y='22' width='28' height='18' rx='2'/><path d='M40 28h10l4 6v6H40'/><circle cx='20' cy='46' r='5'/><circle cx='44' cy='46' r='5'/></svg>",
    ];
}

function et_icon(string $key): string
{
    $icons = et_icons();
    return $icons[$key] ?? $icons['machine'];
}
