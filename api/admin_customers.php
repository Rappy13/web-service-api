<?php
/**
 * GET /api/admin_customers.php
 * 需要登入。回傳所有customer清單。
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/admin_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db_config.php';
$pdo = get_db_connection();

try {
    $stmt = $pdo->query(
        'SELECT id, unit_name, email, phone, created_at, expires_at 
         FROM customer 
         ORDER BY created_at DESC'
    );
    $customers = $stmt->fetchAll();

    echo json_encode(['success' => true, 'customers' => $customers], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => false,
        'message' => '查詢失敗',
        'debug' => $debug ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
