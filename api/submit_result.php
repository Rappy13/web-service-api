<?php
/**
 * POST /api/submit_result.php
 * body(JSON): {
 *   "id": "customer的10碼ID",
 *   "years_of_service": "...", "position": "...",
 *   "is_fire_brigade_member": "是"|"否", "has_fire_training": "是"|"否",
 *   "last_training_time": "...",
 *   "used_extinguisher": "是"|"否", "joined_tabletop_drill": "是"|"否",
 *   "joined_live_drill": "是"|"否", "experienced_real_fire": "是"|"否",
 *   "Q1": 1~5, ..., "Q12": 1~5
 * }
 * 本問卷為不記名，不收集填答人姓名
 *
 * 成功回傳: { "success": true }
 * 失敗回傳: { "success": false, "message": "..." }
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db_config.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$id = trim($input['id'] ?? '');

// --- 驗證 ---
$errors = [];

if ($id === '') {
    $errors[] = '缺少客戶ID';
}

// 基本資料：填空類欄位（必填文字）
$textFields = [
    'years_of_service' => '年資',
    'position' => '職務',
    'last_training_time' => '最近一次訓練時間',
];
$textValues = [];
foreach ($textFields as $key => $label) {
    $value = trim($input[$key] ?? '');
    if ($value === '') {
        $errors[] = "{$label}為必填";
        continue;
    }
    $textValues[$key] = $value;
}

// 基本資料：是/否類欄位（必填，值只能是"是"或"否"）
$yesNoFields = [
    'is_fire_brigade_member' => '是否為自衛消防編組成員',
    'has_fire_training' => '是否接受過消防訓練',
    'used_extinguisher' => '是否實際操作過滅火器',
    'joined_tabletop_drill' => '是否參與過桌面演練',
    'joined_live_drill' => '是否參加過實兵演練',
    'experienced_real_fire' => '是否曾遇過真實火警',
];
$yesNoValues = [];
foreach ($yesNoFields as $key => $label) {
    $value = trim($input[$key] ?? '');
    if ($value !== '是' && $value !== '否') {
        $errors[] = "{$label}為必填";
        continue;
    }
    $yesNoValues[$key] = $value;
}

$questionKeys = [];
for ($i = 1; $i <= 12; $i++) {
    $questionKeys[] = 'Q' . $i;
}

$scores = [];
foreach ($questionKeys as $key) {
    $value = $input[$key] ?? null;
    if ($value === null || $value === '') {
        $errors[] = "{$key} 未作答";
        continue;
    }
    if (!is_numeric($value) || (int)$value < 1 || (int)$value > 5) {
        $errors[] = "{$key} 分數必須介於1到5之間";
        continue;
    }
    $scores[$key] = (int)$value;
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('；', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 寫入資料庫 ---
$pdo = get_db_connection();

// 檢查這個customer ID是否存在且尚未過期
try {
    $checkStmt = $pdo->prepare('SELECT expires_at FROM customer WHERE id = :id');
    $checkStmt->execute(['id' => $id]);
    $customer = $checkStmt->fetch();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '找不到此ID，無法送出'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (new DateTime() > new DateTime($customer['expires_at'])) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => '此ID已過期，無法送出作答'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => false,
        'message' => '驗證ID失敗',
        'debug' => $debug ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $columns = array_merge(['id'], array_keys($textFields), array_keys($yesNoFields), $questionKeys);
    $placeholders = array_map(fn($c) => ':' . $c, $columns);

    $sql = 'INSERT INTO result (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);

    $params = array_merge(['id' => $id], $textValues, $yesNoValues, $scores);
    $stmt->execute($params);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => false,
        'message' => '資料寫入失敗',
        'debug' => $debug ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
