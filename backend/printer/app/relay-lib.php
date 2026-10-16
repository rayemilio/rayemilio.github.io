<?php
declare(strict_types=1);

final class OopPrinterRelayException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

function oop_printer_site_root(): string
{
    $override = getenv('OOP_PRINTER_SITE_ROOT');
    if (is_string($override) && $override !== '') return rtrim($override, '/');
    $nfsRoot = $_SERVER['NFSN_SITE_ROOT'] ?? '';
    if (is_string($nfsRoot) && $nfsRoot !== '') return rtrim($nfsRoot, '/');
    return dirname(rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/'));
}

function oop_printer_store_file(): string
{
    $override = getenv('OOP_PRINTER_RELAY_FILE');
    if (is_string($override) && $override !== '') return $override;
    return oop_printer_site_root() . '/protected/printer-relay.json';
}

function oop_printer_raster_dir(): string
{
    $override = getenv('OOP_PRINTER_RASTER_DIR');
    if (is_string($override) && $override !== '') return rtrim($override, '/');
    return dirname(oop_printer_store_file()) . '/printer-raster';
}

function oop_printer_raster_path(string $id): string
{
    return oop_printer_raster_dir() . '/' . hash('sha256', $id) . '.bin';
}

function oop_printer_raster_remove(string $id): void
{
    $path = oop_printer_raster_path($id);
    if (is_file($path)) @unlink($path);
}

function oop_printer_initial_state(): array
{
    return ['version' => 1, 'device' => ['lastSeen' => 0, 'version' => '', 'busy' => false, 'printerState' => null, 'capabilities' => null, 'capabilitiesVersion' => ''], 'jobs' => []];
}

function oop_printer_clean_state(array &$state, int $now): void
{
    if (($state['version'] ?? null) !== 1 || !is_array($state['device'] ?? null) || !is_array($state['jobs'] ?? null)) {
        throw new RuntimeException('Printer relay state is invalid.');
    }
    foreach ($state['jobs'] as &$job) {
        if (!is_array($job)) continue;
        if (!isset($job['label']) || !is_string($job['label']) || $job['label'] === '') $job['label'] = oop_printer_job_label($job);
        $status = (string)($job['status'] ?? '');
        if ($status === 'uploading' && (int)($job['expires'] ?? 0) <= $now) {
            $job['status'] = 'expired';
            $job['updated'] = $now;
            oop_printer_job_event($job, 'expired', $now);
            oop_printer_raster_remove((string)($job['id'] ?? ''));
            unset($job['body']);
        } elseif ($status === 'queued' && (int)($job['expires'] ?? 0) <= $now) {
            $job['status'] = 'expired';
            $job['updated'] = $now;
            oop_printer_job_event($job, 'expired', $now);
            if (isset($job['rasterStream'])) oop_printer_raster_remove((string)($job['id'] ?? ''));
            unset($job['body']);
        } elseif ($status === 'delivering' && (int)($job['updated'] ?? 0) < $now - 120) {
            $job['status'] = 'uncertain';
            $job['error'] = 'Delivery was claimed but the printer did not confirm its outcome.';
            $job['updated'] = $now;
            oop_printer_job_event($job, 'uncertain', $now, $job['error']);
            if (isset($job['rasterStream'])) oop_printer_raster_remove((string)($job['id'] ?? ''));
            unset($job['body']);
        }
        if (!isset($job['events']) || !is_array($job['events'])) $job['events'] = [];
        if (!isset($job['progress']) || !is_array($job['progress'])) unset($job['progress']);
    }
    unset($job);
    if (count($state['jobs']) > 50) {
        $removed = array_slice($state['jobs'], 0, count($state['jobs']) - 50);
        foreach ($removed as $job) if (is_array($job) && (isset($job['rasterStream']) || ($job['status'] ?? '') === 'uploading')) oop_printer_raster_remove((string)($job['id'] ?? ''));
        $state['jobs'] = array_slice($state['jobs'], -50);
    }
}

function oop_printer_job_event(array &$job, string $status, int $at, string $error = ''): void
{
    if (!isset($job['events']) || !is_array($job['events'])) $job['events'] = [];
    $last = end($job['events']);
    if (is_array($last) && ($last['status'] ?? '') === $status && ($last['error'] ?? '') === $error) return;
    $event = ['status' => $status, 'at' => $at];
    if ($error !== '') $event['error'] = substr($error, 0, 300);
    $job['events'][] = $event;
    if (count($job['events']) > 16) $job['events'] = array_slice($job['events'], -16);
}

function oop_printer_set_job_status(array &$job, string $status, int $now, string $error = ''): void
{
    $changed = ($job['status'] ?? '') !== $status || ($job['error'] ?? '') !== $error;
    $job['status'] = $status;
    $job['updated'] = $now;
    $job['error'] = substr($error, 0, 300);
    if ($changed) oop_printer_job_event($job, $status, $now, $error);
}

function oop_printer_device_report_allowed(string $current, string $incoming): bool
{
    if (in_array($current, ['queued', 'cancelled', 'expired'], true)) return false;
    if (in_array($current, ['delivering', 'processing'], true)) return true;
    if ($current === 'uncertain' && in_array($incoming, ['sent', 'dry_run', 'failed', 'uncertain'], true)) return true;
    return $current === $incoming;
}

function oop_printer_connection(array $device, int $now): array
{
    $lastSeen = max(0, (int)($device['lastSeen'] ?? 0));
    $ageMs = $lastSeen > 0 ? max(0, ($now - $lastSeen) * 1000) : null;
    return [
        'deviceLastSeen' => $lastSeen > 0 ? $lastSeen : null,
        'deviceAgeMs' => $ageMs,
        'serverTime' => $now,
        'online' => $lastSeen > 0 && $lastSeen >= $now - 120,
    ];
}

function oop_printer_public_printer_state(array $device, int $now): array
{
    $connection = oop_printer_connection($device, $now);
    $telemetry = is_array($device['printerState'] ?? null) ? $device['printerState'] : [];
    $sampledAt = max(0, (int)($telemetry['sampledAt'] ?? 0));
    $lastProgressAgeMs = isset($telemetry['lastProgressAgeMs']) && $sampledAt > 0
        ? min(2592000000, (int)$telemetry['lastProgressAgeMs'] + max(0, ($now - $sampledAt) * 1000))
        : null;
    return [
        'state' => (string)($telemetry['state'] ?? 'unknown'),
        'activeJobId' => is_string($telemetry['activeJobId'] ?? null) ? $telemetry['activeJobId'] : null,
        'bytesSent' => isset($telemetry['bytesSent']) ? (int)$telemetry['bytesSent'] : null,
        'totalBytes' => isset($telemetry['totalBytes']) ? (int)$telemetry['totalBytes'] : null,
        'progressPercent' => isset($telemetry['progressPercent']) ? (int)$telemetry['progressPercent'] : null,
        'lastProgressAgeMs' => $lastProgressAgeMs,
        'elapsedMs' => isset($telemetry['elapsedMs']) ? (int)$telemetry['elapsedMs'] : null,
        'uptimeMs' => isset($telemetry['uptimeMs']) ? (int)$telemetry['uptimeMs'] : null,
        'paperStatus' => (string)($telemetry['paperStatus'] ?? 'unavailable'),
        'outputConfirmation' => 'uart-only',
        'stale' => !$connection['online'],
    ];
}

function oop_printer_normalize_telemetry(mixed $value, int $now): ?array
{
    if ($value === null) return null;
    if (!is_array($value)) throw new OopPrinterRelayException('Invalid printer state telemetry.');
    $allowed = ['state', 'activeJobId', 'bytesSent', 'totalBytes', 'progressPercent', 'percent', 'lastProgressAgeMs', 'elapsedMs', 'uptimeMs', 'paperStatus', 'outputConfirmation'];
    foreach (array_keys($value) as $key) if (!is_string($key) || !in_array($key, $allowed, true)) throw new OopPrinterRelayException('Unknown printer state field.');
    $result = [];
    if (array_key_exists('state', $value)) {
        if (!is_string($value['state']) || preg_match('/^[A-Za-z0-9 _-]{1,32}$/', $value['state']) !== 1) throw new OopPrinterRelayException('Invalid printer state.');
        $result['state'] = $value['state'];
    }
    if (array_key_exists('activeJobId', $value)) {
        if ($value['activeJobId'] !== null && (!is_string($value['activeJobId']) || ($value['activeJobId'] !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $value['activeJobId']) !== 1))) throw new OopPrinterRelayException('Invalid active job ID.');
        $result['activeJobId'] = $value['activeJobId'] === '' ? null : $value['activeJobId'];
    }
    $limits = ['bytesSent' => 1000000000, 'totalBytes' => 1000000000, 'progressPercent' => 100, 'lastProgressAgeMs' => 2592000000, 'elapsedMs' => 2592000000, 'uptimeMs' => 315360000000];
    foreach ($limits as $key => $maximum) {
        if (!array_key_exists($key, $value)) continue;
        if (!is_int($value[$key]) || $value[$key] < 0 || $value[$key] > $maximum) throw new OopPrinterRelayException('Invalid printer progress value.');
        $result[$key] = $value[$key];
    }
    if (array_key_exists('percent', $value)) {
        if (array_key_exists('progressPercent', $value) || !is_int($value['percent']) || $value['percent'] < 0 || $value['percent'] > 100) throw new OopPrinterRelayException('Invalid printer progress value.');
        $result['progressPercent'] = $value['percent'];
    }
    if (isset($result['bytesSent'], $result['totalBytes']) && $result['bytesSent'] > $result['totalBytes']) throw new OopPrinterRelayException('Invalid printer progress values.');
    if (array_key_exists('paperStatus', $value)) {
        if (!is_string($value['paperStatus']) || !in_array($value['paperStatus'], ['available', 'low', 'out', 'unknown', 'unavailable'], true)) throw new OopPrinterRelayException('Invalid paper status.');
        $result['paperStatus'] = $value['paperStatus'];
    }
    if (array_key_exists('outputConfirmation', $value)) {
        if ($value['outputConfirmation'] !== 'uart-only') throw new OopPrinterRelayException('Invalid output confirmation.');
        $result['outputConfirmation'] = 'uart-only';
    }
    $result['sampledAt'] = $now;
    return $result;
}

function oop_printer_normalize_device_capabilities(mixed $value): array
{
    if (!is_array($value)) throw new OopPrinterRelayException('Invalid device capabilities.');
    $allowed = ['rasterStream', 'maxRasterHeight', 'maxRenderedTextHeight', 'rasterStreamChunkBytes'];
    foreach (array_keys($value) as $key) if (!is_string($key) || !in_array($key, $allowed, true)) throw new OopPrinterRelayException('Unknown device capability.');
    if (count($value) !== count($allowed) || !is_bool($value['rasterStream'] ?? null) || !in_array($value['maxRasterHeight'] ?? null, [512, 8192], true) || !in_array($value['maxRenderedTextHeight'] ?? null, [512, 8192], true) || !in_array($value['rasterStreamChunkBytes'] ?? null, [0, 3072], true)) {
        throw new OopPrinterRelayException('Invalid device capabilities.');
    }
    $stream = $value['rasterStream'];
    $expectedHeight = $stream ? 8192 : 512;
    $expectedChunkBytes = $stream ? 3072 : 0;
    if ($value['maxRasterHeight'] !== $expectedHeight || $value['maxRenderedTextHeight'] !== $expectedHeight || $value['rasterStreamChunkBytes'] !== $expectedChunkBytes) {
        throw new OopPrinterRelayException('Inconsistent device raster capabilities.');
    }
    return [
        'rasterStream' => $stream,
        'maxRasterHeight' => $expectedHeight,
        'maxRenderedTextHeight' => $expectedHeight,
        'rasterStreamChunkBytes' => $expectedChunkBytes,
    ];
}

function oop_printer_public_stream_capabilities(array $device): array
{
    $caps = is_array($device['capabilities'] ?? null) ? $device['capabilities'] : [];
    $advertisedForCurrentVersion = (int)($device['lastSeen'] ?? 0) > 0
        && (string)($device['capabilitiesVersion'] ?? '') !== ''
        && hash_equals((string)($device['version'] ?? ''), (string)$device['capabilitiesVersion']);
    $stream = $advertisedForCurrentVersion && ($caps['rasterStream'] ?? false) === true;
    return [
        'rasterStream' => $stream,
        'maxRasterHeight' => $stream ? (int)$caps['maxRasterHeight'] : 512,
        'maxRenderedTextHeight' => $stream ? (int)$caps['maxRenderedTextHeight'] : 512,
        'rasterStreamChunkBytes' => $stream ? (int)$caps['rasterStreamChunkBytes'] : 0,
    ];
}

function oop_printer_process_device_request(array &$state, array $body, int $now): array
{
    $action = $body['action'] ?? '';
    $allowed = match ($action) {
        'claim' => ['action', 'version', 'busy', 'printerState', 'progress', 'capabilities'],
        'report' => ['action', 'version', 'busy', 'id', 'status', 'error', 'printerState', 'progress', 'capabilities'],
        'raster_chunk' => ['action', 'version', 'busy', 'id', 'offset', 'maxBytes', 'printerState', 'progress'],
        default => ['action'],
    };
    foreach (array_keys($body) as $key) if (!is_string($key) || !in_array($key, $allowed, true)) throw new OopPrinterRelayException('Unknown device field.');
    if (isset($body['version']) && (!is_string($body['version']) || strlen($body['version']) > 32)) throw new OopPrinterRelayException('Invalid device version.');
    if (isset($body['busy']) && !is_bool($body['busy'])) throw new OopPrinterRelayException('Invalid device busy state.');
    if (isset($body['error']) && (!is_string($body['error']) || strlen($body['error']) > 1000)) throw new OopPrinterRelayException('Invalid device error.');
    if (!in_array($action, ['claim', 'report', 'raster_chunk'], true)) throw new OopPrinterRelayException('Unknown device action.');

    $state['device']['lastSeen'] = $now;
    $previousVersion = (string)($state['device']['version'] ?? '');
    if (isset($body['version'])) {
        $state['device']['version'] = $body['version'];
        if ($previousVersion !== '' && $previousVersion !== $body['version']) {
            $state['device']['capabilities'] = null;
            $state['device']['capabilitiesVersion'] = '';
        }
    }
    if (array_key_exists('capabilities', $body)) {
        if (!isset($body['version'])) throw new OopPrinterRelayException('Capabilities require a device version.');
        if ($body['capabilities'] === null) {
            $state['device']['capabilities'] = null;
            $state['device']['capabilitiesVersion'] = '';
        } else {
            $state['device']['capabilities'] = oop_printer_normalize_device_capabilities($body['capabilities']);
            $state['device']['capabilitiesVersion'] = (string)$body['version'];
        }
    }
    $busy = $body['busy'] ?? (bool)($state['device']['busy'] ?? false);
    $state['device']['busy'] = $busy;
    $telemetry = oop_printer_normalize_telemetry($body['printerState'] ?? null, $now);
    if (array_key_exists('progress', $body)) {
        if (!is_array($body['progress'])) throw new OopPrinterRelayException('Invalid printer progress telemetry.');
        $progress = oop_printer_normalize_telemetry($body['progress'], $now);
        if ($progress === null || array_intersect(array_keys($progress), ['state', 'activeJobId', 'paperStatus', 'uptimeMs', 'outputConfirmation']) !== []) throw new OopPrinterRelayException('Invalid printer progress telemetry.');
        if ($telemetry === null) $telemetry = [];
        $telemetry = array_merge($telemetry, $progress);
    }
    if ($telemetry !== null) {
        $state['device']['printerState'] = array_merge(is_array($state['device']['printerState'] ?? null) ? $state['device']['printerState'] : [], $telemetry);
        $activeId = $state['device']['printerState']['activeJobId'] ?? null;
        if (is_string($activeId)) {
            $matched = false;
            foreach ($state['jobs'] as &$activeJob) {
                if (($activeJob['id'] ?? '') !== $activeId || !in_array($activeJob['status'] ?? '', ['delivering', 'processing'], true)) continue;
                $matched = true;
                $activeJob['progress'] = oop_printer_device_job_progress($state['device']['printerState'], $now);
                break;
            }
            unset($activeJob);
            if (!$matched) unset($state['device']['printerState']['activeJobId']);
        }
    }

    if ($action === 'claim') {
        $supportsRasterStream = array_key_exists('capabilities', $body)
            && is_array($state['device']['capabilities'] ?? null)
            && ($state['device']['capabilities']['rasterStream'] ?? false) === true
            && hash_equals((string)($state['device']['version'] ?? ''), (string)($state['device']['capabilitiesVersion'] ?? ''));
        return ['job' => oop_printer_claim_next($state, $now, $busy, $supportsRasterStream)];
    }

    if ($action === 'raster_chunk') {
        $id = $body['id'] ?? null;
        $offset = $body['offset'] ?? null;
        $maximum = $body['maxBytes'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1 || !is_int($offset) || $offset < 0 || !is_int($maximum) || $maximum < 1 || $maximum > 3072) throw new OopPrinterRelayException('Invalid raster chunk request.');
        foreach ($state['jobs'] as &$job) {
            if (($job['id'] ?? '') !== $id) continue;
            if (!in_array($job['status'] ?? '', ['delivering', 'processing'], true) || !is_array($job['rasterStream'] ?? null)) throw new OopPrinterRelayException('Raster job is not available to the device.', 409);
            $stream = $job['rasterStream'];
            $total = (int)($stream['bitmapBytes'] ?? 0);
            if ($offset >= $total) throw new OopPrinterRelayException('Raster chunk offset is outside the image.', 416);
            $handle = @fopen(oop_printer_raster_path($id), 'rb');
            if (!$handle) throw new RuntimeException('Raster upload storage is unavailable.');
            try {
                $size = fstat($handle)['size'] ?? -1;
                if ($size !== $total || fseek($handle, $offset) !== 0) throw new RuntimeException('Raster upload storage is invalid.');
                $data = fread($handle, min($maximum, $total - $offset));
                if (!is_string($data) || $data === '') throw new RuntimeException('Raster upload storage is incomplete.');
            } finally {
                fclose($handle);
            }
            $job['updated'] = $now;
            // Receiving a staged receipt occupies the device before UART output.
            $state['device']['busy'] = true;
            $result = ['id' => $id, 'offset' => $offset, 'data' => base64_encode($data), 'nextOffset' => $offset + strlen($data)];
            unset($job);
            return $result;
        }
        unset($job);
        throw new OopPrinterRelayException('Unknown print job.', 404);
    }

    $id = $body['id'] ?? '';
    $status = $body['status'] ?? '';
    if (!is_string($id) || !in_array($status, ['processing', 'sent', 'dry_run', 'failed', 'uncertain'], true)) throw new OopPrinterRelayException('Invalid device report.');
    foreach ($state['jobs'] as &$job) {
        if (($job['id'] ?? '') !== $id) continue;
        $current = (string)($job['status'] ?? '');
        if (!oop_printer_device_report_allowed($current, $status)) {
            $message = in_array($current, ['queued', 'cancelled', 'expired'], true) ? 'Print job was not claimed by the device.' : 'Print job outcome is already recorded.';
            throw new OopPrinterRelayException($message, 409);
        }
        $error = is_string($body['error'] ?? null) ? substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $body['error']) ?? '', 0, 300) : '';
        oop_printer_set_job_status($job, $status, $now, $error);
        if (array_key_exists('progress', $body)) $job['progress'] = oop_printer_device_job_progress($state['device']['printerState'] ?? [], $now);
        if ($status === 'processing') {
            $state['device']['printerState']['activeJobId'] = $id;
        } elseif (($state['device']['printerState']['activeJobId'] ?? null) === $id) {
            $state['device']['printerState']['activeJobId'] = null;
        }
        if ($status !== 'processing') {
            if (isset($job['rasterStream'])) oop_printer_raster_remove($id);
            unset($job['body']);
        }
        unset($job);
        return ['ok' => true];
    }
    unset($job);
    throw new OopPrinterRelayException('Unknown print job.', 404);
}

function oop_printer_device_job_progress(array $telemetry, int $now): ?array
{
    $progress = [];
    foreach (['bytesSent', 'totalBytes', 'progressPercent', 'lastProgressAgeMs', 'elapsedMs'] as $metric) {
        if (isset($telemetry[$metric]) && is_int($telemetry[$metric])) $progress[$metric] = $telemetry[$metric];
    }
    if ($progress === []) return null;
    $progress['updated'] = $now;
    return $progress;
}

function oop_printer_job_label(array $job): string
{
    if (is_string($job['label'] ?? null) && $job['label'] !== '') return substr($job['label'], 0, 64);
    if (($job['kind'] ?? '') !== 'text' || !is_string($job['body']['text'] ?? null)) return 'Raster print job';
    $line = preg_split('/\r\n|\r|\n/', $job['body']['text'], 2)[0] ?? '';
    $line = trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $line) ?? '');
    if ($line === '') return 'Text print job';
    return function_exists('mb_strimwidth') ? mb_strimwidth($line, 0, 64, '…', 'UTF-8') : substr($line, 0, 64);
}

function oop_printer_public_progress(array $job): ?array
{
    if (!is_array($job['progress'] ?? null)) return null;
    $source = $job['progress'];
    $public = [];
    foreach (['bytesSent', 'totalBytes', 'elapsedMs'] as $key) {
        if (isset($source[$key]) && is_int($source[$key]) && $source[$key] >= 0) $public[$key] = $source[$key];
    }
    $percent = $source['percent'] ?? $source['progressPercent'] ?? null;
    if (is_int($percent) && $percent >= 0 && $percent <= 100) {
        $public['percent'] = $percent;
        $public['progressPercent'] = $percent;
    }
    if (isset($source['lastProgressAgeMs']) && is_int($source['lastProgressAgeMs']) && $source['lastProgressAgeMs'] >= 0) {
        $sampleAge = isset($source['updated']) ? max(0, (time() - (int)$source['updated']) * 1000) : 0;
        $public['lastProgressAgeMs'] = min(2592000000, $source['lastProgressAgeMs'] + $sampleAge);
    } elseif (isset($source['updated'])) {
        $public['lastProgressAgeMs'] = min(2592000000, max(0, (time() - (int)$source['updated']) * 1000));
    }
    return $public === [] ? null : $public;
}

function oop_printer_claim_next(array &$state, int $now, bool $busy, bool $supportsRasterStream = false): ?array
{
    $state['device']['busy'] = $busy;
    if ($busy) return null;
    foreach ($state['jobs'] as &$job) {
        if (($job['status'] ?? '') !== 'queued' || !is_array($job['body'] ?? null)) continue;
        if (isset($job['rasterStream']) && !$supportsRasterStream) continue;
        oop_printer_set_job_status($job, 'delivering', $now);
        $body = $job['body'];
        unset($job);
        return $body;
    }
    unset($job);
    return null;
}

function oop_printer_cancel_job(array &$state, string $id, int $now): array
{
    foreach ($state['jobs'] as &$job) {
        if (($job['id'] ?? '') !== $id) continue;
        if (($job['status'] ?? '') === 'cancelled') {
            $public = oop_printer_public_job($job);
            unset($job);
            return $public;
        }
        if (($job['status'] ?? '') !== 'queued') throw new OopPrinterRelayException('Only queued jobs can be cancelled.', 409);
        oop_printer_set_job_status($job, 'cancelled', $now);
        if (isset($job['rasterStream'])) oop_printer_raster_remove($id);
        unset($job['body']);
        $public = oop_printer_public_job($job);
        unset($job);
        return $public;
    }
    unset($job);
    throw new OopPrinterRelayException('Unknown print job.', 404);
}

function oop_printer_cancel_request_id(array $body): string
{
    if (count($body) !== 1 || !array_key_exists('id', $body) || !is_string($body['id']) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $body['id']) !== 1) {
        throw new OopPrinterRelayException('Invalid print job ID.');
    }
    return $body['id'];
}

function oop_printer_with_state(callable $callback): mixed
{
    $file = oop_printer_store_file();
    $dir = dirname($file);
    $oldUmask = umask(0077);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        umask($oldUmask);
        throw new RuntimeException('Printer relay storage is unavailable.');
    }
    $handle = @fopen($file, 'c+');
    umask($oldUmask);
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) fclose($handle);
        throw new RuntimeException('Printer relay storage is busy.');
    }
    try {
        @chmod($file, 0600);
        $raw = stream_get_contents($handle);
        $state = trim((string)$raw) === '' ? oop_printer_initial_state() : json_decode((string)$raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new RuntimeException('Printer relay state is invalid.');
        oop_printer_clean_state($state, time());
        $result = $callback($state);
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (ftruncate($handle, 0) === false || rewind($handle) === false || fwrite($handle, $encoded . "\n") !== strlen($encoded) + 1 || !fflush($handle)) {
            throw new RuntimeException('Printer relay state could not be saved.');
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function oop_printer_public_job(array $job): array
{
    return [
        'id' => (string)($job['id'] ?? ''),
        'kind' => (string)($job['kind'] ?? ''),
        'source' => 'remote',
        'status' => (string)($job['status'] ?? ''),
        'created' => (int)($job['created'] ?? 0),
        'updated' => (int)($job['updated'] ?? 0),
        'expires' => (int)($job['expires'] ?? 0),
        'label' => oop_printer_job_label($job),
        'error' => (string)($job['error'] ?? ''),
        'timeline' => is_array($job['events'] ?? null) ? array_values($job['events']) : [],
        'progress' => oop_printer_public_progress($job),
        'storageWarning' => '',
    ];
}

function oop_printer_bitmap_valid(mixed $encoded, int $bytes): bool
{
    if (!is_string($encoded) || strlen($encoded) !== intdiv($bytes + 2, 3) * 4) return false;
    $decoded = base64_decode($encoded, true);
    return is_string($decoded) && strlen($decoded) === $bytes;
}

function oop_printer_validate_job(array $body): void
{
    $id = $body['id'] ?? null;
    $kind = $body['kind'] ?? null;
    if (!is_string($id) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1 || !in_array($kind, ['text', 'raster'], true) || ($body['source'] ?? null) !== 'remote') {
        throw new OopPrinterRelayException('Invalid remote print job.');
    }
    if ($kind === 'raster') {
        $width = $body['width'] ?? null;
        $height = $body['height'] ?? null;
        if (!is_int($width) || !is_int($height) || $width < 8 || $width > 384 || $width % 8 !== 0 || $height < 1 || $height > 512 || !oop_printer_bitmap_valid($body['bitmap'] ?? null, intdiv($width, 8) * $height)) {
            throw new OopPrinterRelayException('Invalid remote image dimensions or bitmap.');
        }
        if (array_key_exists('timestamp', $body) && !is_bool($body['timestamp'])) {
            throw new OopPrinterRelayException('Invalid remote photo timestamp option.');
        }
        if (array_key_exists('utcOffsetMinutes', $body) && (!is_int($body['utcOffsetMinutes']) || $body['utcOffsetMinutes'] < -840 || $body['utcOffsetMinutes'] > 840)) {
            throw new OopPrinterRelayException('Invalid remote photo time zone.');
        }
        $allowed = ['id', 'kind', 'source', 'width', 'height', 'bitmap', 'timestamp', 'utcOffsetMinutes'];
    } else {
        $text = $body['text'] ?? null;
        if (!is_string($text) || strlen($text) < 1 || strlen($text) > 2048 || preg_match('/[^\x09\x0a\x0d\x20-\x7e]/', $text)) {
            throw new OopPrinterRelayException('Use 1–2,048 printable ASCII characters.');
        }
        if (isset($body['rendered'])) {
            $rendered = $body['rendered'];
            $width = $rendered['width'] ?? null;
            $height = $rendered['height'] ?? null;
            if (!is_array($rendered) || $width !== 384 || !is_int($height) || $height < 1 || $height > 512 || !oop_printer_bitmap_valid($rendered['bitmap'] ?? null, 48 * $height) || !is_string($rendered['stamp'] ?? '')) {
                throw new OopPrinterRelayException('Invalid rendered text bitmap.');
            }
        }
        $allowed = ['id', 'kind', 'source', 'text', 'textFont', 'textSize', 'rendered', 'timestamp', 'utcOffsetMinutes'];
    }
    foreach (array_keys($body) as $key) if (!in_array($key, $allowed, true)) throw new OopPrinterRelayException('Unknown remote print field.');
}

function oop_printer_stream_metadata(array $body): array
{
    $allowed = ['id', 'kind', 'source', 'width', 'height', 'bitmapBytes', 'bitmapSha256', 'text', 'textFont', 'textSize', 'stamp', 'timestamp', 'utcOffsetMinutes'];
    foreach (array_keys($body) as $key) if (!is_string($key) || !in_array($key, $allowed, true)) throw new OopPrinterRelayException('Unknown raster stream field.');
    $id = $body['id'] ?? null;
    $kind = $body['kind'] ?? null;
    $width = $body['width'] ?? null;
    $height = $body['height'] ?? null;
    $bitmapBytes = $body['bitmapBytes'] ?? null;
    $sha = $body['bitmapSha256'] ?? null;
    if (!is_string($id) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1 || !in_array($kind, ['text', 'raster'], true) || ($body['source'] ?? null) !== 'remote') {
        throw new OopPrinterRelayException('Invalid remote raster stream.');
    }
    if (!is_int($width) || !is_int($height) || $width < 8 || $width > 384 || $width % 8 !== 0 || $height < 1 || $height > 8192 || !is_int($bitmapBytes) || $bitmapBytes !== intdiv($width, 8) * $height || !is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
        throw new OopPrinterRelayException('Invalid remote raster stream dimensions or digest.');
    }
    if ($kind === 'text' && $width !== 384) throw new OopPrinterRelayException('Rendered text must be 384 pixels wide.');
    if (isset($body['text']) && (!is_string($body['text']) || strlen($body['text']) < 1 || strlen($body['text']) > 2048 || preg_match('/[^\x09\x0a\x0d\x20-\x7e]/', $body['text']))) {
        throw new OopPrinterRelayException('Invalid remote text content.');
    }
    if ($kind === 'text' && !is_string($body['text'] ?? null)) throw new OopPrinterRelayException('Text stream is missing its source text.');
    if ($kind === 'raster' && array_key_exists('text', $body)) throw new OopPrinterRelayException('Raster stream must not include text content.');
    if (isset($body['stamp']) && (!is_string($body['stamp']) || strlen($body['stamp']) > 128)) throw new OopPrinterRelayException('Invalid raster stream stamp.');
    if (isset($body['timestamp']) && !is_bool($body['timestamp'])) throw new OopPrinterRelayException('Invalid raster stream timestamp option.');
    if (isset($body['utcOffsetMinutes']) && (!is_int($body['utcOffsetMinutes']) || $body['utcOffsetMinutes'] < -840 || $body['utcOffsetMinutes'] > 840)) throw new OopPrinterRelayException('Invalid raster stream time zone.');
    foreach (['textFont', 'textSize'] as $key) if (isset($body[$key]) && (!is_string($body[$key]) || strlen($body[$key]) > 32)) throw new OopPrinterRelayException('Invalid raster stream text style.');
    return $body;
}

function oop_printer_canonical_json(array $value): string
{
    $sort = static function (array $input) use (&$sort): array {
        foreach ($input as &$child) if (is_array($child)) $child = $sort($child);
        unset($child);
        if (!array_is_list($input)) ksort($input, SORT_STRING);
        return $input;
    };
    return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function oop_printer_upload_status(array $job): array
{
    return ['id' => (string)($job['id'] ?? ''), 'status' => (string)($job['status'] ?? ''), 'nextOffset' => (int)($job['nextOffset'] ?? 0), 'chunkBytes' => 3072];
}

function oop_printer_start_raster_stream(array &$state, array $input, int $now): array
{
    $metadata = oop_printer_stream_metadata($input);
    $id = $metadata['id'];
    $digest = hash('sha256', oop_printer_canonical_json($metadata));
    foreach ($state['jobs'] as $job) {
        if (($job['id'] ?? '') !== $id) continue;
        if (!hash_equals((string)($job['digest'] ?? ''), $digest)) throw new OopPrinterRelayException('Request ID already contains different content.', 409);
        return ($job['status'] ?? '') === 'uploading' ? oop_printer_upload_status($job) : oop_printer_public_job($job);
    }
    $capabilities = oop_printer_public_stream_capabilities($state['device'] ?? []);
    if (!$capabilities['rasterStream']) throw new OopPrinterRelayException('Printer has not advertised streamed raster support.', 409);
    $pending = 0;
    foreach ($state['jobs'] as $job) if (in_array($job['status'] ?? '', ['uploading', 'queued', 'delivering', 'processing'], true)) $pending++;
    if ($pending >= 8) throw new OopPrinterRelayException('Remote print queue is full.', 409);
    $directory = oop_printer_raster_dir();
    $oldUmask = umask(0077);
    $madeDirectory = !is_dir($directory) && @mkdir($directory, 0700, true);
    umask($oldUmask);
    if (!is_dir($directory)) throw new RuntimeException('Raster upload storage is unavailable.');
    @chmod($directory, 0700);
    if (file_exists(oop_printer_raster_path($id)) && !@unlink(oop_printer_raster_path($id))) throw new RuntimeException('Raster upload storage is unavailable.');
    $oldUmask = umask(0077);
    $handle = @fopen(oop_printer_raster_path($id), 'x+b');
    umask($oldUmask);
    if (!$handle) throw new RuntimeException('Raster upload storage is unavailable.');
    @chmod(oop_printer_raster_path($id), 0600);
    fclose($handle);
    $job = ['id' => $id, 'kind' => $metadata['kind'], 'status' => 'uploading', 'created' => $now, 'updated' => $now, 'expires' => $now + 600, 'digest' => $digest, 'body' => $metadata, 'label' => oop_printer_job_label(['kind' => $metadata['kind'], 'body' => $metadata]), 'error' => '', 'events' => [], 'progress' => null, 'nextOffset' => 0];
    oop_printer_job_event($job, 'uploading', $now);
    $state['jobs'][] = $job;
    return oop_printer_upload_status($job);
}

function oop_printer_append_raster_chunk(array &$state, array $input, int $now): array
{
    $id = $input['id'] ?? null;
    $offset = $input['offset'] ?? null;
    $encoded = $input['data'] ?? null;
    if (count($input) !== 3 || !is_string($id) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1 || !is_int($offset) || $offset < 0 || !is_string($encoded)) throw new OopPrinterRelayException('Invalid raster chunk.');
    $chunk = base64_decode($encoded, true);
    if (!is_string($chunk) || strlen($chunk) < 1 || strlen($chunk) > 3072) throw new OopPrinterRelayException('Raster chunks must contain 1–3,072 bytes.');
    foreach ($state['jobs'] as &$job) {
        if (($job['id'] ?? '') !== $id) continue;
        if (($job['status'] ?? '') !== 'uploading' || !is_array($job['body'] ?? null)) throw new OopPrinterRelayException('Raster upload is not open.', 409);
        $nextOffset = (int)($job['nextOffset'] ?? 0);
        $total = (int)($job['body']['bitmapBytes'] ?? 0);
        if ($offset !== $nextOffset || $offset + strlen($chunk) > $total) throw new OopPrinterRelayException('Raster chunk offset is out of sequence.', 409);
        $handle = @fopen(oop_printer_raster_path($id), 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            throw new RuntimeException('Raster upload storage is unavailable.');
        }
        try {
            @chmod(oop_printer_raster_path($id), 0600);
            if (fseek($handle, $offset) !== 0 || fwrite($handle, $chunk) !== strlen($chunk) || !fflush($handle)) throw new RuntimeException('Raster upload could not be saved.');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $job['nextOffset'] = $offset + strlen($chunk);
        $job['updated'] = $now;
        $result = oop_printer_upload_status($job);
        unset($job);
        return $result;
    }
    unset($job);
    throw new OopPrinterRelayException('Unknown raster upload.', 404);
}

function oop_printer_commit_raster_stream(array &$state, string $id, int $now): array
{
    foreach ($state['jobs'] as &$job) {
        if (($job['id'] ?? '') !== $id) continue;
        if (($job['status'] ?? '') !== 'uploading') {
            $accepted = in_array($job['status'] ?? '', ['queued', 'delivering', 'processing', 'sent', 'dry_run', 'uncertain'], true);
            $result = ['job' => oop_printer_public_job($job), 'accepted' => $accepted];
            unset($job);
            return $result;
        }
        if (!is_array($job['body'] ?? null)) throw new OopPrinterRelayException('Raster upload is not open.', 409);
        $metadata = $job['body'];
        $expectedBytes = (int)$metadata['bitmapBytes'];
        $path = oop_printer_raster_path($id);
        clearstatcache(true, $path);
        $actualBytes = is_file($path) ? filesize($path) : false;
        if (!is_int($actualBytes) || $actualBytes !== $expectedBytes || (int)($job['nextOffset'] ?? 0) !== $expectedBytes) throw new OopPrinterRelayException('Raster upload is incomplete.', 409);
        $actualSha = hash_file('sha256', $path);
        if (!is_string($actualSha) || !hash_equals((string)$metadata['bitmapSha256'], $actualSha)) {
            oop_printer_set_job_status($job, 'failed', $now, 'Raster upload digest did not match its metadata.');
            oop_printer_raster_remove($id);
            unset($job['body'], $job['nextOffset']);
            $failed = oop_printer_public_job($job);
            unset($job);
            return ['failure' => $failed];
        }
        $job['body']['rasterStream'] = ['width' => (int)$metadata['width'], 'height' => (int)$metadata['height'], 'bitmapBytes' => $expectedBytes, 'bitmapSha256' => $actualSha];
        $job['rasterStream'] = $job['body']['rasterStream'];
        unset($job['nextOffset']);
        $job['expires'] = $now + 86400;
        oop_printer_set_job_status($job, 'queued', $now);
        $public = oop_printer_public_job($job);
        unset($job);
        return ['job' => $public];
    }
    unset($job);
    throw new OopPrinterRelayException('Unknown raster upload.', 404);
}

function oop_printer_abort_raster_stream(array &$state, string $id, int $now): array
{
    foreach ($state['jobs'] as &$job) {
        if (($job['id'] ?? '') !== $id) continue;
        if (($job['status'] ?? '') !== 'uploading') throw new OopPrinterRelayException('Only an open raster upload can be aborted.', 409);
        oop_printer_set_job_status($job, 'cancelled', $now);
        unset($job['body'], $job['nextOffset']);
        oop_printer_raster_remove($id);
        $public = oop_printer_public_job($job);
        unset($job);
        return $public;
    }
    unset($job);
    throw new OopPrinterRelayException('Unknown raster upload.', 404);
}
