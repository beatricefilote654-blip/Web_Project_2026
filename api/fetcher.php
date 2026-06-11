<?php
// Fetches ANOFM unemployment data from data.gov.ro into the local SQLite DB.
// Call fetch_all_data($pdo) to (re)populate unemployment_records.
error_reporting(0);

define('DATAGOV_API', 'https://data.gov.ro/api/3/action/package_search?q=somaj+inregistrat&rows=100&sort=metadata_created+desc');

$_NAME_TO_CODE = [
    'ALBA'=>'AB','ARAD'=>'AR','ARGES'=>'AG','BACAU'=>'BC','BIHOR'=>'BH',
    'BISTRITA-NASAUD'=>'BN','BOTOSANI'=>'BT','BRASOV'=>'BV','BRAILA'=>'BR',
    'BUZAU'=>'BZ','CARAS-SEVERIN'=>'CS','CALARASI'=>'CL','CLUJ'=>'CJ',
    'CONSTANTA'=>'CT','COVASNA'=>'CV','DAMBOVITA'=>'DB','DOLJ'=>'DJ',
    'GALATI'=>'GL','GIURGIU'=>'GR','GORJ'=>'GJ','HARGHITA'=>'HR',
    'HUNEDOARA'=>'HD','IALOMITA'=>'IL','IASI'=>'IS','ILFOV'=>'IF',
    'MARAMURES'=>'MM','MEHEDINTI'=>'MH','MURES'=>'MS','NEAMT'=>'NT',
    'OLT'=>'OT','PRAHOVA'=>'PH','SATU MARE'=>'SM','SALAJ'=>'SJ',
    'SIBIU'=>'SB','SUCEAVA'=>'SV','TELEORMAN'=>'TR','TIMIS'=>'TM',
    'TULCEA'=>'TL','VASLUI'=>'VS','VALCEA'=>'VL','VRANCEA'=>'VN',
    'MUNICIPIUL BUCURESTI'=>'B','BUCURESTI'=>'B',
];

$_MONTHS_RO = [
    'ianuarie'=>1,'februarie'=>2,'martie'=>3,'aprilie'=>4,'mai'=>5,'iunie'=>6,
    'iulie'=>7,'august'=>8,'septembrie'=>9,'octombrie'=>10,'noiembrie'=>11,'decembrie'=>12
];

function _norm_county(string $s): string {
    $s = strtoupper(trim($s));
    return strtr($s, [
        'Ă'=>'A','ă'=>'a','Â'=>'A','â'=>'a','Î'=>'I','î'=>'i',
        'Ș'=>'S','ș'=>'s','Ț'=>'T','ț'=>'t','Ş'=>'S','ş'=>'s','Ţ'=>'T','ţ'=>'t',
    ]);
}

function _county_code(string $raw): ?string {
    global $_NAME_TO_CODE;
    $n = _norm_county($raw);
    if (isset($_NAME_TO_CODE[$n])) return $_NAME_TO_CODE[$n];
    foreach ($_NAME_TO_CODE as $k => $v) {
        if (str_contains($n, $k) || str_contains($k, $n)) return $v;
    }
    return null;
}

function _fetch_url(string $url): string {
    $ctx = stream_context_create([
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        'http' => ['timeout' => 25, 'header' => "User-Agent: Mozilla/5.0\r\n"],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) throw new RuntimeException("HTTP error: $url");
    return $data;
}

function _parse_csv(string $text): array {
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", str_replace("\r", "", $text)))
    ));
    if (!$lines) return [];
    $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
    return array_map(fn($l) => array_map('trim', str_getcsv($l, $sep)), $lines);
}

function _int(string $s): ?int {
    $s = str_replace(['.', ',', ' ', "\xc2\xa0"], '', trim($s));
    return is_numeric($s) ? (int)$s : null;
}

function _float(string $s): ?float {
    $s = str_replace([',', ' ', "\xc2\xa0"], ['.', '', ''], trim($s));
    return is_numeric($s) ? (float)$s : null;
}

// File 1: per-county total unemployment rate
function _parse_rates(string $text): array {
    $rows = _parse_csv($text);
    $out = [];
    foreach (array_slice($rows, 1) as $r) {
        if (count($r) < 7) continue;
        $code = _county_code($r[0]);
        if (!$code) continue;
        $total = _int($r[1]);
        $rate  = _float($r[6]);
        if ($total === null || $rate === null) continue;
        $out[$code] = [$total, $rate];
    }
    return $out;
}

// File 2: by urban/rural environment
function _parse_env(string $text): array {
    $rows = _parse_csv($text);
    if (!$rows) return [];
    $h = array_map('strtoupper', $rows[0]);
    $tc = 1; $uc = 4; $rc = 7;
    foreach ($h as $i => $col) {
        if (str_contains($col,'TOTAL') && str_contains($col,'SOMERI') && !str_contains($col,'URBAN') && !str_contains($col,'RURAL')) $tc = $i;
        if (str_contains($col,'URBAN') && str_contains($col,'TOTAL')) $uc = $i;
        if (str_contains($col,'RURAL') && str_contains($col,'TOTAL')) $rc = $i;
    }
    $out = [];
    foreach (array_slice($rows, 1) as $r) {
        if (count($r) < 2) continue;
        $code = _county_code($r[0]);
        if (!$code) continue;
        $total = _int($r[$tc] ?? '');
        if ($total === null) continue;
        $out[$code] = [
            'urban' => _int($r[$uc] ?? '') ?? 0,
            'rural' => _int($r[$rc] ?? '') ?? 0,
            'total' => $total,
        ];
    }
    return $out;
}

// File 3: by education level
function _parse_edu(string $text): array {
    $rows = _parse_csv($text);
    $out = [];
    foreach (array_slice($rows, 1) as $r) {
        if (count($r) < 9) continue;
        $code = _county_code($r[0]);
        if (!$code) continue;
        $total  = _int($r[1]);
        $primar = (_int($r[2])??0) + (_int($r[3])??0) + (_int($r[4])??0);
        $secund = (_int($r[5])??0) + (_int($r[6])??0) + (_int($r[7])??0);
        $univ   = _int($r[8] ?? '') ?? 0;
        $out[$code] = [
            'primar'   => $primar,
            'secundar' => $secund,
            'superior' => $univ,
            'total'    => $total ?? ($primar + $secund + $univ),
        ];
    }
    return $out;
}

// File 4: by age group
function _parse_age(string $text): array {
    $rows = _parse_csv($text);
    $out = [];
    foreach (array_slice($rows, 1) as $r) {
        if (count($r) < 7) continue;
        $code = _county_code($r[0]);
        if (!$code) continue;
        $total = _int($r[1]);
        $ages = [
            '15-24' => _int($r[2]) ?? 0,
            '25-34' => _int($r[3]) ?? 0,
            '35-44' => _int($r[4]) ?? 0,
            '45-54' => _int($r[5]) ?? 0,
            '55-64' => (_int($r[6])??0) + (count($r)>7 ? (_int($r[7])??0) : 0),
        ];
        $out[$code] = array_merge($ages, ['total' => $total ?? array_sum($ages)]);
    }
    return $out;
}

function _detect_ym(string $name): array {
    global $_MONTHS_RO;
    $name = strtolower($name);
    $year = null;
    foreach (['2020','2021','2022','2023','2024','2025'] as $y) {
        if (str_contains($name, $y)) { $year = (int)$y; break; }
    }
    $month = null;
    foreach ($_MONTHS_RO as $ro => $num) {
        if (str_contains($name, $ro)) { $month = $num; break; }
    }
    if ($year && !$month) return [null, null];
    return [$year, $month];
}

/**
 * Fetch all available months from data.gov.ro and populate unemployment_records.
 * Clears existing data and query cache before starting.
 * Calls $progress(string $msg) for each dataset processed (optional).
 * Returns ['imported'=>N, 'skipped'=>N, 'total'=>N, 'months'=>N, 'errors'=>[...]].
 */
function fetch_all_data(PDO $pdo, ?callable $progress = null): array {
    set_time_limit(300);
    ignore_user_abort(true);

    $county_ids = [];
    foreach ($pdo->query("SELECT code, id FROM counties") as $r) {
        $county_ids[$r['code']] = (int)$r['id'];
    }

    // Pull dataset list from data.gov.ro CKAN API
    $api_json = json_decode(_fetch_url(DATAGOV_API), true);
    $datasets = [];
    foreach ($api_json['result']['results'] ?? [] as $r) {
        $name  = $r['name'] ?? '';
        $urls  = array_column(
            array_filter($r['resources'] ?? [], fn($x) => !empty($x['url'])),
            'url'
        );
        if (count($urls) >= 4 && str_contains(strtolower($name), 'somaj')) {
            $datasets[] = ['name' => $name, 'resources' => array_slice($urls, 0, 4)];
        }
    }

    // Clear stale data
    $pdo->exec("DELETE FROM unemployment_records; DELETE FROM cache_entries;");

    $stmt = $pdo->prepare("
        INSERT OR REPLACE INTO unemployment_records
            (county_id, year, month, age_group, education_level, environment, value, count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $imported = 0; $skipped = 0; $errors = [];

    foreach ($datasets as $ds) {
        [$year, $month] = _detect_ym($ds['name']);
        if (!$year || !$month) { $skipped++; continue; }

        try {
            $t1 = _fetch_url($ds['resources'][0]);
            $t2 = _fetch_url($ds['resources'][1]);
            $t3 = _fetch_url($ds['resources'][2]);
            $t4 = _fetch_url($ds['resources'][3]);
        } catch (Exception $e) {
            $errors[] = "$year-$month: " . $e->getMessage();
            if ($progress) $progress("SKIP $year-$month: " . $e->getMessage());
            continue;
        }

        $rates = _parse_rates($t1);
        $env   = _parse_env($t2);
        $edu   = _parse_edu($t3);
        $age   = _parse_age($t4);

        $pdo->beginTransaction();
        foreach ($county_ids as $code => $cid) {
            if (!isset($rates[$code])) continue;
            [$total_count, $total_rate] = $rates[$code];
            if ($total_rate === null || $total_count == 0) continue;

            $env_d = $env[$code] ?? []; $edu_d = $edu[$code] ?? []; $age_d = $age[$code] ?? [];
            $et = $env_d['total'] ?? $total_count;
            $dt = $edu_d['total'] ?? $total_count;
            $at = $age_d['total'] ?? $total_count;
            $frac = fn($c, $t) => $t > 0 ? round($total_rate * $c / $t, 3) : 0.0;

            $stmt->execute([$cid, $year, $month, 'total', 'total', 'total', $total_rate, $total_count]);
            foreach (['15-24','25-34','35-44','45-54','55-64'] as $ag) {
                $c = $age_d[$ag] ?? 0;
                $stmt->execute([$cid, $year, $month, $ag, 'total', 'total', $frac($c, $at), $c]);
            }
            foreach (['primar','secundar','superior'] as $ed) {
                $c = $edu_d[$ed] ?? 0;
                $stmt->execute([$cid, $year, $month, 'total', $ed, 'total', $frac($c, $dt), $c]);
            }
            foreach (['urban','rural'] as $ev) {
                $c = $env_d[$ev] ?? 0;
                $stmt->execute([$cid, $year, $month, 'total', 'total', $ev, $frac($c, $et), $c]);
            }
        }
        $pdo->commit();
        $imported++;
        if ($progress) $progress("OK $year-$month");
    }

    $total  = (int)$pdo->query("SELECT COUNT(*) FROM unemployment_records")->fetchColumn();
    $months = (int)$pdo->query("
        SELECT COUNT(DISTINCT year||printf('%02d',month))
        FROM unemployment_records
        WHERE age_group='total' AND education_level='total' AND environment='total'
    ")->fetchColumn();

    return [
        'imported' => $imported,
        'skipped'  => $skipped,
        'total'    => $total,
        'months'   => $months,
        'errors'   => $errors,
    ];
}
