<?php
declare(strict_types=1);

header('Cache-Control: no-store, max-age=0');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');

require_once __DIR__ . '/relay-lib.php';

function deviceResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') deviceResponse(405, ['error' => 'Method not allowed.']);
    if (!preg_match('/^application\/json(?:\s*;|$)/i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))) deviceResponse(415, ['error' => 'Send JSON.']);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) deviceResponse(413, ['error' => 'Request too large.']);
    $tokenFile = getenv('OOP_PRINTER_TOKEN_FILE');
    if (!is_string($tokenFile) || $tokenFile === '') $tokenFile = oop_printer_site_root() . '/protected/printer-device-token';
    $expected = is_file($tokenFile) ? trim((string)@file_get_contents($tokenFile)) : '';
    $supplied = (string)($_SERVER['HTTP_X_PRINTER_TOKEN'] ?? '');
    if (strlen($expected) !== 64 || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1 || !hash_equals($expected, $supplied)) deviceResponse(401, ['error' => 'Device not authorized.']);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($body)) deviceResponse(400, ['error' => 'Expected a JSON object.']);
    $result = oop_printer_with_state(fn(array &$state): array => oop_printer_process_device_request($state, $body, time()));
    deviceResponse(200, $result);
} catch (OopPrinterRelayException $error) {
    deviceResponse($error->status, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Printer device relay error: ' . $error->getMessage());
    deviceResponse(500, ['error' => 'Relay unavailable.']);
}
