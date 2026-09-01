<?php
/**
 * 寄送問卷連結email
 * 使用SMTP，連線資訊透過Render環境變數設定：
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD,
 *   SMTP_SECURE (tls 或 ssl), SMTP_FROM_EMAIL, SMTP_FROM_NAME
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * @return array{sent: bool, error: string|null}
 */
function send_survey_email(string $toEmail, string $unitName, string $surveyUrl, string $expiresAt): array {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USER');
        $mail->Password = getenv('SMTP_PASSWORD');
        $mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_STARTTLS; // 'tls' 或 'ssl'
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->CharSet = 'UTF-8';

        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USER');
        $fromName = getenv('SMTP_FROM_NAME') ?: '消防安全問卷系統';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = "【{$unitName}】消防安全Q12問卷作答連結";
        $mail->Body = <<<HTML
            <p>您好，</p>
            <p>感謝貴單位（{$unitName}）登錄，以下是本次消防安全Q12問卷的作答連結：</p>
            <p><a href="{$surveyUrl}">{$surveyUrl}</a></p>
            <p><strong>作答截止時間：{$expiresAt}（GMT+8）</strong>，逾期將無法作答，請盡快完成填寫。</p>
            HTML;
        $mail->AltBody = "作答連結：{$surveyUrl}\n作答截止時間：{$expiresAt}（GMT+8）";

        $mail->send();
        return ['sent' => true, 'error' => null];
    } catch (Exception $e) {
        return ['sent' => false, 'error' => $mail->ErrorInfo];
    }
}
