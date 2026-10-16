<?php
declare(strict_types=1);

$temporaryDirectory = sys_get_temp_dir() . '/printer-raster-stream-' . bin2hex(random_bytes(6));
mkdir($temporaryDirectory, 0700, true);
putenv('OOP_PRINTER_RELAY_FILE=' . $temporaryDirectory . '/relay.json');
putenv('OOP_PRINTER_RASTER_DIR=' . $temporaryDirectory . '/printer-raster');
require_once dirname(__DIR__) . '/app/relay-lib.php';

function check_stream(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function throws_stream(callable $callback, int $status, string $message): void
{
    try {
        $callback();
    } catch (OopPrinterRelayException $error) {
        check_stream($error->status === $status, $message . ' (wrong status)');
        return;
    }
    throw new RuntimeException($message . ' (no exception)');
}

function stream_metadata(string $id, string $bitmap, ?string $sha = null, int $width = 128): array
{
    return [
        'id' => $id,
        'kind' => 'raster',
        'source' => 'remote',
        'width' => $width,
        'height' => intdiv(strlen($bitmap), intdiv($width, 8)),
        'bitmapBytes' => strlen($bitmap),
        'bitmapSha256' => $sha ?? hash('sha256', $bitmap),
        'timestamp' => false,
    ];
}

function stream_chunk(array &$state, string $id, string $bitmap, int $offset, int $length, int $now): array
{
    return oop_printer_append_raster_chunk($state, [
        'id' => $id,
        'offset' => $offset,
        'data' => base64_encode(substr($bitmap, $offset, $length)),
    ], $now);
}

$now = time();
$state = oop_printer_initial_state();
$capabilities = ['rasterStream' => true, 'maxRasterHeight' => 8192, 'maxRenderedTextHeight' => 8192, 'rasterStreamChunkBytes' => 3072];
$bitmap = '';
for ($index = 0; $index < 393216; $index++) $bitmap .= chr(($index * 37 + 19) & 0xff);
$metadata = stream_metadata('weather-8192', $bitmap, null, 384);

throws_stream(fn() => oop_printer_start_raster_stream($state, $metadata, $now), 409, 'Stream upload was accepted before a device advertised support.');
$state['device']['lastSeen'] = $now;
$state['device']['version'] = '1.7.5';
$state['device']['capabilities'] = $capabilities;
$state['device']['capabilitiesVersion'] = '1.7.5';

$started = oop_printer_start_raster_stream($state, $metadata, $now);
check_stream($started === ['id' => 'weather-8192', 'status' => 'uploading', 'nextOffset' => 0, 'chunkBytes' => 3072], 'Stream did not start at offset zero.');
$path = oop_printer_raster_path('weather-8192');
check_stream(is_file($path) && (fileperms($path) & 0777) === 0600, 'Private raster file was not created with mode 0600.');
check_stream(oop_printer_start_raster_stream($state, $metadata, $now + 1)['nextOffset'] === 0 && count($state['jobs']) === 1, 'A repeated matching start created a duplicate upload.');
$changed = $metadata;
$changed['timestamp'] = true;
throws_stream(fn() => oop_printer_start_raster_stream($state, $changed, $now + 1), 409, 'Reused request ID with different metadata was accepted.');
throws_stream(fn() => oop_printer_append_raster_chunk($state, ['id' => 'weather-8192', 'offset' => 0, 'data' => base64_encode(str_repeat('x', 3073))], $now + 1), 400, 'Oversized chunk was accepted.');
throws_stream(fn() => stream_chunk($state, 'weather-8192', $bitmap, 1, 100, $now + 1), 409, 'Out-of-order chunk was accepted.');
throws_stream(fn() => oop_printer_commit_raster_stream($state, 'weather-8192', $now + 1), 409, 'Truncated stream was committed.');

for ($offset = 0; $offset < strlen($bitmap); $offset += 3072) {
    $length = min(3072, strlen($bitmap) - $offset);
    $chunk = stream_chunk($state, 'weather-8192', $bitmap, $offset, $length, $now + 2);
    check_stream($chunk['nextOffset'] === $offset + $length, 'Chunk offsets did not advance exactly.');
}
$committed = oop_printer_commit_raster_stream($state, 'weather-8192', $now + 5);
check_stream(($committed['job']['status'] ?? '') === 'queued', 'Complete verified stream was not queued.');
$acceptedRepeat = oop_printer_commit_raster_stream($state, 'weather-8192', $now + 5);
check_stream(($acceptedRepeat['accepted'] ?? false) && ($acceptedRepeat['job']['status'] ?? '') === 'queued', 'Repeated commit did not return the existing accepted job.');
$stored = $state['jobs'][0];
check_stream(($stored['body']['rasterStream']['height'] ?? 0) === 8192 && ($stored['body']['rasterStream']['bitmapBytes'] ?? 0) === 393216 && ($stored['body']['rasterStream']['bitmapSha256'] ?? '') === hash('sha256', $bitmap), 'Committed descriptor did not preserve maximum height, size, and digest.');
check_stream(!array_key_exists('bitmap', $stored['body']), 'Full bitmap was copied into relay state.');
$legacyClaim = oop_printer_process_device_request($state, ['action' => 'claim', 'version' => '1.7.4', 'busy' => false], $now + 6);
check_stream(($legacyClaim['job'] ?? null) === null && ($state['jobs'][0]['status'] ?? '') === 'queued', 'Device without stream capability claimed a raster stream.');
$inconsistentCapabilities = $capabilities;
$inconsistentCapabilities['rasterStreamChunkBytes'] = 0;
throws_stream(fn() => oop_printer_normalize_device_capabilities($inconsistentCapabilities), 400, 'Inconsistent device stream capability was accepted.');
$claimed = oop_printer_process_device_request($state, ['action' => 'claim', 'version' => '1.7.5', 'busy' => false, 'capabilities' => $capabilities], $now + 7);
check_stream(($claimed['job']['id'] ?? '') === 'weather-8192' && ($state['jobs'][0]['status'] ?? '') === 'delivering', 'Device did not claim the streamed job.');
$capsWhileOnline = oop_printer_public_stream_capabilities($state['device']);
$offlineDevice = $state['device'];
$offlineDevice['lastSeen'] = $now - 600;
$capsWhileOffline = oop_printer_public_stream_capabilities($offlineDevice);
check_stream($capsWhileOnline['rasterStream'] && $capsWhileOnline['maxRasterHeight'] === 8192, 'Current device advertisement was not exposed while online.');
check_stream($capsWhileOffline['rasterStream'] && $capsWhileOffline['maxRasterHeight'] === 8192, 'Last verified same-version capability was not retained while offline.');
$changedFirmwareState = $state['device'];
$changedFirmwareState['version'] = '1.7.4';
check_stream(!oop_printer_public_stream_capabilities($changedFirmwareState)['rasterStream'], 'Capabilities leaked across a firmware version change.');
$reconstructed = '';
$offset = 0;
while ($offset < strlen($bitmap)) {
    $read = oop_printer_process_device_request($state, ['action' => 'raster_chunk', 'id' => 'weather-8192', 'offset' => $offset, 'maxBytes' => 3072], $now + 7);
    check_stream($read['offset'] === $offset && $read['nextOffset'] > $offset && strlen($read['data']) <= 4096, 'Device chunk response exceeded bounds.');
    check_stream(($state['device']['busy'] ?? false) === true, 'Device appeared idle while receiving a receipt.');
    $piece = base64_decode($read['data'], true);
    check_stream(is_string($piece), 'Device chunk was not base64.');
    $reconstructed .= $piece;
    $offset = $read['nextOffset'];
}
check_stream(hash_equals(hash('sha256', $bitmap), hash('sha256', $reconstructed)), 'Device chunk reads did not reconstruct the original 384-by-8,192 raster.');
throws_stream(fn() => oop_printer_process_device_request($state, ['action' => 'raster_chunk', 'id' => 'weather-8192', 'offset' => strlen($bitmap), 'maxBytes' => 3072], $now + 8), 416, 'Read past end of raster was accepted.');
oop_printer_process_device_request($state, ['action' => 'report', 'version' => '1.7.5', 'busy' => false, 'id' => 'weather-8192', 'status' => 'sent'], $now + 9);
check_stream(($state['jobs'][0]['status'] ?? '') === 'sent' && !is_file($path), 'Terminal report did not mark sent and remove raster bytes.');
throws_stream(fn() => oop_printer_process_device_request($state, ['action' => 'raster_chunk', 'id' => 'weather-8192', 'offset' => 0, 'maxBytes' => 3072], $now + 10), 409, 'Terminal job still exposed raster bytes.');
check_stream((oop_printer_commit_raster_stream($state, 'weather-8192', $now + 10)['job']['status'] ?? '') === 'sent', 'Repeated commit after terminal delivery did not return the recorded outcome.');
check_stream(oop_printer_start_raster_stream($state, $metadata, $now + 11)['status'] === 'sent' && count($state['jobs']) === 1, 'Duplicate commit start replayed a terminal print.');

$puzzleBitmap = str_repeat("\xaa", 48);
$puzzleMetadata = stream_metadata('puzzle-1196', $puzzleBitmap, null, 384);
$puzzleMetadata['kind'] = 'text';
$puzzleMetadata['text'] = 'Puzzle #1196';
$puzzleMetadata['textFont'] = 'default';
$puzzleMetadata['textSize'] = 'default';
$puzzleMetadata['stamp'] = '10/15/2026 12:00 PM';
oop_printer_start_raster_stream($state, $puzzleMetadata, $now + 12);
stream_chunk($state, 'puzzle-1196', $puzzleBitmap, 0, 48, $now + 13);
oop_printer_commit_raster_stream($state, 'puzzle-1196', $now + 14);
$puzzleClaim = oop_printer_process_device_request($state, ['action' => 'claim', 'version' => '1.7.5', 'busy' => false, 'capabilities' => $capabilities], $now + 15);
check_stream(($puzzleClaim['job']['text'] ?? '') === 'Puzzle #1196' && ($puzzleClaim['job']['rasterStream']['bitmapBytes'] ?? 0) === 48 && ($puzzleClaim['job']['stamp'] ?? '') === $puzzleMetadata['stamp'], 'Text stream metadata was not preserved in the device claim.');
oop_printer_process_device_request($state, ['action' => 'report', 'version' => '1.7.5', 'busy' => false, 'id' => 'puzzle-1196', 'status' => 'sent'], $now + 16);

$badBitmap = str_repeat("\x5a", 4096);
$bad = stream_metadata('bad-digest', $badBitmap, str_repeat('0', 64));
oop_printer_start_raster_stream($state, $bad, $now + 20);
stream_chunk($state, 'bad-digest', $badBitmap, 0, 3072, $now + 21);
stream_chunk($state, 'bad-digest', $badBitmap, 3072, 1024, $now + 22);
$badCommit = oop_printer_commit_raster_stream($state, 'bad-digest', $now + 23);
check_stream(($badCommit['failure']['status'] ?? '') === 'failed' && !is_file(oop_printer_raster_path('bad-digest')), 'Digest mismatch was not terminally rejected and cleaned.');

$expiredBitmap = str_repeat("\xa5", 512);
$expiredMetadata = stream_metadata('expires', $expiredBitmap);
oop_printer_start_raster_stream($state, $expiredMetadata, $now + 30);
$expiredPath = oop_printer_raster_path('expires');
stream_chunk($state, 'expires', $expiredBitmap, 0, 256, $now + 35);
oop_printer_clean_state($state, $now + 631);
$expired = array_values(array_filter($state['jobs'], fn(array $job): bool => ($job['id'] ?? '') === 'expires'))[0] ?? [];
check_stream(($expired['status'] ?? '') === 'expired' && !is_file($expiredPath), 'Ten-minute incomplete upload was not expired and removed.');

$staleBitmap = str_repeat("\x81", 512);
$staleMetadata = stream_metadata('stale-delivery', $staleBitmap);
oop_printer_start_raster_stream($state, $staleMetadata, $now + 32);
stream_chunk($state, 'stale-delivery', $staleBitmap, 0, 512, $now + 33);
oop_printer_commit_raster_stream($state, 'stale-delivery', $now + 34);
oop_printer_process_device_request($state, ['action' => 'claim', 'version' => '1.7.5', 'busy' => false, 'capabilities' => $capabilities], $now + 35);
$stalePath = oop_printer_raster_path('stale-delivery');
oop_printer_clean_state($state, $now + 156);
$stale = array_values(array_filter($state['jobs'], fn(array $job): bool => ($job['id'] ?? '') === 'stale-delivery'))[0] ?? [];
check_stream(($stale['status'] ?? '') === 'uncertain' && !is_file($stalePath), 'Unconfirmed delivery did not become uncertain and remove its raster bytes.');

$abortBitmap = str_repeat("\x01", 512);
oop_printer_start_raster_stream($state, stream_metadata('abort-me', $abortBitmap), $now + 40);
$abortPath = oop_printer_raster_path('abort-me');
$aborted = oop_printer_abort_raster_stream($state, 'abort-me', $now + 41);
check_stream(($aborted['status'] ?? '') === 'cancelled' && !is_file($abortPath), 'Abort did not cancel and clean an open upload.');

$persisted = oop_printer_with_state(function (array &$storedState) use ($capabilities): array {
    $storedState['device']['lastSeen'] = time();
    $storedState['device']['version'] = '1.7.5';
    $storedState['device']['capabilities'] = $capabilities;
    $storedState['device']['capabilitiesVersion'] = '1.7.5';
    return oop_printer_start_raster_stream($storedState, stream_metadata('persisted', str_repeat("\x11", 64)), time());
});
check_stream(($persisted['status'] ?? '') === 'uploading', 'Stream metadata did not persist through the locked state store.');

fwrite(STDOUT, "printer relay raster stream checks passed\n");
