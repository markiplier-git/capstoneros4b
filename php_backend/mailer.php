<?php
// Outgoing mail via PHPMailer + Gmail SMTP. Returns [true, ''] on success,
// [false, $message] on failure (offline, bad credentials, timeout).
// Never throws: callers turn the message into user-facing feedback.
require_once __DIR__ . '/mail_env.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function sendMail($to, $subject, $body) {
    $cfg = mailConfig();
    if ($cfg['username'] === '' || $cfg['password'] === '') {
        return [false, 'Mail is not configured.'];
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_readable($autoload)) {
        return [false, 'Mail library missing. Run composer install while online.'];
    }
    require_once $autoload;
    $debug = (($_ENV['MAIL_DEBUG'] ?? '') === '1');
    $log = '';
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 10;
        if ($debug) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function ($str) use (&$log) {
                $log .= $str . "\n";
            };
        }
        $mail->setFrom($cfg['username'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        return [true, ''];
    } catch (MailException $e) {
        return [false, $debug ? ('SMTP: ' . trim($log) . ' | ' . $e->getMessage()) : 'Could not send mail. Check internet connection and mail settings.'];
    } catch (Throwable $e) {
        return [false, $debug ? ('ERR: ' . $e->getMessage()) : 'Could not send mail. Check internet connection and mail settings.'];
    }
}
?>
