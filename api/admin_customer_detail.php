<?php
/**
 * GET /api/admin_customer_detail.php?id=XXXXXXXXXX
 * 需要登入。回傳：
 *   - customer基本資料（含Q16的生成/過期時間）
 *   - Q12（result表）：依題目層級分組、每一題的平均分數；每筆作答紀錄（不含record_id、id、created_at）
 *     依created_at由舊到新排序，並附上該筆的個人平均分數
 *   - Q16（result_q16表）：格式同上，另外還會回傳 q12_expired（Q12是否已過期，決定後台能不能按「生成Q16」）
 *     與 q16_started（Q16是否已經生成過，決定後台顯示按鈕還是顯示連結）
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

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

// 題目層級分組，需與 fireq12.html / fireq16.html 的 LAYERS 定義一致
$Q12_LAYERS = [
    ['title' => '一、基礎條件', 'questions' => ['Q1', 'Q2']],
    ['title' => '二、支持與發揮', 'questions' => ['Q3', 'Q4', 'Q5', 'Q6']],
    ['title' => '三、團隊連結', 'questions' => ['Q7', 'Q8', 'Q9', 'Q10']],
    ['title' => '四、成長與進步', 'questions' => ['Q11', 'Q12']],
];
$Q16_LAYERS = [
    ['title' => '一、應變認知與個人準備', 'questions' => ['FR1', 'FR2', 'FR3', 'FR4']],
    ['title' => '二、管理支持與風險回應', 'questions' => ['FR5', 'FR6', 'FR7', 'FR8']],
    ['title' => '三、團隊安全行為與協作', 'questions' => ['FR9', 'FR10', 'FR11', 'FR12']],
    ['title' => '四、訓練演練與持續改善', 'questions' => ['FR13', 'FR14', 'FR15', 'FR16']],
];

/**
 * 計算某張result表（table）對某個customer id的：依層級分組平均分數、每題平均分數、逐筆紀錄(含個人平均)
 * $extraColumns：逐筆紀錄除了題目分數外，還要一併選出的欄位（例如Q12表的基本資料欄位），Q16沒有則傳空陣列
 */
function analyze_result_table(PDO $pdo, string $table, string $id, array $layers, string $prefix, int $questionCount, array $extraColumns = []): array {
    $qCols = [];
    for ($i = 1; $i <= $questionCount; $i++) {
        $qCols[] = "AVG(`{$prefix}{$i}`) AS `{$prefix}{$i}`";
    }
    $avgStmt = $pdo->prepare(
        "SELECT " . implode(', ', $qCols) . ", COUNT(*) AS response_count FROM `{$table}` WHERE id = :id"
    );
    $avgStmt->execute(['id' => $id]);
    $avgRow = $avgStmt->fetch();

    $responseCount = (int)$avgRow['response_count'];

    $layerAverages = [];
    foreach ($layers as $layer) {
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
    for ($i = 1; $i <= $questionCount; $i++) {
        $questionAverages[$prefix . $i] = $responseCount === 0 ? null : round((float)$avgRow[$prefix . $i], 2);
    }

    $qSelectCols = array_map(fn($c) => "`{$c}`", $extraColumns);
    for ($i = 1; $i <= $questionCount; $i++) {
        $qSelectCols[] = "`{$prefix}{$i}`";
    }
    $recordStmt = $pdo->prepare(
        "SELECT " . implode(', ', $qSelectCols) . " FROM `{$table}` WHERE id = :id ORDER BY created_at ASC"
    );
    $recordStmt->execute(['id' => $id]);
    $records = $recordStmt->fetchAll();

    foreach ($records as &$record) {
        $sum = 0;
        for ($i = 1; $i <= $questionCount; $i++) {
            $sum += (int)$record[$prefix . $i];
        }
        $record['personal_average'] = round($sum / $questionCount, 2);
    }
    unset($record);

    return [
        'response_count' => $responseCount,
        'layer_averages' => $layerAverages,
        'question_averages' => $questionAverages,
        'records' => $records,
    ];
}

$pdo = get_db_connection();

try {
    // --- 客戶基本資料（含Q16狀態）與 Q12是否已過期 ---
    $custStmt = $pdo->prepare(
        'SELECT id, unit_name, email, phone, created_at, expires_at, q16_started_at, q16_expires_at,
                (NOW() > expires_at) AS q12_expired
         FROM customer WHERE id = :id'
    );
    $custStmt->execute(['id' => $id]);
    $customer = $custStmt->fetch();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '找不到此客戶'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q12Expired = (bool)$customer['q12_expired'];
    $q16Started = $customer['q16_started_at'] !== null;
    unset($customer['q12_expired']); // 已經拆出來放在外層，避免重複

    $q12ExtraColumns = [
        'years_of_service', 'position', 'is_fire_brigade_member', 'has_fire_training',
        'last_training_time', 'used_extinguisher', 'joined_tabletop_drill',
        'joined_live_drill', 'experienced_real_fire',
    ];
    $q12 = analyze_result_table($pdo, 'result', $id, $Q12_LAYERS, 'Q', 12, $q12ExtraColumns);
    $q16 = $q16Started
        ? analyze_result_table($pdo, 'result_q16', $id, $Q16_LAYERS, 'FR', 16)
        : null;

    echo json_encode([
        'success' => true,
        'customer' => $customer,
        'q12_expired' => $q12Expired,
        'q16_started' => $q16Started,
        'q12' => $q12,
        'q16' => $q16,
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
