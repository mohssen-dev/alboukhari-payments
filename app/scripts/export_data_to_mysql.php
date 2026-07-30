<?php
/**
 * Exports business data from local SQLite to a MySQL-compatible SQL dump.
 *
 * Usage (from app/ dir):
 *   php scripts/export_data_to_mysql.php > production_data.sql
 *
 * Then upload production_data.sql to Hostinger via phpMyAdmin → Import.
 *
 * IMPORTANT: Excludes system tables (migrations, sessions, cache) — those
 * are managed by Laravel on production. Also excludes activity_log by
 * default (usually not needed on fresh prod), but you can include it via
 * the INCLUDE_ACTIVITY_LOG env var.
 */

$sqlitePath = __DIR__ . '/../database/database.sqlite';
if (!file_exists($sqlitePath)) {
    fwrite(STDERR, "SQLite DB not found at $sqlitePath\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $sqlitePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Tables to export — order matters for FK constraints (parents first).
$tables = [
    'families',
    'students',
    'payments',
    'student_monthly_markers',
    'student_monthly_fee_overrides',
    'student_surcharges',
    'student_suspensions',
    'templates',
    'campaigns',
    'campaign_recipients',
    'message_logs',
    'settings',
];

if (getenv('INCLUDE_ACTIVITY_LOG')) {
    $tables[] = 'activity_log';
}
if (getenv('INCLUDE_USERS')) {
    $tables[] = 'users';
}

echo "-- ===============================================\n";
echo "-- Al Boukhari Payments — production data seed\n";
echo "-- Exported: " . date('Y-m-d H:i:s') . "\n";
echo "-- Source: local SQLite\n";
echo "-- Target: production MySQL\n";
echo "-- ===============================================\n\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n";
echo "SET NAMES utf8mb4;\n";
echo "SET CHARACTER SET utf8mb4;\n\n";

foreach ($tables as $table) {
    // Skip missing tables silently
    try {
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
    } catch (Throwable $e) { $exists = null; }
    if (!$exists) {
        fwrite(STDERR, "  skip: table $table not found\n");
        continue;
    }

    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    fwrite(STDERR, "  " . str_pad($table, 34) . str_pad(': ' . count($rows), 8) . " rows\n");

    if (!$rows) continue;

    echo "-- ---------------------------------------------\n";
    echo "-- Table: $table (" . count($rows) . " rows)\n";
    echo "-- ---------------------------------------------\n";
    echo "TRUNCATE TABLE `$table`;\n";

    $cols = array_keys($rows[0]);
    $colsList = '`' . implode('`, `', $cols) . '`';

    // Chunk by 100 rows per INSERT for a good balance between speed and readability.
    foreach (array_chunk($rows, 100) as $chunk) {
        $values = [];
        foreach ($chunk as $row) {
            $vals = [];
            foreach ($cols as $col) {
                $v = $row[$col];
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = $v;
                } elseif (is_bool($v)) {
                    $vals[] = $v ? '1' : '0';
                } else {
                    // Escape single quotes, backslashes, and control chars.
                    $s = (string) $v;
                    $s = str_replace(['\\', "\0", "\r", "\n", "'"], ['\\\\', '\\0', '\\r', '\\n', "\\'"], $s);
                    $vals[] = "'" . $s . "'";
                }
            }
            $values[] = '(' . implode(', ', $vals) . ')';
        }
        echo "INSERT INTO `$table` ($colsList) VALUES\n";
        echo implode(",\n", $values) . ";\n";
    }
    echo "\n";
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
echo "\n-- ===== Done =====\n";

fwrite(STDERR, "\nDone. Redirect stdout to a .sql file:\n");
fwrite(STDERR, "  php scripts/export_data_to_mysql.php > production_data.sql\n");
