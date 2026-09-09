<?php
/**
 * 後台登入驗證共用模組
 * 帳號密碼透過環境變數設定：ADMIN_USERNAME、ADMIN_PASSWORD
 */

function configure_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,   // Render上都是HTTPS
        'httponly' => true, // JS無法讀取，避免XSS竊取session
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * 保護API：沒登入就直接擋下並回傳401
 */
function require_admin(): void {
    configure_admin_session();
    if (empty($_SESSION['is_admin'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '請先登入'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
