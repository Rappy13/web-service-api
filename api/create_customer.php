<?php
/**
 * POST /api/create_customer.php
 * body(JSON): { "unit_name": "...", "email": "...", "phone": "..." (optional) }
 *
 * 成功回傳: { "success": true, "id": "A1B2C3D4E5" }
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
require_once __DIR__ . '/mailer.php';

// 讀取輸入（相容 JSON body 或一般表單 POST）
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$unit_name = trim($input['unit_name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');

// --- 驗證 ---
$errors = [];

if ($unit_name === '') {
    $errors[] = '單位名稱為必填';
}

if ($email === '') {
    $errors[] = 'Email為必填';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email格式不正確';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('；', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

$phone = $phone === '' ? null : $phone;

$pdo = get_db_connection();

// --- 檢查這個Email是否已經有尚未過期的紀錄，有的話直接回傳既有的，不重新產生ID也不寄信 ---
$siteUrl = rtrim(getenv('SITE_URL') ?: (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $_SERVER['HTTP_HOST']), '/');

$existingStmt = $pdo->prepare(
    'SELECT id, created_at, expires_at FROM customer 
     WHERE email = :email AND expires_at > NOW() 
     ORDER BY created_at DESC LIMIT 1'
);
$existingStmt->execute(['email' => $email]);
$existing = $existingStmt->fetch();

if ($existing) {
    echo json_encode([
        'success' => true,
        'id' => $existing['id'],
        'created_at' => $existing['created_at'],
        'expires_at' => $existing['expires_at'],
        'survey_url' => $siteUrl . '/fireq12.html?id=' . $existing['id'],
        'email_sent' => false,
        'reused' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 產生10碼英數ID（大寫英文+數字），並確保不重複 ---
function generate_id(int $length = 10): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

$newId = '';
$maxAttempts = 10;
for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
    $candidate = generate_id(10);
    $check = $pdo->prepare('SELECT 1 FROM customer WHERE id = :id');
    $check->execute(['id' => $candidate]);
    if (!$check->fetch()) {
        $newId = $candidate;
        break;
    }
}

if ($newId === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ID產生失敗，請重新送出'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 寫入資料庫 ---
try {
    $stmt = $pdo->prepare(
        'INSERT INTO customer (id, unit_name, email, phone, created_at, expires_at) 
         VALUES (:id, :unit_name, :email, :phone, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))'
    );
    $stmt->execute([
        'id' => $newId,
        'unit_name' => $unit_name,
        'email' => $email,
        'phone' => $phone,
    ]);

    // 直接查回資料庫實際寫入的時間，確保跟DB一致（已透過db_config.php統一設為GMT+8）
    $timeStmt = $pdo->prepare('SELECT created_at, expires_at FROM customer WHERE id = :id');
    $timeStmt->execute(['id' => $newId]);
    $times = $timeStmt->fetch();

    // 組出問卷作答網址並寄信給填寫人
    $surveyUrl = $siteUrl . '/fireq12.html?id=' . $newId;
    $emailResult = send_survey_email($email, $unit_name, $surveyUrl, $times['expires_at']);

    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => true,
        'id' => $newId,
        'created_at' => $times['created_at'],
        'expires_at' => $times['expires_at'],
        'survey_url' => $surveyUrl,
        'email_sent' => $emailResult['sent'],
        'email_error' => $debug ? $emailResult['error'] : null,
        'reused' => false,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => false,
        'message' => '資料寫入失敗',
        'debug' => $debug ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
