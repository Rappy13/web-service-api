<?php
/**
 * POST /api/admin_generate_q16.php
 * body(JSON): { "id": "客戶的10碼ID" }
 * 需要登入。由後台人員按下「生成Q16量表」時呼叫。
 *
 * 防呆規則：
 *   1. 該ID必須存在
 *   2. Q12的作答期限（customer.expires_at）必須已經過了，才能生成Q16
 *   3. 該ID的Q16必須「尚未生成過」（q16_started_at 必須是NULL），避免重複啟動
 *
 * 成功回傳: { "success": true, "id":..., "q16_started_at":..., "q16_expires_at":..., "survey_url":... }
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

$id = trim($input['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少ID參數'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db_connection();

try {
    $custStmt = $pdo->prepare(
        'SELECT id, unit_name, email, expires_at, q16_started_at FROM customer WHERE id = :id'
    );
    $custStmt->execute(['id' => $id]);
    $customer = $custStmt->fetch();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '找不到此客戶'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($customer['q16_started_at'] !== null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '此ID的Q16量表已經生成過，不能重複生成'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 檢查Q12的作答期限是否已過（拿DB當下時間跟expires_at比較，避免PHP/DB時區不一致）
    $checkStmt = $pdo->prepare('SELECT (NOW() > expires_at) AS q12_expired FROM customer WHERE id = :id');
    $checkStmt->execute(['id' => $id]);
    $checkRow = $checkStmt->fetch();

    if (!$checkRow['q12_expired']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Q12的作答期限尚未截止，無法生成Q16量表'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE customer SET q16_started_at = NOW(), q16_expires_at = DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE id = :id'
    );
    $updateStmt->execute(['id' => $id]);

    $timeStmt = $pdo->prepare('SELECT q16_started_at, q16_expires_at FROM customer WHERE id = :id');
    $timeStmt->execute(['id' => $id]);
    $times = $timeStmt->fetch();

    $siteUrl = rtrim(getenv('SITE_URL') ?: (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . $_SERVER['HTTP_HOST']), '/');
    $surveyUrl = $siteUrl . '/fireq16.html?id=' . $id;

    $emailResult = send_survey_email($customer['email'], $customer['unit_name'], $surveyUrl, $times['q16_expires_at']);

    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => true,
        'id' => $id,
        'q16_started_at' => $times['q16_started_at'],
        'q16_expires_at' => $times['q16_expires_at'],
        'survey_url' => $surveyUrl,
        'email_sent' => $emailResult['sent'],
        'email_error' => $debug ? $emailResult['error'] : null,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => false,
        'message' => '生成失敗',
        'debug' => $debug ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
