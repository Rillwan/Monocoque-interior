<?php
/*
 * Monocoque Interiors - SMTP settings.
 * Keep this file ONE FOLDER ABOVE public_html (outside the web root).
 * It is loaded only by send-contact.php and cannot be opened from a browser.
 */
if (!defined('MONOCOQUE_MAIL')) {
    http_response_code(403);
    exit;
}

$config = [
    'host'      => 'smtp.gmail.com',
    'username'  => 'sales@monocoqueindia.com',
    'password'  => 'qkqpigqcqnpwzhki',
    'port'      => 587,
    'secure'    => 'tls',
    'from'      => 'sales@monocoqueindia.com',
    'from_name' => 'Monocoque Interiors Website',
    'to'        => 'sales@monocoqueindia.com',
];
