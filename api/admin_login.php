<?php
/**
 * POST /api/admin_login.php
 * body(JSON): { "username": "...", "password": "..." }
 */

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

require_once __DIR__ . '/admin_auth.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

$validUser = getenv('ADMIN_USERNAME');
$validPass = getenv('ADMIN_PASSWORD');

if ($validUser === false || $validPass === false || $validUser === '' || $validPass === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '後台尚未設定管理員帳號密碼'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($username === '' || $password === '' || !hash_equals($validUser, $username) || !hash_equals($validPass, $password)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '帳號或密碼錯誤'], JSON_UNESCAPED_UNICODE);
    exit;
}

configure_admin_session();
session_regenerate_id(true);
$_SESSION['is_admin'] = true;

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
