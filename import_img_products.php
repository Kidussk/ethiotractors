<?php
/**
 * One-time import runner — visit once after deploying new catalogue images.
 * DELETE this file after a successful run.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/db.php';

echo "Running catalogue v4 migration...\n\n";

$pdo = db();
$before = (int)$pdo->query("SELECT COALESCE(`value`, '0') FROM settings WHERE `key` = 'catalog_version'")->fetchColumn();
et_upgrade_catalog($pdo);
$after = (int)$pdo->query("SELECT COALESCE(`value`, '0') FROM settings WHERE `key` = 'catalog_version'")->fetchColumn();

echo "catalog_version: {$before} -> {$after}\n\n";

$names = [
    'Pin Cutting Chisel — Field / Garden',
    'Spring Cultivator',
    'Tiller / Scissor Spring Tiller',
    'Rotovator — Field / Garden / Vertical',
    'Monocoque Tandem Tipper — R100TAHKP4',
    'Manure Spreader — R100TKG',
    'Monocoque Tandem Tipper — R220TAHK ROBUST',
    'Three-Axle Tipper — R180USGA',
    'Three-Axle Tipper — R140CSGA4P-L',
    'Tandem Axle 3-Way Tipper — R16TASGAP4',
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

echo "\nDone. DELETE import_img_products.php from the server.\n";
