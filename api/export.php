<?php
error_reporting(0);
require_once __DIR__ . '/db.php';

$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';
$allowed = ['csv','json'];
if (!in_array($format, $allowed, true)) $format = 'csv';

// Reuse same query logic from data.php
$filters = [
    'county'    => isset($_GET['county'])   ? array_filter(array_map('trim', explode(',', $_GET['county']))) : [],
    'age'       => isset($_GET['age'])      ? array_filter(array_map('trim', explode(',', $_GET['age'])))    : [],
    'edu'       => isset($_GET['edu'])      ? array_filter(array_map('trim', explode(',', $_GET['edu'])))    : [],
    'env'       => isset($_GET['env'])      ? array_filter(array_map('trim', explode(',', $_GET['env'])))    : [],
    'year_from' => isset($_GET['year_from'])? (int)$_GET['year_from'] : 2023,
    'month_from'=> isset($_GET['month_from'])? (int)$_GET['month_from']: 1,
    'year_to'   => isset($_GET['year_to'])  ? (int)$_GET['year_to']   : 2024,
    'month_to'  => isset($_GET['month_to']) ? (int)$_GET['month_to']  : 12,
];

$pdo = get_pdo();
$params = [];
$where  = [];

$yf = $filters['year_from']; $mf = $filters['month_from'];
$yt = $filters['year_to'];   $mt = $filters['month_to'];
$where[] = "(r.year > ? OR (r.year = ? AND r.month >= ?))";
array_push($params, $yf, $yf, $mf);
$where[] = "(r.year < ? OR (r.year = ? AND r.month <= ?))";
array_push($params, $yt, $yt, $mt);

if (!empty($filters['county'])) {
    $ph = implode(',', array_fill(0, count($filters['county']), '?'));
    $where[] = "c.code IN ($ph)";
    foreach ($filters['county'] as $v) $params[] = $v;
}

if (!empty($filters['age'])) {
    $ph = implode(',', array_fill(0, count($filters['age']), '?'));
    $where[] = "r.age_group IN ($ph)";
    foreach ($filters['age'] as $v) $params[] = $v;
}

if (!empty($filters['edu'])) {
    $ph = implode(',', array_fill(0, count($filters['edu']), '?'));
    $where[] = "r.education_level IN ($ph)";
    foreach ($filters['edu'] as $v) $params[] = $v;
}

if (!empty($filters['env'])) {
    $ph = implode(',', array_fill(0, count($filters['env']), '?'));

    $where[] = "r.environment IN ($ph)";

    foreach ($filters['env'] as $v) $params[] = $v;
}

$sql = "
    SELECT c.code, c.name AS county, r.year, r.month,
           r.age_group, r.education_level, r.environment, r.value
    FROM unemployment_records r
    JOIN counties c ON r.county_id = c.id
    " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
    ORDER BY r.year, r.month, c.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unemployment_data.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['county_code','county_name','year','month','age_group','education_level','environment','value']);

    foreach ($rows as $r) {
        fputcsv($out, array_values($r));
    }

    fclose($out);
} else {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="unemployment_data.json"');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
