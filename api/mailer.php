<?php
/**
 * 寄送問卷連結email
 * 改用 Resend 的 HTTP API（走HTTPS，不受Render免費方案封鎖SMTP埠的限制）
 *
 * 需要的環境變數：
 *   RESEND_API_KEY   - 在 https://resend.com 註冊後於後台取得
 *   RESEND_FROM_EMAIL - 寄件者email，沒驗證自訂網域前只能用 onboarding@resend.dev
 *   SMTP_FROM_NAME    - 寄件者顯示名稱（沿用舊變數名稱，沒設定會用預設值）
 *
 * 注意：Resend 若尚未驗證你自己的網域，收件者只能是你Resend帳號本身註冊的Email，
 * 寄給其他地址會被API拒絕。要正式對外寄信，需要到Resend後台驗證一個網域。
 *
 * @return array{sent: bool, error: string|null}
 */
function send_survey_email(string $toEmail, string $unitName, string $surveyUrl, string $expiresAt): array {
    $apiKey = getenv('RESEND_API_KEY');
    if (!$apiKey) {
        return ['sent' => false, 'error' => '尚未設定RESEND_API_KEY環境變數，略過寄信'];
    }

    $fromEmail = getenv('RESEND_FROM_EMAIL') ?: 'onboarding@resend.dev';
    $fromName = getenv('SMTP_FROM_NAME') ?: '消防安全問卷系統';

    $html = <<<HTML
        <p>您好，</p>
        <p>感謝貴單位（{$unitName}）登錄，以下是本次消防安全Q12問卷的作答連結：</p>
        <p><a href="{$surveyUrl}">{$surveyUrl}</a></p>
        <p><strong>作答截止時間：{$expiresAt}（GMT+8）</strong>，逾期將無法作答，請盡快完成填寫。</p>
        HTML;

    $payload = json_encode([
        'from' => "{$fromName} <{$fromEmail}>",
        'to' => [$toEmail],
        'subject' => "【{$unitName}】消防安全Q12問卷作答連結",
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
