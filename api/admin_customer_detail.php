<?php
/**
 * GET /api/admin_customer_detail.php?id=XXXXXXXXXX
 * 需要登入。回傳：
 *   - customer基本資料
 *   - 該customer id 對應的result表：依題目層級分組、每一題的平均分數
 *   - 該customer id 對應的result表：每筆作答紀錄（不含record_id、id、created_at），
 *     依created_at由舊到新排序，並附上該筆的個人平均分數
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

$id = trim($_GET['id'] ?? '');
if ($id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少ID參數'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 題目層級分組，需與 fireq12.html 的 LAYERS 定義一致
$LAYERS = [
    ['title' => '一、基本需求', 'questions' => ['Q1', 'Q2']],
    ['title' => '二、個人', 'questions' => ['Q3', 'Q4', 'Q5', 'Q6']],
    ['title' => '三、團隊', 'questions' => ['Q7', 'Q8', 'Q9', 'Q10']],
    ['title' => '四、成長', 'questions' => ['Q11', 'Q12']],
];

$pdo = get_db_connection();

try {
    // --- 客戶基本資料 ---
    $custStmt = $pdo->prepare(
        'SELECT id, unit_name, email, phone, created_at, expires_at FROM customer WHERE id = :id'
    );
    $custStmt->execute(['id' => $id]);
    $customer = $custStmt->fetch();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '找不到此客戶'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- 各題平均分數 ---
    $qCols = [];
    for ($i = 1; $i <= 12; $i++) {
        $qCols[] = "AVG(Q{$i}) AS Q{$i}";
    }
    $avgStmt = $pdo->prepare(
        'SELECT ' . implode(', ', $qCols) . ', COUNT(*) AS response_count FROM result WHERE id = :id'
    );
    $avgStmt->execute(['id' => $id]);
    $avgRow = $avgStmt->fetch();

    $responseCount = (int)$avgRow['response_count'];

    // --- 依層級組出平均分數 ---
    $layerAverages = [];
    foreach ($LAYERS as $layer) {
        if ($responseCount === 0) {
            $layerAverages[] = ['title' => $layer['title'], 'questions' => $layer['questions'], 'average' => null];
            continue;
        }
        $sum = 0;
        foreach ($layer['questions'] as $q) {
            $sum += (float)$avgRow[$q];
        }
        $layerAverages[] = [
            'title' => $layer['title'],
            'questions' => $layer['questions'],
            'average' => round($sum / count($layer['questions']), 2),
        ];
    }

    $questionAverages = [];
    for ($i = 1; $i <= 12; $i++) {
        $questionAverages['Q' . $i] = $responseCount === 0 ? null : round((float)$avgRow['Q' . $i], 2);
    }

    // --- 每筆作答紀錄（不含record_id、id、created_at，依created_at由舊到新排序） ---
    $recordStmt = $pdo->prepare(
        'SELECT years_of_service, position, is_fire_brigade_member, has_fire_training, 
                last_training_time, used_extinguisher, joined_tabletop_drill, joined_live_drill, 
                experienced_real_fire, Q1, Q2, Q3, Q4, Q5, Q6, Q7, Q8, Q9, Q10, Q11, Q12
         FROM result 
         WHERE id = :id 
         ORDER BY created_at ASC'
    );
    $recordStmt->execute(['id' => $id]);
    $records = $recordStmt->fetchAll();

    // --- 每筆紀錄的個人平均分數（該筆12題的平均） ---
    foreach ($records as &$record) {
        $sum = 0;
        for ($i = 1; $i <= 12; $i++) {
            $sum += (int)$record['Q' . $i];
        }
        $record['personal_average'] = round($sum / 12, 2);
    }
    unset($record);

    echo json_encode([
        'success' => true,
        'customer' => $customer,
        'response_count' => $responseCount,
        'layer_averages' => $layerAverages,
        'question_averages' => $questionAverages,
        'records' => $records,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    $debug = getenv('APP_DEBUG') === 'true';
    echo json_encode([
        'success' => false,
        'message' => '查詢失敗',
        'debug' => $debug ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
}
