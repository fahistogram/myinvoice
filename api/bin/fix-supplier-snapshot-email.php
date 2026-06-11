<?php

declare(strict_types=1);

/**
 * Propaguje AKTUÁLNÍ e-mail dodavatele z živé tabulky `supplier` do
 * `supplier_snapshot` již vystavených faktur.
 *
 * Mění výhradně klíč `$.email` ve snapshotu (přes JSON_SET) — adresa, IČO,
 * DIČ a ostatní historicky zamrzlé údaje zůstávají nedotčené. E-mail je jen
 * kontakt („jak nás zastihnout"), ne historicky závazný fakturační údaj,
 * proto je legitimní ho propsat i do starých faktur (PDF patička + Reply-To).
 *
 * Použití:
 *   php api/bin/fix-supplier-snapshot-email.php                 # dry-run, vypíše co změní
 *   php api/bin/fix-supplier-snapshot-email.php --apply         # zapíše do DB
 *   php api/bin/fix-supplier-snapshot-email.php --supplier=2    # omez na konkrétního dodavatele
 *
 * Idempotentní: dotýká se jen faktur, kde snapshot.email != aktuální supplier.email.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

$apply = in_array('--apply', $argv, true);

$supplierId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--supplier=(\d+)$/', $arg, $m)) {
        $supplierId = (int) $m[1];
    }
}

$config = Config::load(Bootstrap::rootDir());
$pdo = (new Connection($config))->pdo();

// Faktury, kde snapshot existuje a jeho e-mail se liší od aktuálního supplier.email.
// JSON_UNQUOTE kvůli srovnání řetězců; NULL-safe operátor <=> ošetří chybějící email ve snapshotu.
$sql = "
    SELECT i.id, i.varsymbol, i.supplier_id,
           JSON_UNQUOTE(JSON_EXTRACT(i.supplier_snapshot, '$.email')) AS snap_email,
           s.email AS live_email
      FROM invoices i
      JOIN supplier s ON s.id = i.supplier_id
     WHERE i.supplier_snapshot IS NOT NULL
       AND NOT (JSON_UNQUOTE(JSON_EXTRACT(i.supplier_snapshot, '$.email')) <=> s.email)
";
$params = [];
if ($supplierId !== null) {
    $sql .= ' AND i.supplier_id = ?';
    $params[] = $supplierId;
}
$sql .= ' ORDER BY i.supplier_id, i.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nic k opravě — snapshoty už mají aktuální e-mail dodavatele.\n";
    exit(0);
}

echo 'Faktur ke zpracování: ' . count($rows) . "\n";
foreach (array_slice($rows, 0, 10) as $r) {
    $from = $r['snap_email'] ?? '(prázdné)';
    echo "  #{$r['id']} VS={$r['varsymbol']} sid={$r['supplier_id']}: {$from} -> {$r['live_email']}\n";
}
if (count($rows) > 10) {
    echo '  … a další ' . (count($rows) - 10) . "\n";
}

if (!$apply) {
    echo "(dry-run — pro zápis spusť s --apply)\n";
    exit(0);
}

$update = $pdo->prepare(
    "UPDATE invoices
        SET supplier_snapshot = JSON_SET(supplier_snapshot, '$.email', ?)
      WHERE id = ?"
);
$ok = 0;
$err = 0;
foreach ($rows as $r) {
    try {
        $update->execute([$r['live_email'], (int) $r['id']]);
        $ok++;
    } catch (\Throwable $e) {
        $err++;
        echo "  ✗ #{$r['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Hotovo: $ok opraveno" . ($err > 0 ? ", $err chyb" : '') . ".\n";
