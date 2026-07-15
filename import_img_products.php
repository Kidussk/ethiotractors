<?php
/**
 * One-time import runner — visit once after deploying new catalogue images.
 * DELETE this file after a successful run.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/db.php';

echo "Running catalogue v7 migration...\n\n";

$pdo = db();
$before = (int)$pdo->query("SELECT COALESCE(`value`, '0') FROM settings WHERE `key` = 'catalog_version'")->fetchColumn();
et_upgrade_catalog($pdo);
$after = (int)$pdo->query("SELECT COALESCE(`value`, '0') FROM settings WHERE `key` = 'catalog_version'")->fetchColumn();

echo "catalog_version: {$before} -> {$after}\n\n";

// New Zoomlion tractor line-up + the lines that received photos in v6.
$names = [
    'PQ Series Tractor',
    'PV3204 Tractor',
    'PL2304 Tractor',
    'PL1604 Tractor',
    'RG Series Tractor',
    'RL1604 Tractor',
    'Baler',
    'Grain Dryer',
    'Construction Hoist',
    'Rotary Drilling Rig',
    'Concrete Batching Plant',
    'Mining Dump Truck',
    'Mobile Crushers & Screens',
    'Surface DTH Drill Rig',
];

$stmt = $pdo->prepare('SELECT name, brand, image_url FROM products WHERE name = ?');
foreach ($names as $name) {
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "OK  | {$row['brand']} | {$row['name']} | {$row['image_url']}\n";
    } else {
        echo "MISSING | {$name}\n";
    }
}

// v7 reinstates these two lines.
$back = $pdo->prepare('SELECT COUNT(*) FROM products WHERE name = ?');
foreach (['Zoomlion Tractor Series', 'Boat Transport Trailer'] as $name) {
    $back->execute([$name]);
    echo ((int)$back->fetchColumn() > 0 ? "RESTORED | " : "MISSING (!) | ") . $name . "\n";
}

// Every sector should now hold at least 10 product lines.
echo "\nSector counts:\n";
foreach ($pdo->query("SELECT sector, COUNT(*) c FROM products GROUP BY sector") as $row) {
    $flag = (int)$row['c'] >= 10 ? 'OK ' : 'LOW';
    echo "{$flag} | {$row['sector']}: {$row['c']}\n";
}

echo "\nDone. DELETE import_img_products.php from the server.\n";
