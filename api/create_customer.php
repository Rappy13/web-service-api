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

// --- 產生10碼英數ID（大寫英文+數字），並確保不重複 ---
function generate_id(int $length = 10): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

$pdo = get_db_connection();

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

    $expiresAt = (new DateTime('+3 days'))->format('Y-m-d H:i:s');

    echo json_encode(['success' => true, 'id' => $newId, 'expires_at' => $expiresAt], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '資料寫入失敗'], JSON_UNESCAPED_UNICODE);
}
