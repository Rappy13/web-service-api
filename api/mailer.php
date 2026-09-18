<?php
/**
 * 寄信共用模組
 * 改用 Resend 的 HTTP API（走HTTPS，不受Render免費方案封鎖SMTP埠的限制）
 *
 * 需要的環境變數：
 *   RESEND_API_KEY    - 在 https://resend.com 註冊後於後台取得
 *   RESEND_FROM_EMAIL - 寄件者email，沒驗證自訂網域前只能用 onboarding@resend.dev
 *   SMTP_FROM_NAME     - 寄件者顯示名稱
 *
 * 注意：Resend 若尚未驗證你自己的網域，收件者只能是你Resend帳號本身註冊的Email，
 * 寄給其他地址會被API拒絕。要正式對外寄信，需要到Resend後台驗證一個網域。
 */

/**
 * @return array{sent: bool, error: string|null}
 */
function send_email(string $toEmail, string $subject, string $html): array {
    $apiKey = getenv('RESEND_API_KEY');
    if (!$apiKey) {
        return ['sent' => false, 'error' => '尚未設定RESEND_API_KEY環境變數，略過寄信'];
    }

    $fromEmail = getenv('RESEND_FROM_EMAIL') ?: 'onboarding@resend.dev';
    $fromName = getenv('SMTP_FROM_NAME') ?: '安全Q12問卷系統';

    $payload = json_encode([
        'from' => "{$fromName} <{$fromEmail}>",
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $html,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10, // 秒，避免卡住整個API請求
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['sent' => false, 'error' => "cURL錯誤: {$curlError}"];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['sent' => true, 'error' => null];
    }

    $decoded = json_decode($response, true);
    $message = $decoded['message'] ?? $response;
    return ['sent' => false, 'error' => "Resend API錯誤 (HTTP {$httpCode}): {$message}"];
}

/**
 * 寄送問卷連結email（客戶登錄成功或Q16生成時使用）
 * @param string $surveyName 問卷名稱，會出現在信件標題與內文，預設「安全 Q12」
 * @return array{sent: bool, error: string|null}
 */
function send_survey_email(string $toEmail, string $unitName, string $surveyUrl, string $expiresAt, string $surveyName = '安全 Q12'): array {
    $html = <<<HTML
        <p>您好，</p>
        <p>感謝貴單位（{$unitName}）登錄，以下是本次「{$surveyName}」問卷的作答連結：</p>
        <p><a href="{$surveyUrl}">{$surveyUrl}</a></p>
        <p><strong>作答截止時間：{$expiresAt}（GMT+8）</strong>，逾期將無法作答，請盡快完成填寫。</p>
        HTML;

    return send_email($toEmail, "【{$unitName}】{$surveyName}問卷作答連結", $html);
}

/**
 * 寄送作答期限截止後的分析結果email
 *
 * @param array $layerAverages  格式同 admin_customer_detail.php 回傳的 layer_averages：
 *                               [{title, questions:[Q1,Q2,...], average}, ...]
 * @param array $questionAverages  格式：['Q1' => 4.2, 'Q2' => 3.8, ...]
 * @param array $questionMeanings  格式：['Q1' => '第一反應', ...]
 * @return array{sent: bool, error: string|null}
 */
function send_analysis_email(
    string $toEmail,
    string $unitName,
    int $responseCount,
    array $layerAverages,
    array $questionAverages,
    array $questionMeanings
): array {
    if ($responseCount === 0) {
        $bodyHtml = '<p>本次作答期限已截止，但目前尚無任何作答紀錄。</p>';
    } else {
        $rows = '';
        foreach ($layerAverages as $layer) {
            $rows .= "<tr><td colspan=\"2\" style=\"padding:10px 6px 4px;font-weight:bold;background:#f3f4f6;\">{$layer['title']}</td></tr>";
            foreach ($layer['questions'] as $q) {
                $meaning = $questionMeanings[$q] ?? '';
                $avg = $questionAverages[$q] ?? '-';
                $rows .= "<tr><td style=\"padding:6px;border-bottom:1px solid #e5e7eb;\">{$q}．{$meaning}</td><td style=\"padding:6px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold;\">{$avg}</td></tr>";
            }
        }
        $bodyHtml = <<<HTML
            <p>本次作答期限已截止，共收到 {$responseCount} 筆作答，各題平均分數（1~5分）如下：</p>
            <table style="border-collapse:collapse;width:100%;max-width:480px;font-size:14px;">
                {$rows}
            </table>
            HTML;
    }

    $html = <<<HTML
        <p>您好，</p>
        <p>貴單位（{$unitName}）的「安全 Q12」問卷作答期限已截止，以下是本次的分析結果：</p>
        {$bodyHtml}
        HTML;

    return send_email($toEmail, "【{$unitName}】安全Q12問卷分析結果", $html);
}
