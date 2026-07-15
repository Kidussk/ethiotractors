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

if (!is_readable(__DIR__ . '/config.php')) {
    http_response_code(503);
    exit('Missing config.php. On the server, copy config.example.php to config.php and set your database credentials.');
}
require __DIR__ . '/config.php';

/* ---------- Errors: log everything, never show details to visitors ---------- */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $e): void {
    error_log('[EthioTractors] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    exit('Something went wrong on our side. Please try again shortly.');
});

/* ---------- Security headers (sent on every page, before any output) ---------- */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
        . "img-src 'self' data: https://images.unsplash.com; frame-src https://www.google.com; "
        . "connect-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors 'self'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

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

    $port = defined('ET_DB_PORT') ? ET_DB_PORT : '3306';
    $dsn  = 'mysql:host=' . ET_DB_HOST . ';port=' . $port . ';dbname=' . ET_DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, ET_DB_USER, ET_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException) {
        // Never leak credentials or stack traces to visitors.
        http_response_code(503);
        exit('The site is temporarily unavailable — database connection failed. Please check the settings in config.php.');
    }
    // Store timestamps in UTC (the admin panel converts to Africa/Addis_Ababa).
    $pdo->exec("SET time_zone = '+00:00'");

    et_migrate($pdo);
    // Seed only when the database is empty (first run).
    $fresh = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    if ($fresh) {
        et_seed($pdo);
    } else {
        et_upgrade_catalog($pdo);
        et_upgrade_settings($pdo);
    }
    return $pdo;
}

/**
 * One-time catalog upgrades for databases seeded before new brands were added.
 * Uses the settings table as a version stamp so products deleted by the admin
 * are never resurrected. Must use $pdo directly — db() is not ready yet.
 */
function et_upgrade_catalog(PDO $pdo): void
{
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'catalog_version'");
    $stmt->execute();
    $version = (int)($stmt->fetchColumn() ?: 1);

    if ($version < 2) {
        // v2: Romsan Machinery Industry — mobile power & field-logistics lines.
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM products')->fetchColumn();
        et_seed_romsan($pdo, $sort);
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('catalog_version', '2')
                       ON DUPLICATE KEY UPDATE `value` = '2'")->execute();
    }

    if ($version < 3) {
        // v3: attach catalog / brand product photos to the original seed products.
        // Only fills rows that still have no image, so admin-uploaded photos are kept.
        $upd = $pdo->prepare('UPDATE products SET image_url = ? WHERE name = ? AND (image_url IS NULL OR image_url = "")');
        foreach (et_default_product_images() as $name => $url) {
            $upd->execute([$url, $name]);
        }
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('catalog_version', '3')
                       ON DUPLICATE KEY UPDATE `value` = '3'")->execute();
    }

    if ($version < 4) {
        // v4: refreshed Doğanlar catalogue photos + Romsan agricultural trailers & spreader.
        $imgUpd = $pdo->prepare('UPDATE products SET image_url = ? WHERE name = ?');
        foreach (et_catalog_v4_image_updates() as $name => $url) {
            $imgUpd->execute([$url, $name]);
        }
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM products')->fetchColumn();
        et_seed_romsan_agri($pdo, $sort);
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('catalog_version', '4')
                       ON DUPLICATE KEY UPDATE `value` = '4'")->execute();
    }

    if ($version < 5) {
        // v5: Romsan is an agricultural machinery brand — move all lines to Agriculture.
        $pdo->exec("UPDATE products SET sector = 'agriculture' WHERE brand = 'Romsan' AND sector = 'power'");
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('catalog_version', '5')
                       ON DUPLICATE KEY UPDATE `value` = '5'")->execute();
    }

    if ($version < 6) {
        // v6: manufacturer photos for the lines seeded without one, the real Zoomlion
        // tractor line-up in place of the generic series card, and removal of the one
        // line still left with no photograph.
        $imgUpd = $pdo->prepare('UPDATE products SET image_url = ? WHERE name = ?');
        foreach (et_catalog_v6_image_updates() as $name => $url) {
            $imgUpd->execute([$url, $name]);
        }
        $del = $pdo->prepare('DELETE FROM products WHERE name = ?');
        foreach (et_catalog_v6_removals() as $name) {
            $del->execute([$name]);
        }
        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM products')->fetchColumn();
        et_seed_zoomlion_tractors($pdo, $sort);
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('catalog_version', '6')
                       ON DUPLICATE KEY UPDATE `value` = '6'")->execute();
    }
}

/** Zoomlion photos for the lines that shipped with no image at all (v6). */
function et_catalog_v6_image_updates(): array
{
    return [
        'Baler'                            => 'assets/products/zoomlion-baler.png',
        'Grain Dryer'                      => 'assets/products/zoomlion-grain-dryer.png',
        'Construction Hoist'               => 'assets/products/zoomlion-construction-hoist.png',
        'Rotary Drilling Rig'              => 'assets/products/zoomlion-rotary-drill-rig.png',
        'Concrete Batching Plant'          => 'assets/products/zoomlion-batching-plant.png',
        'Mining Dump Truck'                => 'assets/products/zoomlion-mining-dump-truck.png',
        'Mobile Crushers & Screens'        => 'assets/products/zoomlion-mobile-crusher.png',
        'Surface DTH Drill Rig'            => 'assets/products/zoomlion-dth-drill.png',
    ];
}

/**
 * Lines dropped in v6 — nothing in the manufacturer catalogues to photograph them
 * with, and the generic tractor card is replaced by the named models below.
 */
function et_catalog_v6_removals(): array
{
    return ['Boat Transport Trailer', 'Zoomlion Tractor Series'];
}

/** Zoomlion's tractor line-up, from the manufacturer's product site (v6). */
function et_seed_zoomlion_tractors(PDO $pdo, int $sortStart): void
{
    $S = static fn(array $pairs) => json_encode($pairs, JSON_UNESCAPED_UNICODE);
    $seed = [
        ['PQ Series Tractor', 'agriculture', 'Tractors', 'Zoomlion',
            'High-horsepower tractors for family farms, large-scale operations and agricultural service providers — 39.3% torque reserve for heavy draft loads and up to 13 hours on one tank.',
            $S([['Horsepower', '260 / 275 hp'], ['Fuel tank', '630 L'], ['Gears', '48F+24R'], ['Speed', '0.24 – 38.85 km/h']]),
            'High Horsepower', 'tractor', 'assets/products/zoomlion-tractor-pq-series.png'],
        ['PV3204 Tractor', 'agriculture', 'Tractors', 'Zoomlion',
            'Mid-to-high-end tractor for intensive tillage, seeding, harvesting, crop management, transport and PTO-powered operations.',
            $S([['Horsepower', '320 hp'], ['Fuel tank', '630 L'], ['Gears', '48F+24R']]),
            'High Horsepower', 'tractor', 'assets/products/zoomlion-tractor-pv3204.png'],
        ['PL2304 Tractor', 'agriculture', 'Tractors', 'Zoomlion',
            'Heavy-duty power shift tractor for intensive tillage, seeding, forage harvesting, spraying, transport and high-draft work.',
            $S([['Engine', 'Weichai WP7'], ['Fuel tank', '400 L'], ['Gears', '40F+40R'], ['Speed', '0.4 – 40 km/h']]),
            'Power Shift', 'tractor', 'assets/products/zoomlion-tractor-pl2304.png'],
        ['PL1604 Tractor', 'agriculture', 'Tractors', 'Zoomlion',
            'Mid-to-high-end multi-purpose power shift tractor for tillage, PTO-powered work, seeding, harvesting, cultivation and transport in dryland farming.',
            $S([['Horsepower', '160 hp'], ['Fuel tank', '375 L'], ['Gears', '48F+24R']]),
            'Power Shift', 'tractor', 'assets/products/zoomlion-tractor-pl1604.png'],
        ['RG Series Tractor', 'agriculture', 'Tractors', 'Zoomlion',
            'High-horsepower, multi-purpose tractors developed for large-scale dryland farming.',
            $S([['Horsepower', '160 – 200 hp'], ['Fuel tank', '375 L'], ['Gears', '16F+16R']]),
            'Dryland', 'tractor', 'assets/products/zoomlion-tractor-rg-series.png'],
        ['RL1604 Tractor', 'agriculture', 'Tractors', 'Zoomlion',
            'Heavy-duty tractor for medium and deep tillage, planting, harvest support, material handling and transport — suited to intensive sugarcane work.',
            $S([['Horsepower', '160 hp'], ['Fuel tank', '375 L'], ['Gears', '32F+32R']]),
            'Sugarcane', 'tractor', 'assets/products/zoomlion-tractor-rl1604.png'],
    ];

    $exists = $pdo->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, sector, category, brand, description, specs, tags, icon, image_url, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $sort = $sortStart;
    foreach ($seed as $p) {
        $exists->execute([$p[0]]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $stmt->execute([...$p, $sort++]);
    }
}

/** Doğanlar photos refreshed from the Romsan product catalogue (v4). */
function et_catalog_v4_image_updates(): array
{
    return [
        'Pin Cutting Chisel — Field / Garden'     => 'assets/products/doganlar-pin-cutting-chisel.jpg',
        'Spring Cultivator'                       => 'assets/products/doganlar-spring-cultivator.jpg',
        'Tiller / Scissor Spring Tiller'          => 'assets/products/doganlar-tiller.jpg',
        'Rotovator — Field / Garden / Vertical'   => 'assets/products/doganlar-rotovator.jpg',
    ];
}

/** Romsan farm trailers & manure spreader from the agricultural catalogue (v4). */
function et_seed_romsan_agri(PDO $pdo, int $sortStart): void
{
    $S = static fn(array $pairs) => json_encode($pairs, JSON_UNESCAPED_UNICODE);
    $seed = [
        ['Monocoque Tandem Tipper — R100TAHKP4', 'agriculture', 'Transport Trailers', 'Romsan',
            'Monocoque tandem-axle tipper for grain, bulk and site cargo — rear hydraulic tipping with parabolic suspension.',
            $S([['Payload', '10,000 kg'], ['Volume', '11.7 – 16.4 m³'], ['Tipping', 'Rear']]),
            'Haulage', 'trailer', 'assets/products/romsan-r100tahkp4-tipper.jpg'],
        ['Manure Spreader — R100TKG', 'agriculture', 'Spreaders', 'Romsan',
            'Hydraulic conveyor and PTO-driven twin-column spreader for even field distribution up to 15 m.',
            $S([['Volume', '13 m³'], ['Spread width', 'Up to 15 m'], ['PTO', '540 rpm']]),
            'Livestock', 'machine', 'assets/products/romsan-r100tkg-manure-spreader.jpg'],
        ['Monocoque Tandem Tipper — R220TAHK ROBUST', 'agriculture', 'Transport Trailers', 'Romsan',
            'Heavy-duty monocoque tipper with HARDOX floor — built for abrasive loads and demanding field haulage.',
            $S([['Payload', '18,800 kg'], ['Volume', '13 m³'], ['Floor', '6 mm HARDOX']]),
            'Haulage', 'trailer', 'assets/products/romsan-r220tahk-robust-tipper.jpg'],
        ['Three-Axle Tipper — R180USGA', 'agriculture', 'Transport Trailers', 'Romsan',
            'Three-axle grain and bulk trailer with three-way hydraulic tipping and optional tarpaulin frame.',
            $S([['Payload', '24,000 kg'], ['Volume', '28 – 30 m³'], ['Tipping', '3-way']]),
            'Haulage', 'trailer', 'assets/products/romsan-r180usga-tipper.jpg'],
        ['Three-Axle Tipper — R140CSGA4P-L', 'agriculture', 'Transport Trailers', 'Romsan',
            'Three-axle tipper for high-volume grain and bulk transport with three-way hydraulic discharge.',
            $S([['Payload', '13,150 – 16,150 kg'], ['Volume', '20 – 22 m³'], ['Tipping', '3-way']]),
            'Haulage', 'trailer', 'assets/products/romsan-r140csga4p-l-tipper.jpg'],
        ['Tandem Axle 3-Way Tipper — R16TASGAP4', 'agriculture', 'Transport Trailers', 'Romsan',
            'Tandem-axle trailer with three-way tipping, steering axle and pneumatic braking for versatile field logistics.',
            $S([['Payload', '16,000 kg'], ['Volume', '20 – 22 m³'], ['Tipping', '3-way']]),
            'Haulage', 'trailer', 'assets/products/romsan-r16tasgap4-tipper.jpg'],
    ];

    $exists = $pdo->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, sector, category, brand, description, specs, tags, icon, image_url, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $sort = $sortStart;
    foreach ($seed as $p) {
        $exists->execute([$p[0]]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $stmt->execute([...$p, $sort++]);
    }
}

/** One-time contact-info upgrades for existing databases. */
function et_upgrade_settings(PDO $pdo): void
{
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'contact_version'");
    $stmt->execute();
    $version = (int)($stmt->fetchColumn() ?: 0);

    if ($version < 1) {
        $phones = [
            'phone'  => '0960995555',
            'phone2' => '0961995555',
            'phone3' => '0986807851',
        ];
        $upd = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        foreach ($phones as $k => $v) {
            $upd->execute([$k, $v]);
        }
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('contact_version', '1')
                       ON DUPLICATE KEY UPDATE `value` = '1'")->execute();
    }
}

/** Name → default photo map, shared by the fresh seed and the v3 backfill migration. */
function et_default_product_images(): array
{
    return [
        // Doğanlar — cropped from the manufacturer's product catalogue
        'Gas-Safe Reversible Plough'         => 'assets/products/doganlar-reversible-plough.jpg',
        'Gas-Safe Plough'                    => 'assets/products/doganlar-gas-safe-plough.jpg',
        'Spring Profile Plough'              => 'assets/products/doganlar-spring-profile-plough.jpg',
        'Rotary Spring Profile Plough'       => 'assets/products/doganlar-rotary-spring-plough.jpg',
        'Pin Cutting Plough'                 => 'assets/products/doganlar-pin-cutting-plough.jpg',
        'Rotary Mounted Pin Cutting Plough'  => 'assets/products/doganlar-rotary-pin-plough.jpg',
        'Standard Plough'                    => 'assets/products/doganlar-standard-plough.jpg',
        'Spring Chisel'                      => 'assets/products/doganlar-spring-chisel.jpg',
        'Pin Cutting Chisel — Field / Garden'=> 'assets/products/doganlar-pin-cutting-chisel.jpg',
        'Disc Harrow'                        => 'assets/products/doganlar-disc-harrow.jpg',
        'Spring Cultivator'                  => 'assets/products/doganlar-spring-cultivator.jpg',
        'Tiller / Scissor Spring Tiller'     => 'assets/products/doganlar-tiller.jpg',
        'Rotovator — Field / Garden / Vertical' => 'assets/products/doganlar-rotovator.jpg',
        // Zoomlion — freely-licensed photos (see assets/products/IMAGE-CREDITS.md)
        'Combine Harvester'                  => 'assets/products/zoomlion-combine.jpg',
        'Sugarcane Harvester'                => 'assets/products/zoomlion-sugarcane.jpg',
        'Excavators — Mini to Large'         => 'assets/products/zoomlion-excavator.jpg',
        'Wheel & Crawler Loaders'            => 'assets/products/zoomlion-loader.jpg',
        'Crawler Bulldozer'                  => 'assets/products/zoomlion-bulldozer.jpg',
        'Truck & Crawler Cranes'             => 'assets/products/zoomlion-truck-crane.jpg',
        'Tower Cranes — Flat-Top & Luffing Jib' => 'assets/products/zoomlion-tower-crane.jpg',
        'Truck Mixer & Concrete Pumps'       => 'assets/products/zoomlion-concrete-pump.jpg',
        'Large Mining Excavator'             => 'assets/products/zoomlion-mining-excavator.jpg',
    ];
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS throttle (
            tkey         VARCHAR(64) PRIMARY KEY,
            hits         INT NOT NULL DEFAULT 0,
            window_start INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

/* ---------- Abuse throttling (per-IP, database-backed) ---------- */

/** Privacy-friendly per-IP key: the raw address is hashed, never stored. */
function et_throttle_key(string $purpose): string
{
    return $purpose . ':' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 40);
}

/** True when this client already used up its allowance for the purpose. */
function et_throttled(string $purpose, int $max, int $windowSeconds): bool
{
    $stmt = db()->prepare('SELECT hits, window_start FROM throttle WHERE tkey = ?');
    $stmt->execute([et_throttle_key($purpose)]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['window_start'] < time() - $windowSeconds) {
        return false;
    }
    return (int)$row['hits'] >= $max;
}

/** Count one hit against the client's allowance (resets when the window expired). */
function et_throttle_hit(string $purpose, int $windowSeconds): void
{
    $now = time();
    $cut = $now - $windowSeconds;
    db()->prepare('INSERT INTO throttle (tkey, hits, window_start) VALUES (?, 1, ?)
                   ON DUPLICATE KEY UPDATE
                     hits = IF(window_start < ?, 1, hits + 1),
                     window_start = IF(window_start < ?, ?, window_start)')
        ->execute([et_throttle_key($purpose), $now, $cut, $cut, $now]);
    if (random_int(1, 100) === 1) { // occasional cleanup of stale rows
        db()->prepare('DELETE FROM throttle WHERE window_start < ?')->execute([$now - 86400]);
    }
}

function et_seed(PDO $pdo): void
{
    // Default admin account — change the password in Admin → Settings after first login.
    $stmt = $pdo->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)');
    $stmt->execute(['admin', password_hash('ethio2026', PASSWORD_DEFAULT)]);

    $defaults = [
        'company_name'  => 'EthioTractors PLC',
        'tagline'       => 'Imported Machinery — Built for Ethiopia’s Work',
        'phone'         => '0960995555',
        'phone2'        => '0961995555',
        'phone3'        => '0986807851',
        'email'         => 'info@ethiotractors.com',
        'address'       => 'Bole Road, Addis Ababa, Ethiopia',
        'branches'      => '',
        'hours'         => 'Mon – Sat · 8:00 AM – 6:00 PM',
        'map_query'     => 'Bole, Addis Ababa, Ethiopia',
        'telegram'      => '',
        'facebook'      => '',
        'linkedin'      => '',
        'trade_license' => '',
        'catalog_version' => '5',
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
        // Tractors are seeded from the manufacturer line-up by et_seed_zoomlion_tractors().
        ['Combine Harvester', 'agriculture', 'Harvesters', 'Zoomlion',
            'Grain harvesting with integrated threshing and cleaning for cereal crops.',
            $S([]), 'Harvest', 'harvester', 'assets/products/zoomlion-combine.jpg'],
        ['Sugarcane Harvester', 'agriculture', 'Harvesters', 'Zoomlion',
            'Purpose-built cane cutting and billeting for large-scale plantation operations.',
            $S([]), 'Harvest', 'cane', 'assets/products/zoomlion-sugarcane.jpg'],
        ['Baler', 'agriculture', 'Post-Harvest', 'Zoomlion',
            'Compresses hay or straw into transportable, storable bales after harvest.',
            $S([]), 'Post-Harvest', 'baler', 'assets/products/zoomlion-baler.png'],
        ['Grain Dryer', 'agriculture', 'Post-Harvest', 'Zoomlion',
            'Reduces post-harvest moisture loss and supports safe long-term grain storage.',
            $S([]), 'Post-Harvest', 'dryer', 'assets/products/zoomlion-grain-dryer.png'],

        // ---- Agriculture — ploughs (Doğanlar) ----
        ['Gas-Safe Reversible Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Hydraulic-piston escape mechanism lets each body clear rocky ground without damage — adjustable 12"–18" working width.',
            $S([['Bodies', '4 / 5 / 6'], ['Weight', '940 – 1,120 kg'], ['Power req.', '110 – 250 hp']]),
            '140×120 Profile', 'plough', 'assets/products/doganlar-reversible-plough.jpg'],
        ['Gas-Safe Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Fixed gas-safe plough with hydraulic body protection for rocky and demanding soils.',
            $S([['Bodies', '4 – 6'], ['Weight', '1,420 – 1,840 kg'], ['Power req.', '110 – 250 hp']]),
            '16" Blade', 'plough', 'assets/products/doganlar-gas-safe-plough.jpg'],
        ['Spring Profile Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Independent spring-body protection with hydraulic working-width adjustment — 3 to 5 body configurations.',
            $S([['Bodies', '3 / 4 / 5'], ['Weight', '600 – 995 kg'], ['Power req.', '45 – 130 hp']]),
            '100–140 Profile', 'plough', 'assets/products/doganlar-spring-profile-plough.jpg'],
        ['Rotary Spring Profile Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Rotary-reset spring bodies for continuous ploughing in stony fields without stopping to reset.',
            $S([['Bodies', '4 – 5'], ['Weight', '1,315 – 1,550 kg'], ['Power req.', '85 – 165 hp']]),
            '12–14" Blade', 'plough', 'assets/products/doganlar-rotary-spring-plough.jpg'],
        ['Pin Cutting Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Shear-pin protected bodies in 4 to 7 body configurations for general field ploughing.',
            $S([['Bodies', '4 – 7'], ['Weight', '685 – 1,782 kg'], ['Power req.', '80 – 245 hp']]),
            '12–16" Blade', 'plough', 'assets/products/doganlar-pin-cutting-plough.jpg'],
        ['Rotary Mounted Pin Cutting Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Rotary-mounted shear-pin plough for smaller tractors and tighter field patterns.',
            $S([['Bodies', '2 – 5'], ['Weight', '750 – 1,355 kg'], ['Power req.', '65 – 175 hp']]),
            '12–16" Blade', 'plough', 'assets/products/doganlar-rotary-pin-plough.jpg'],
        ['Standard Plough', 'agriculture', 'Ploughs', 'Doğanlar',
            'Economical fixed mouldboard plough for routine primary tillage.',
            $S([['Bodies', '2 – 6'], ['Weight', '160 – 795 kg'], ['Power req.', '30 – 130 hp']]),
            '8–16" Blade', 'plough', 'assets/products/doganlar-standard-plough.jpg'],

        // ---- Agriculture — chisels & harrows (Doğanlar) ----
        ['Spring Chisel', 'agriculture', 'Chisels & Harrows', 'Doğanlar',
            'Breaks up hardpan without inverting the soil layer. Independent spring tines deflect off rock and reset automatically.',
            $S([['Feet', '5 – 11'], ['Depth', '375 – 450 mm'], ['Power req.', '50 – 140 hp']]),
            'Deep Tillage', 'chisel', 'assets/products/doganlar-spring-chisel.jpg'],
        ['Pin Cutting Chisel — Field / Garden', 'agriculture', 'Chisels & Harrows', 'Doğanlar',
            'Heat-treated pin-and-clamp assemblies for stubble and deep tillage in open-field and orchard/vineyard rows.',
            $S([['Feet', '7 – 11'], ['Depth', '250 – 450 mm'], ['Power req.', '45 – 130 hp']]),
            'Deep Tillage', 'chisel', 'assets/products/doganlar-pin-cutting-chisel.jpg'],
        ['Disc Harrow', 'agriculture', 'Chisels & Harrows', 'Doğanlar',
            'Suspension-mounted discs for seedbed prep, residue cutting and post-harvest tillage.',
            $S([['Discs', '16 – 32'], ['Depth', '225 – 250 mm'], ['Power req.', '45 – 180 hp']]),
            'Seedbed', 'harrow', 'assets/products/doganlar-disc-harrow.jpg'],

        // ---- Agriculture — cultivators & finishing (Doğanlar) ----
        ['Spring Cultivator', 'agriculture', 'Cultivators & Tillers', 'Doğanlar',
            'Loosens and aerates soil, cuts weed roots, and inter-cultivates row crops like maize, potato and sunflower.',
            $S([['Feet', '7 – 22'], ['Depth', '175 – 250 mm'], ['Power req.', '40 – 200 hp']]),
            'Row Crop', 'cultivator', 'assets/products/doganlar-spring-cultivator.jpg'],
        ['Tiller / Scissor Spring Tiller', 'agriculture', 'Cultivators & Tillers', 'Doğanlar',
            'Final seedbed preparation before planting — self-vibrating spring mechanism levels the field in one pass.',
            $S([['Width', '21 – 40 ft'], ['Power req.', '50 – 140 hp']]),
            'Seedbed', 'tiller', 'assets/products/doganlar-tiller.jpg'],
        ['Rotovator — Field / Garden / Vertical', 'agriculture', 'Cultivators & Tillers', 'Doğanlar',
            'Mixes and breaks down soil, weeds and residue into organic matter. Field, garden and vertical-tine configurations.',
            $S([['Width', '160 – 400 cm'], ['Power req.', '45 – 180 hp']]),
            'Residue', 'rotovator', 'assets/products/doganlar-rotovator.jpg'],

        // ---- Construction (Zoomlion) ----
        ['Excavators — Mini to Large', 'construction', 'Earthmoving', 'Zoomlion',
            'Mini, small, medium, large and wheeled excavators for sites of every scale.',
            $S([]), 'Earthmoving', 'excavator', 'assets/products/zoomlion-excavator.jpg'],
        ['Wheel & Crawler Loaders', 'construction', 'Earthmoving', 'Zoomlion',
            'Skid steer, crawler, wheel and compact track loaders for material handling and site prep.',
            $S([]), 'Earthmoving', 'loader', 'assets/products/zoomlion-loader.jpg'],
        ['Crawler Bulldozer', 'construction', 'Earthmoving', 'Zoomlion',
            'Heavy blade grading and earthmoving for road building and site clearance.',
            $S([]), 'Earthmoving', 'dozer', 'assets/products/zoomlion-bulldozer.jpg'],
        ['Truck & Crawler Cranes', 'construction', 'Cranes & Hoisting', 'Zoomlion',
            'Truck, rough terrain, all terrain and crawler cranes for lifting and placement.',
            $S([]), 'Mobile Crane', 'crane', 'assets/products/zoomlion-truck-crane.jpg'],
        ['Tower Cranes — Flat-Top & Luffing Jib', 'construction', 'Cranes & Hoisting', 'Zoomlion',
            'Fixed-site vertical lift for multi-storey construction projects.',
            $S([]), 'Hoisting', 'tower', 'assets/products/zoomlion-tower-crane.jpg'],
        ['Construction Hoist', 'construction', 'Cranes & Hoisting', 'Zoomlion',
            'Personnel and material lifts for high-rise site logistics.',
            $S([]), 'Hoisting', 'hoist', 'assets/products/zoomlion-construction-hoist.png'],
        ['Rotary Drilling Rig', 'construction', 'Foundation & Concrete', 'Zoomlion',
            'Bored pile foundation drilling for high-load structures.',
            $S([]), 'Foundation', 'drillrig', 'assets/products/zoomlion-rotary-drill-rig.png'],
        ['Truck Mixer & Concrete Pumps', 'construction', 'Foundation & Concrete', 'Zoomlion',
            'Truck mixers, truck-mounted pumps, trailer pumps and placing booms.',
            $S([]), 'Concrete', 'mixer', 'assets/products/zoomlion-concrete-pump.jpg'],
        ['Concrete Batching Plant', 'construction', 'Foundation & Concrete', 'Zoomlion',
            'On-site or centralized concrete batching for continuous supply.',
            $S([]), 'Concrete', 'plant', 'assets/products/zoomlion-batching-plant.png'],

        // ---- Mining (Zoomlion) ----
        ['Mining Dump Truck', 'mining', 'Haulage', 'Zoomlion',
            'High-payload haulage built for continuous pit-to-stockpile cycles.',
            $S([]), 'Mining', 'dumptruck', 'assets/products/zoomlion-mining-dump-truck.png'],
        ['Large Mining Excavator', 'mining', 'Excavation', 'Zoomlion',
            'Mine-class excavators for overburden removal and bulk material loading.',
            $S([]), 'Mining', 'excavator', 'assets/products/zoomlion-mining-excavator.jpg'],
        ['Mobile Crushers & Screens', 'mining', 'Processing', 'Zoomlion',
            'On-site crushing and screening, plus fixed crushers for aggregate production.',
            $S([]), 'Mining', 'crusher', 'assets/products/zoomlion-mobile-crusher.png'],
        ['Surface DTH Drill Rig', 'mining', 'Drilling', 'Zoomlion',
            'Down-the-hole drilling rigs for blast-hole and surface mining programs.',
            $S([]), 'Mining', 'drillrig', 'assets/products/zoomlion-dth-drill.png'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO products (name, sector, category, brand, description, specs, tags, icon, image_url, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($seed as $i => $p) {
        $stmt->execute([...$p, $i]);
    }

    et_seed_romsan($pdo, count($seed));
    et_seed_romsan_agri($pdo, (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM products')->fetchColumn());
    et_seed_zoomlion_tractors($pdo, (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM products')->fetchColumn());
    // Stamp the catalog version so et_upgrade_catalog() never re-runs these steps.
    $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('catalog_version', '6')
                   ON DUPLICATE KEY UPDATE `value` = '6'")->execute();
}

/** Romsan Machinery Industry — mobile power, trailers and site containers (catalog v2). */
function et_seed_romsan(PDO $pdo, int $sortStart): void
{
    $S = static fn(array $pairs) => json_encode($pairs, JSON_UNESCAPED_UNICODE);
    $seed = [
        ['Mobile Generator & NATO Trailer', 'agriculture', 'Mobile Power', 'Romsan',
            'Trailer-mounted diesel generator sets with NATO-type towing gear — dependable field power that moves with the job.',
            $S([['Output', '5 – 1,000 kVA'], ['Alternator', 'Dual / synchronous'], ['Mount', 'NATO-type trailer']]),
            'Field Power', 'generator', 'assets/products/romsan-mobile-generator.jpg'],
        ['Tactical Portable Generator', 'agriculture', 'Portable Power', 'Romsan',
            'Compact enclosed diesel generators light enough for a crew to hand-carry and position anywhere on site.',
            $S([['Output', '5 / 7.5 / 10 / 33 kVA'], ['Weight', '≤ 300 kg'], ['Handling', 'Hand-carriable']]),
            'Portable', 'generator', 'assets/products/romsan-tactical-generator.jpg'],
        ['Container Type Generator', 'agriculture', 'Containerised Power', 'Romsan',
            'Containerised generating sets for standby and prime power at plants, camps and remote operations.',
            $S([['Output', '275 – 1,000 kVA'], ['Alternator', 'Dual / synchronous'], ['Format', '20 ft container']]),
            'Standby Power', 'container', 'assets/products/romsan-container-generator.jpg'],
        ['Air Cargo Transport Trailer', 'agriculture', 'Transport Trailers', 'Romsan',
            'NATO-type cargo trailer engineered for air-freight and airside logistics, with an optional 360° rotating deck.',
            $S([['Type', 'NATO type'], ['Deck', 'Optional 360° rotation'], ['Brakes', 'Pneumatic']]),
            'Logistics', 'trailer', 'assets/products/romsan-air-cargo-trailer.jpg'],
        ['Flatbed & Container Shipment Trailer', 'agriculture', 'Transport Trailers', 'Romsan',
            'Single and tandem-axle flatbeds for machinery, 20 ft containers and general site cargo.',
            $S([['Axles', 'Single / tandem'], ['Load', '20 ft container'], ['Landing legs', 'Heavy duty']]),
            'Haulage', 'trailer', 'assets/products/romsan-flatbed-trailer.jpg'],
        ['Field Living & Utility Containers', 'agriculture', 'Site Containers', 'Romsan',
            '20 ft accommodation, kitchen, cold-storage, WC & bathroom and living units for remote camps and projects.',
            $S([['Size', '20 ft'], ['Units', 'Living · Kitchen · Cold store · WC']]),
            'Camp Setup', 'container', 'assets/products/romsan-living-container.jpg'],
        ['Heavy-Duty LED Trailer Lamps', 'agriculture', 'Components', 'Romsan',
            'Sealed 12–24 V LED stop, signal and fog lamps in guarded aluminium housings, built to MIL-STD 810F / 461E.',
            $S([['Voltage', '12 – 24 VDC'], ['Sealing', 'IP X8'], ['Standard', 'MIL-STD 810F / 461E']]),
            'Components', 'lamp', 'assets/products/romsan-led-lamp.jpg'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO products (name, sector, category, brand, description, specs, tags, icon, image_url, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($seed as $i => $p) {
        $stmt->execute([...$p, $sortStart + $i]);
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
    // Auto sign-out after an hour of inactivity.
    if (isset($_SESSION['admin'])) {
        if (($_SESSION['admin_last_seen'] ?? time()) < time() - 3600) {
            unset($_SESSION['admin'], $_SESSION['admin_last_seen']);
            flash_set('error', 'You were signed out after an hour of inactivity.');
            return null;
        }
        $_SESSION['admin_last_seen'] = time();
    }
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
    'power'        => 'Power & Logistics',
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
        'generator'  => "<svg viewBox='0 0 64 64' $w><rect x='10' y='18' width='36' height='24' rx='2'/><path d='M18 24v12M24 24v12M40 22l-5 9h8l-5 9'/><circle cx='18' cy='50' r='5'/><circle cx='40' cy='50' r='5'/><path d='M46 30h8M46 36h8'/></svg>",
        'trailer'    => "<svg viewBox='0 0 64 64' $w><path d='M6 36h44M50 36h8l-4-4M10 36V26h32v10'/><circle cx='20' cy='44' r='5'/><circle cx='34' cy='44' r='5'/></svg>",
        'container'  => "<svg viewBox='0 0 64 64' $w><rect x='8' y='18' width='48' height='28' rx='2'/><path d='M16 22v20M24 22v20M32 22v20M40 22v20M48 22v20'/></svg>",
        'lamp'       => "<svg viewBox='0 0 64 64' $w><rect x='14' y='16' width='36' height='28' rx='3'/><path d='M14 24h36M14 36h36M26 16v28M38 16v28'/><circle cx='20' cy='30' r='2'/><circle cx='32' cy='30' r='2'/><circle cx='44' cy='30' r='2'/></svg>",
        'crusher'    => "<svg viewBox='0 0 64 64' $w><path d='M8 40l8-20h8l-6 20M28 40l8-20h8l-6 20M8 40h44'/></svg>",
        'machine'    => "<svg viewBox='0 0 64 64' $w><rect x='12' y='22' width='28' height='18' rx='2'/><path d='M40 28h10l4 6v6H40'/><circle cx='20' cy='46' r='5'/><circle cx='44' cy='46' r='5'/></svg>",
    ];
}

function et_icon(string $key): string
{
    $icons = et_icons();
    return $icons[$key] ?? $icons['machine'];
}
