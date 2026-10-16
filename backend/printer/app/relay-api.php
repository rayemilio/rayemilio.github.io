<?php
declare(strict_types=1);

function oop_printer_json(int $status, array $body): never
{
    oop_printer_headers('application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function oop_printer_read_json(int $maximum = 43000): array
{
    if (!preg_match('/^application\/json(?:\s*;|$)/i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))) throw new OopPrinterRelayException('Send JSON.', 415);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximum) throw new OopPrinterRelayException('Print job is too large.', 413);
    $raw = file_get_contents('php://input', false, null, 0, $maximum + 1);
    if (!is_string($raw) || strlen($raw) > $maximum) throw new OopPrinterRelayException('Print job is too large.', 413);
    $body = json_decode($raw, true);
    if (!is_array($body)) throw new OopPrinterRelayException('Expected a JSON object.');
    return $body;
}

function oop_printer_owner_api(string $endpoint): never
{
    require_once __DIR__ . '/relay-lib.php';
    try {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($endpoint === 'status' && $method === 'GET') {
            $result = oop_printer_with_state(function (array &$state): array {
                $device = $state['device'];
                // Dense thermal prints can occupy the UART for a while. Keep
                // the last authenticated heartbeat online for two minutes.
                $now = time();
                $connection = oop_printer_connection($device, $now);
                $online = $connection['online'];
                $streamCapabilities = oop_printer_public_stream_capabilities($device);
                $jobs = array_map('oop_printer_public_job', array_reverse($state['jobs']));
                return [
                    'mode' => 'relay', 'transport' => 'outbound HTTPS relay',
                    'version' => (string)($device['version'] ?? ''),
                    'board' => 'Classic ESP32 / ESP-WROOM-32', 'printer' => 'QR204', 'camera' => 'Local control only',
                    'busy' => (bool)($device['busy'] ?? false),
                    'connection' => $connection,
                    'printerState' => oop_printer_public_printer_state($device, $now),
                    'settings' => ['name' => 'Paper companion', 'baud' => 9600, 'networkOnline' => $online, 'wifiSsid' => '', 'photoTimestamp' => false, 'utcOffsetMinutes' => 0, 'feedLines' => 4, 'textFont' => 'default', 'textSize' => 'default', 'sleepMinutes' => 0, 'darkness' => 'normal', 'printSpeed' => 'normal'],
                    'clock' => ['valid' => true], 'storage' => ['ready' => true, 'warning' => ''],
                    'capabilities' => ['text' => true, 'paperFeed' => false, 'nativeText' => true, 'styledText' => true, ...$streamCapabilities, 'raster' => true, 'packedRaster' => true, 'camera' => false, 'viewfinder' => false, 'remote' => $online ? 'connected' : 'offline', 'hardwareTuning' => false, 'sleep' => false],
                    'jobs' => $jobs,
                ];
            });
            oop_printer_json(200, $result);
        }
        if ($endpoint === 'jobs' && $method === 'POST') {
            $body = oop_printer_read_json();
            oop_printer_validate_job($body);
            $result = oop_printer_with_state(function (array &$state) use ($body): array {
                $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $digest = hash('sha256', $encoded);
                foreach ($state['jobs'] as $job) {
                    if (($job['id'] ?? '') !== $body['id']) continue;
                    if (!hash_equals((string)($job['digest'] ?? ''), $digest)) throw new OopPrinterRelayException('Request ID already contains different content.', 409);
                    return oop_printer_public_job($job);
                }
                $pending = 0;
                foreach ($state['jobs'] as $job) if (in_array($job['status'] ?? '', ['uploading', 'queued', 'delivering', 'processing'], true)) $pending++;
                if ($pending >= 8) throw new OopPrinterRelayException('Remote print queue is full.', 409);
                $now = time();
                $job = ['id' => $body['id'], 'kind' => $body['kind'], 'status' => 'queued', 'created' => $now, 'updated' => $now, 'expires' => $now + 86400, 'digest' => $digest, 'body' => $body, 'label' => oop_printer_job_label(['kind' => $body['kind'], 'body' => $body]), 'error' => '', 'events' => [], 'progress' => null];
                oop_printer_job_event($job, 'queued', $now);
                $state['jobs'][] = $job;
                return oop_printer_public_job($job);
            });
            oop_printer_json(202, $result);
        }
        if ($endpoint === 'jobs/cancel' && $method === 'POST') {
            $body = oop_printer_read_json(1024);
            $id = oop_printer_cancel_request_id($body);
            $result = oop_printer_with_state(fn(array &$state): array => oop_printer_cancel_job($state, $id, time()));
            oop_printer_json(200, $result);
        }
        if ($endpoint === 'jobs/raster/start' && $method === 'POST') {
            $body = oop_printer_read_json(8192);
            $result = oop_printer_with_state(fn(array &$state): array => oop_printer_start_raster_stream($state, $body, time()));
            oop_printer_json(($result['status'] ?? '') === 'uploading' ? 200 : 202, $result);
        }
        if ($endpoint === 'jobs/raster/chunk' && $method === 'POST') {
            $body = oop_printer_read_json(8192);
            $result = oop_printer_with_state(fn(array &$state): array => oop_printer_append_raster_chunk($state, $body, time()));
            oop_printer_json(200, $result);
        }
        if ($endpoint === 'jobs/raster/commit' && $method === 'POST') {
            $body = oop_printer_read_json(1024);
            $id = oop_printer_cancel_request_id($body);
            $result = oop_printer_with_state(fn(array &$state): array => oop_printer_commit_raster_stream($state, $id, time()));
            if (isset($result['failure'])) oop_printer_json(422, ['error' => (string)($result['failure']['error'] ?? 'Raster integrity check failed.'), 'job' => $result['failure']]);
            if (($result['accepted'] ?? true) !== true) oop_printer_json(409, $result['job']);
            oop_printer_json(202, $result['job']);
        }
        if ($endpoint === 'jobs/raster/abort' && $method === 'POST') {
            $body = oop_printer_read_json(1024);
            $id = oop_printer_cancel_request_id($body);
            $result = oop_printer_with_state(fn(array &$state): array => oop_printer_abort_raster_stream($state, $id, time()));
            oop_printer_json(200, $result);
        }
        oop_printer_json(404, ['error' => 'Remote printer endpoint not found.']);
    } catch (OopPrinterRelayException $error) {
        oop_printer_json($error->status, ['error' => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log('Printer owner relay error: ' . $error->getMessage());
        oop_printer_json(500, ['error' => 'Remote printing is temporarily unavailable.']);
    }
}
