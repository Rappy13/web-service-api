<?php
/**
 * GET /api/verify_customer.php?id=XXXXXXXXXX
 *
 * 驗證這組customer ID是否存在、且尚未過期
 *
 * 成功回傳: { "success": true, "unit_name": "...", "expires_at": "2026-09-04 15:30:00" }
 * 失敗回傳: { "success": false, "message": "...", "expired": true|false }
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db_config.php';

$id = trim($_GET['id'] ?? '');

if ($id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少ID參數'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db_connection();

try {
    $stmt = $pdo->prepare('SELECT unit_name, expires_at FROM customer WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '找不到此ID，請確認連結是否正確', 'expired' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $expiresAt = new DateTime($customer['expires_at']);
    $now = new DateTime();

    if ($now > $expiresAt) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => '此連結已過期，無法作答', 'expired' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'unit_name' => $customer['unit_name'],
        'expires_at' => $customer['expires_at'],
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => false,
        'message' => '驗證失敗',
        'debug' => $debug ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
