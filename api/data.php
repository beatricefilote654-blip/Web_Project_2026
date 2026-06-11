<?php
error_reporting(0);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cache.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Collect and sanitize filters
$filters = [
    'county'    => isset($_GET['county'])  ? array_filter(array_map('trim', explode(',', $_GET['county'])))  : [],
    'age'       => isset($_GET['age'])     ? array_filter(array_map('trim', explode(',', $_GET['age'])))     : [],
    'edu'       => isset($_GET['edu'])     ? array_filter(array_map('trim', explode(',', $_GET['edu'])))     : [],
    'env'       => isset($_GET['env'])     ? array_filter(array_map('trim', explode(',', $_GET['env'])))     : [],
    'year_from' => isset($_GET['year_from']) ? (int)$_GET['year_from'] : 2023,
    'month_from'=> isset($_GET['month_from'])? (int)$_GET['month_from']: 1,
    'year_to'   => isset($_GET['year_to'])   ? (int)$_GET['year_to']   : 2024,
    'month_to'  => isset($_GET['month_to'])  ? (int)$_GET['month_to']  : 12,
    'group_by'  => isset($_GET['group_by'])  ? trim($_GET['group_by'])  : 'county',
];

$allowed_group = ['county','age_group','education_level','environment','month'];
if (!in_array($filters['group_by'], $allowed_group, true)) {
    $filters['group_by'] = 'county';
}

$cache_key = md5(json_encode($filters));
$cached = cache_get($cache_key);
if ($cached) {
    echo $cached;
    exit;
}

$pdo = get_pdo();
$params = [];
$where  = [];

// Date range — explicit year/month comparisons (avoids PDO/SQLite arithmetic binding bug)
$yf = $filters['year_from']; $mf = $filters['month_from'];
$yt = $filters['year_to'];   $mt = $filters['month_to'];
$where[] = "(r.year > ? OR (r.year = ? AND r.month >= ?))";
array_push($params, $yf, $yf, $mf);
$where[] = "(r.year < ? OR (r.year = ? AND r.month <= ?))";
array_push($params, $yt, $yt, $mt);

// County filter
if (!empty($filters['county'])) {
    $ph = implode(',', array_fill(0, count($filters['county']), '?'));
    $where[] = "c.code IN ($ph)";
    foreach ($filters['county'] as $v) $params[] = $v;
}
// Age filter
if (!empty($filters['age'])) {
    $ph = implode(',', array_fill(0, count($filters['age']), '?'));
    $where[] = "r.age_group IN ($ph)";
    foreach ($filters['age'] as $v) $params[] = $v;
}
// Edu filter
if (!empty($filters['edu'])) {
    $ph = implode(',', array_fill(0, count($filters['edu']), '?'));
    $where[] = "r.education_level IN ($ph)";
    foreach ($filters['edu'] as $v) $params[] = $v;
}
// Env filter
if (!empty($filters['env'])) {
    $ph = implode(',', array_fill(0, count($filters['env']), '?'));
    $where[] = "r.environment IN ($ph)";
    foreach ($filters['env'] as $v) $params[] = $v;
}

$group_col = $filters['group_by'] === 'county' ? 'c.name' :
             ($filters['group_by'] === 'month'  ? "r.year || '-' || printf('%02d', r.month)" :
              "r." . $filters['group_by']);

$sql = "
    SELECT $group_col AS label, ROUND(AVG(r.value), 2) AS avg_value, COUNT(*) AS n
    FROM unemployment_records r
    JOIN counties c ON r.county_id = c.id
    " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
    GROUP BY $group_col
    ORDER BY label
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Also return metadata
$counties_all = $pdo->query("SELECT code, name FROM counties ORDER BY name")->fetchAll();
$result = ['data' => $rows, 'counties' => $counties_all];

$json = json_encode($result, JSON_UNESCAPED_UNICODE);
cache_set($cache_key, $json);
echo $json;
