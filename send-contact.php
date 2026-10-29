<?php
/*
 * Monocoque Interiors - contact form handler (PHPMailer over SMTP).
 * Receives the POST from /contact, validates it, and emails the enquiry.
 * SMTP host, username and password live in mail-config.php (outside public_html).
 */
declare(strict_types=1);

define('MONOCOQUE_MAIL', true);

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Send a JSON reply and stop. */
function respond(int $status, bool $ok, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Remove control characters (including CR/LF) and collapse whitespace. */
function clean_line(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s{2,}/u', ' ', $value) ?? '';
    return trim($value);
}

/** Keep line breaks in the message but remove other control characters. */
function clean_text(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '';
    $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';
    return trim($value);
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Rate limit per IP: 3 messages per 10 minutes and 10 per day.
 * Counters are stored outside public_html. Returns false when the visitor is over the limit.
 */
function rate_limit_allows(string $ip): bool
{
    $rateDir = dirname(__DIR__) . '/mail-rate';
    if (!is_dir($rateDir) && !@mkdir($rateDir, 0700, true)) {
        $rateDir = sys_get_temp_dir() . '/monocoque-mail-rate';
        if (!is_dir($rateDir)) {
            @mkdir($rateDir, 0700, true);
        }
    }
    $handle = @fopen($rateDir . '/' . hash('sha256', $ip) . '.json', 'c+');
    if ($handle === false) {
        return true;
    }
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $decoded = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
    $now = time();
    $hits = [];
    foreach (is_array($decoded) ? $decoded : [] as $t) {
        if (is_int($t) && $t > $now - 86400) {
            $hits[] = $t;
        }
    }
    $recent = count(array_filter($hits, static fn (int $t): bool => $t > $now - 600));
    $allowed = $recent < 3 && count($hits) < 10;
    if ($allowed) {
        $hits[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($hits));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $allowed;
}

set_exception_handler(function (Throwable $e): void {
    error_log('[send-contact] Uncaught: ' . $e->getMessage());
    respond(500, false, 'Sorry, something went wrong. Please call us or use WhatsApp.');
});

/* ---------- 1. Request checks ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Method not allowed.');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
    respond(413, false, 'Your message is too large.');
}

$siteHost = strtolower(preg_replace('/^www\./i', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
$origin   = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$referer  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$source   = $origin !== '' ? $origin : $referer;
$sourceHost = strtolower(preg_replace('/^www\./i', '', (string) parse_url($source, PHP_URL_HOST)) ?? '');
if ($source === '' || $sourceHost === '' || $sourceHost !== $siteHost) {
    respond(403, false, 'Request not allowed.');
}

/* ---------- 2. Spam checks ---------- */
// Honeypot: real visitors never see or fill this field. Pretend success to the bot.
if (clean_line((string) ($_POST['website'] ?? '')) !== '') {
    respond(200, true, 'Thank you! Your message has been sent. We will contact you shortly.');
}

// Timing: the form stamps the time when the page loads; humans need more than 2 seconds.
$stamp = (int) ($_POST['ts'] ?? 0);
$nowMs = (int) round(microtime(true) * 1000);
if ($stamp <= 0 || $stamp > $nowMs + 60000) {
    respond(400, false, 'Please reload the page and try again.');
}
if ($nowMs - $stamp < 2000) {
    respond(429, false, 'Please wait a moment and press Send again.');
}

/* ---------- 3. Validate input ---------- */
$allowedTypes = [
    'Residential Construction',
    'Interior Design & Finishing',
    'Renovation',
    'Commercial Project',
    'Other',
];

$name    = clean_line((string) ($_POST['name'] ?? ''));
$phone   = clean_line((string) ($_POST['phone'] ?? ''));
$email   = clean_line((string) ($_POST['email'] ?? ''));
$type    = clean_line((string) ($_POST['type'] ?? ''));
$message = clean_text((string) ($_POST['message'] ?? ''));

if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
    respond(422, false, 'Please enter your name.');
}
if (!preg_match('/^[0-9+()\-\s.]{6,25}$/', $phone)) {
    respond(422, false, 'Please enter a valid phone number.');
}
if ($email !== '' && (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    respond(422, false, 'Please enter a valid email address.');
}
if (!in_array($type, $allowedTypes, true)) {
    $type = 'Other';
}
if (mb_strlen($message) > 3000) {
    respond(422, false, 'Your message is too long. Please shorten it.');
}

/* ---------- 4. Load SMTP settings (outside public_html first) ---------- */
$configPath = null;
foreach ([dirname(__DIR__) . '/mail-config.php', __DIR__ . '/mail-config.php'] as $candidate) {
    if (is_file($candidate)) {
        $configPath = $candidate;
        break;
    }
}
if ($configPath === null) {
    error_log('[send-contact] mail-config.php not found.');
    respond(500, false, 'The contact form is not available right now. Please call us or use WhatsApp.');
}

$config = [];
require $configPath;

foreach (['host', 'username', 'password', 'port', 'secure', 'from', 'to'] as $key) {
    if (!isset($config[$key]) || $config[$key] === '') {
        error_log('[send-contact] mail-config.php is missing a value for "' . $key . '".');
        respond(500, false, 'The contact form is not available right now. Please call us or use WhatsApp.');
    }
}

/* ---------- 5. Rate limit, then build and send the email ---------- */
if (!rate_limit_allows((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
    respond(429, false, 'Too many messages from your connection. Please try again later or call us.');
}

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

$sentAt = date('d M Y, h:i A') . ' IST';
$messageText = $message !== '' ? $message : '-';
$emailText = $email !== '' ? $email : '-';

$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#0b1020;line-height:1.6">'
    . '<h2 style="margin:0 0 12px;color:#0a1f7a">New website enquiry</h2>'
    . '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;border:1px solid #d5dceb">'
    . '<tr><td style="border:1px solid #d5dceb"><strong>Name</strong></td><td style="border:1px solid #d5dceb">' . esc($name) . '</td></tr>'
    . '<tr><td style="border:1px solid #d5dceb"><strong>Phone</strong></td><td style="border:1px solid #d5dceb">' . esc($phone) . '</td></tr>'
    . '<tr><td style="border:1px solid #d5dceb"><strong>Email</strong></td><td style="border:1px solid #d5dceb">' . esc($emailText) . '</td></tr>'
    . '<tr><td style="border:1px solid #d5dceb"><strong>Project type</strong></td><td style="border:1px solid #d5dceb">' . esc($type) . '</td></tr>'
    . '<tr><td style="border:1px solid #d5dceb;vertical-align:top"><strong>Message</strong></td><td style="border:1px solid #d5dceb">' . nl2br(esc($messageText)) . '</td></tr>'
    . '</table>'
    . '<p style="margin-top:14px;font-size:12px;color:#4a5470">Sent from the contact form on ' . esc($siteHost) . ' at ' . esc($sentAt) . '</p>'
    . '</div>';

$plain = "New website enquiry\n\n"
    . 'Name: ' . $name . "\n"
    . 'Phone: ' . $phone . "\n"
    . 'Email: ' . $emailText . "\n"
    . 'Project type: ' . $type . "\n\n"
    . "Message:\n" . $messageText . "\n\n"
    . 'Sent from the contact form on ' . $siteHost . ' at ' . $sentAt;

try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->SMTPDebug  = 0;
    $mail->Host       = (string) $config['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = (string) $config['username'];
    $mail->Password   = (string) $config['password'];
    $mail->Port       = (int) $config['port'];
    $mail->SMTPSecure = strtolower((string) $config['secure']) === 'ssl'
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout    = 15;
    $mail->CharSet    = 'UTF-8';

    // From is always our own mailbox; the visitor's address goes in Reply-To only.
    $mail->setFrom((string) $config['from'], clean_line((string) ($config['from_name'] ?? '')));
    $mail->addAddress((string) $config['to']);
    if ($email !== '') {
        $mail->addReplyTo($email, $name);
    }

    $mail->isHTML(true);
    $mail->Subject = 'New enquiry: ' . $type . ' - ' . $name;
    $mail->Body    = $html;
    $mail->AltBody = $plain;
    $mail->send();
} catch (Throwable $e) {
    error_log('[send-contact] Mail failed: ' . $e->getMessage());
    respond(500, false, 'Sorry, we could not send your message. Please call us or use WhatsApp.');
}

respond(200, true, 'Thank you! Your message has been sent. We will contact you shortly.');
