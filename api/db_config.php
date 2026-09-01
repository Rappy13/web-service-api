<?php
/**
 * 資料庫連線設定
 * 這些值請在 Render 的 Environment Variables 設定，
 * 對應到 Clever Cloud MySQL add-on 提供的連線資訊
 * (在 Clever Cloud 的 add-on 頁面可以找到 Host / Port / Database / User / Password)
 */

function get_db_connection(): PDO {
    $host = getenv('DB_HOST');       // 例如 bxxxxxxxxxxxx-mysql.services.clever-cloud.com
    $port = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_NAME');     // Clever Cloud 給的資料庫名稱
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASSWORD');

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '資料庫連線失敗'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
