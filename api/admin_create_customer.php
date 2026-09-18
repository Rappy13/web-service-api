<?php
/**
 * POST /api/admin_create_customer.php
 * body(JSON): { "unit_name": "...", "email": "..." (可留空), "phone": "..." (可留空) }
 * 需要登入。給後台人員直接建立客戶用，跟公開表單 create_customer.php 不同的地方是：
 *   - Email 不是必填（沒填就不會寄信，也不會用Email比對是否重複）
 *
 * 成功回傳: { "success": true, "id":..., "created_at":..., "expires_at":..., "survey_url":..., "email_sent":..., "reused":... }
 * 失敗回傳: { "success": false, "message": "..." }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/admin_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/mailer.php';

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

// Email非必填，但有填就要格式正確
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email格式不正確';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('；', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

$phone = $phone === '' ? null : $phone;

$pdo = get_db_connection();
$siteUrl = rtrim(getenv('SITE_URL') ?: (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $_SERVER['HTTP_HOST']), '/');

// --- 只有在有填Email時，才檢查是否已經有尚未過期的同Email紀錄 ---
if ($email !== '') {
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
        'email' => $email, // 沒填就是空字串，DB欄位允許空字串（只是不能是NULL）
        'phone' => $phone,
    ]);

    $timeStmt = $pdo->prepare('SELECT created_at, expires_at FROM customer WHERE id = :id');
    $timeStmt->execute(['id' => $newId]);
    $times = $timeStmt->fetch();

    $surveyUrl = $siteUrl . '/fireq12.html?id=' . $newId;

    // 只有在有填Email時才寄信
    $emailResult = $email !== ''
        ? send_survey_email($email, $unit_name, $surveyUrl, $times['expires_at'])
        : ['sent' => false, 'error' => '未提供Email，未寄送'];

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
