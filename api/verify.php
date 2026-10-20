<?php

/**
 * Telebirr transaction verification API.
 *
 * GET  /api/verify.php?receipt=CGP5Q3APB5
 * GET  /api/verify.php?receipt=CGP5Q3APB5&receiver_account=0911223344&amount=63
 * POST /api/verify.php   (JSON or form body with the same fields)
 *
 * Fields:
 *   receipt           (required) receipt number or full transactioninfo URL
 *   receiver_name     (optional) expected credited party name
 *   receiver_account  (optional) expected credited party phone/account (masking tolerated)
 *   payer_name        (optional) expected payer name
 *   payer_phone       (optional) expected payer phone (masking tolerated)
 *   amount            (optional) expected exact settled amount in ETB
 *   min_amount        (optional) minimum settled amount in ETB
 */

declare(strict_types=1);

require __DIR__ . '/../src/ReceiptParser.php';
require __DIR__ . '/../src/Verifier.php';

use TelebirrVerifier\Verifier;

$config = is_file(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($config['cors_origin'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    respond(204, []);
}
if (!in_array($method, ['GET', 'POST'], true)) {
    respond(405, ['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
}

// Optional API key protection (set 'api_key' in config.php).
if (!empty($config['api_key'])) {
    $given = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    if (!hash_equals((string) $config['api_key'], (string) $given)) {
        respond(401, ['success' => false, 'error' => 'Invalid or missing API key.']);
    }
}

$input = $_GET;
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $input = array_merge($input, is_array($json) ? $json : $_POST);
}

$receiptNo = Verifier::normalizeReceiptNo((string) ($input['receipt'] ?? $input['receipt_no'] ?? ''));
if ($receiptNo === null) {
    respond(400, [
        'success' => false,
        'error'   => 'Missing or invalid "receipt". Pass a telebirr receipt number (e.g. CGP5Q3APB5) or its transactioninfo URL.',
    ]);
}

$expect = array_intersect_key($input, array_flip([
    'receiver_name', 'receiver_account', 'payer_name', 'payer_phone', 'amount', 'min_amount',
]));
foreach (['amount', 'min_amount'] as $k) {
    if (isset($expect[$k]) && $expect[$k] !== '' && !is_numeric($expect[$k])) {
        respond(400, ['success' => false, 'error' => "\"$k\" must be numeric."]);
    }
}

try {
    $verifier = new Verifier($config['http'] ?? []);
    $result = $verifier->verify($receiptNo, $expect);
} catch (Throwable $e) {
    respond(502, ['success' => false, 'receipt_no' => $receiptNo, 'error' => $e->getMessage()]);
}

if (!$result['found']) {
    respond(404, [
        'success'    => false,
        'receipt_no' => $receiptNo,
        'verified'   => false,
        'error'      => 'Receipt not found on Ethio Telecom.',
    ]);
}

respond(200, [
    'success'     => true,
    'verified'    => $result['verified'],
    'receipt_no'  => $result['receipt']['receipt_no'] ?? $receiptNo,
    'receipt_url' => Verifier::BASE_URL . $receiptNo,
    'transaction' => $result['receipt'],
    'checks'      => $result['checks'],
]);
