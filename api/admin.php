<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cache.php';

header('Content-Type: application/json; charset=utf-8');
// Admin API is session-authenticated; same-origin only (no CORS exposure)

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// --- Login (no auth required) ---
if ($action === 'login') {
    $body = json_decode(file_get_contents('php://input'), true);
    $user = $body['username'] ?? '';
    $pass = $body['password'] ?? '';
    if (login($user, $pass)) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'session') {
    echo json_encode(['admin' => $_SESSION['admin'] ?? null]);
    exit;
}

require_admin();

$pdo = get_pdo();

// --- Cache flush (query cache only) ---
if ($action === 'flush_cache') {
    cache_flush();
    echo json_encode(['ok' => true, 'message' => 'Cache interogări golit.']);
    exit;
}

// --- Refresh data from API (streaming SSE) ---
if ($action === 'refresh_data') {
    require_once __DIR__ . '/fetcher.php';
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $send = function(string $type, $payload) {
        echo "data: " . json_encode(['type' => $type, 'msg' => $payload]) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    };

    try {
        $send('start', 'Preluare date din data.gov.ro...');
        $result = fetch_all_data(get_pdo(), function(string $msg) use ($send) {
            $send('progress', $msg);
        });
        $send('done', $result);
    } catch (Exception $e) {
        $send('error', $e->getMessage());
    }
    exit;
}

// --- Import CSV ---
if ($action === 'import_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }
    $file = $_FILES['file']['tmp_name'];
    $handle = fopen($file, 'r');
    $header = fgetcsv($handle);
    // expected: county_code,county_name,year,month,age_group,education_level,environment,value
    $insert = $pdo->prepare("
        INSERT OR REPLACE INTO unemployment_records (county_id, year, month, age_group, education_level, environment, value)
        VALUES ((SELECT id FROM counties WHERE code = ?), ?, ?, ?, ?, ?, ?)
    ");
    $count = 0;
    $pdo->beginTransaction();
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 8) continue;
        [$code, , $year, $month, $age, $edu, $env, $val] = $row;
        $insert->execute([trim($code), (int)$year, (int)$month, trim($age), trim($edu), trim($env), (float)$val]);
        $count++;
    }
    $pdo->commit();
    fclose($handle);
    cache_flush();
    echo json_encode(['ok' => true, 'imported' => $count]);
    exit;
}

// --- Import JSON ---
if ($action === 'import_json' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }
    $insert = $pdo->prepare("
        INSERT OR REPLACE INTO unemployment_records (county_id, year, month, age_group, education_level, environment, value)
        VALUES ((SELECT id FROM counties WHERE code = ?), ?, ?, ?, ?, ?, ?)
    ");
    $count = 0;
    $pdo->beginTransaction();
    foreach ($body as $r) {
        $insert->execute([
            $r['county_code'], (int)$r['year'], (int)$r['month'],
            $r['age_group'], $r['education_level'], $r['environment'], (float)$r['value']
        ]);
        $count++;
    }
    $pdo->commit();
    cache_flush();
    echo json_encode(['ok' => true, 'imported' => $count]);
    exit;
}

// --- Stats ---
if ($action === 'stats') {
    $records = $pdo->query("SELECT COUNT(*) FROM unemployment_records")->fetchColumn();
    $cache   = $pdo->query("SELECT COUNT(*) FROM cache_entries")->fetchColumn();
    $months  = $pdo->query("SELECT COUNT(DISTINCT year||month) FROM unemployment_records")->fetchColumn();
    echo json_encode(['records' => (int)$records, 'cache_entries' => (int)$cache, 'months' => (int)$months]);
    exit;
}

// --- Delete record ---
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM unemployment_records WHERE id = ?")->execute([$id]);
        cache_flush();
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing id']);
    }
    exit;
}

// --- List records (paginated) ---
if ($action === 'records') {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;
    $rows = $pdo->query("
        SELECT r.id, c.code, c.name AS county, r.year, r.month,
               r.age_group, r.education_level, r.environment, r.value
        FROM unemployment_records r JOIN counties c ON r.county_id = c.id
        ORDER BY r.year DESC, r.month DESC, c.name
        LIMIT $limit OFFSET $offset
    ")->fetchAll();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM unemployment_records")->fetchColumn();
    echo json_encode(['rows' => $rows, 'total' => $total, 'page' => $page]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
