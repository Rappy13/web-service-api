<?php
/**
 * POST /api/admin_logout.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin_auth.php';

configure_admin_session();
$_SESSION = [];
session_destroy();

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
