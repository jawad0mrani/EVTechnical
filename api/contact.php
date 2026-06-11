<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'This request cannot be processed. Please use the official inquiry form.']);
    exit;
}

const SMTP_HOST = 'smtp.hostinger.com';
const SMTP_PORT = 465;
const SMTP_USERNAME = 'booking@uaetechnical24.com';
const SMTP_PASSWORD = '!!';
const SMTP_FROM = 'booking@uaetechnical24.com';
const SMTP_TO = 'booking@uaetechnical24.com';

function respond(bool $ok, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

function clean_value(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\r", "\0"], '', $value);
    return mb_substr($value, 0, 2000);
}

$name = clean_value((string)($_POST['name'] ?? ''));
$phone = clean_value((string)($_POST['phone'] ?? ''));
$email = clean_value((string)($_POST['email'] ?? ''));
$message = clean_value((string)($_POST['message'] ?? ''));

if ($name === '' || $phone === '') {
    respond(false, 'Please provide your name and phone number so our team can contact you.', 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address or leave the email field empty.', 422);
}

if (SMTP_PASSWORD === '') {
    respond(false, 'The inquiry service is temporarily unavailable. Please contact us by phone or WhatsApp.', 500);
}

$subject = 'New EVTechnical inquiry from ' . $name;
$bodyLines = [
    'New inquiry from EVTechnical website',
    '',
    'Name: ' . $name,
    'Phone: ' . $phone,
    'Email: ' . ($email !== '' ? $email : 'Not provided'),
    '',
    'Message:',
    $message !== '' ? $message : 'Not provided',
    '',
    'Sent: ' . gmdate('Y-m-d H:i:s') . ' UTC',
];
$body = implode("\r\n", $bodyLines);

function smtp_read($socket): string
{
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtp_command($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    $response = smtp_read($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }
    return $response;
}

function smtp_send_mail(string $subject, string $body, string $replyTo = ''): void
{
    $socket = stream_socket_client(
        'ssl://' . SMTP_HOST . ':' . SMTP_PORT,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException('Could not connect to SMTP server: ' . $errstr);
    }

    stream_set_timeout($socket, 20);
    $greeting = smtp_read($socket);
    if ((int)substr($greeting, 0, 3) !== 220) {
        throw new RuntimeException('SMTP greeting failed: ' . trim($greeting));
    }

    smtp_command($socket, 'EHLO uaetechnical24.com', [250]);
    smtp_command($socket, 'AUTH LOGIN', [334]);
    smtp_command($socket, base64_encode(SMTP_USERNAME), [334]);
    smtp_command($socket, base64_encode(SMTP_PASSWORD), [235]);
    smtp_command($socket, 'MAIL FROM:<' . SMTP_FROM . '>', [250]);
    smtp_command($socket, 'RCPT TO:<' . SMTP_TO . '>', [250, 251]);
    smtp_command($socket, 'DATA', [354]);

    $headers = [
        'From: EVTechnical Website <' . SMTP_FROM . '>',
        'To: ' . SMTP_TO,
        'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $payload = preg_replace('/^\./m', '..', $payload);
    fwrite($socket, $payload . "\r\n.\r\n");
    $response = smtp_read($socket);
    if ((int)substr($response, 0, 3) !== 250) {
        throw new RuntimeException('SMTP send failed: ' . trim($response));
    }

    smtp_command($socket, 'QUIT', [221]);
    fclose($socket);
}

try {
    smtp_send_mail($subject, $body, $email);
    respond(true, 'Your inquiry has been sent successfully. Our team will contact you shortly.');
} catch (Throwable $error) {
    error_log($error->getMessage());
    respond(false, 'The inquiry could not be sent at this time. Please contact us by phone or WhatsApp.', 500);
}
