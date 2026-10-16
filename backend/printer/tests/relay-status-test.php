<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/relay-lib.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function throws_status(callable $fn, int $status): void
{
    try { $fn(); } catch (OopPrinterRelayException $error) { check($error->status === $status, 'Unexpected HTTP status: ' . $error->status); return; }
    throw new RuntimeException('Expected OopPrinterRelayException.');
}

$now = 1_800_000_000;
$oldDevice = ['lastSeen' => $now - 30, 'version' => 'old-fw', 'busy' => false];
$fresh = oop_printer_connection($oldDevice, $now);
check($fresh === ['deviceLastSeen' => $now - 30, 'deviceAgeMs' => 30000, 'serverTime' => $now, 'online' => true], 'Old device state did not produce fresh connection data.');
$oldDevice['lastSeen'] = $now - 120;
check(oop_printer_connection($oldDevice, $now)['online'] === true, 'A device at the 120-second boundary must remain online.');
$oldDevice['lastSeen'] = $now - 121;
check(oop_printer_connection($oldDevice, $now)['online'] === false, 'A device past the freshness window must be offline.');
$oldStateView = oop_printer_public_printer_state($oldDevice, $now);
check($oldStateView['state'] === 'unknown' && $oldStateView['paperStatus'] === 'unavailable' && $oldStateView['outputConfirmation'] === 'uart-only' && $oldStateView['stale'] === true, 'Old device schema did not receive safe telemetry defaults.');
check($oldStateView['activeJobId'] === null && $oldStateView['progressPercent'] === null, 'Old device schema invented progress telemetry.');

$normalized = oop_printer_normalize_telemetry([
    'state' => 'printing', 'activeJobId' => 'job-1', 'bytesSent' => 120, 'totalBytes' => 600,
    'progressPercent' => 20, 'lastProgressAgeMs' => 50, 'elapsedMs' => 900, 'uptimeMs' => 5000,
], $now);
check($normalized['bytesSent'] === 120 && $normalized['activeJobId'] === 'job-1', 'Valid bounded telemetry was not retained.');
$frontendProgress = oop_printer_normalize_telemetry(['bytesSent' => 120, 'totalBytes' => 600, 'percent' => 20, 'elapsedMs' => 900, 'lastProgressAgeMs' => 40], $now);
check($frontendProgress['progressPercent'] === 20 && $frontendProgress['lastProgressAgeMs'] === 40, 'Frontend/firmware percent progress shape was not accepted.');
$idleTelemetry = oop_printer_normalize_telemetry(['state' => 'idle', 'activeJobId' => null], $now);
check(array_key_exists('activeJobId', $idleTelemetry) && $idleTelemetry['activeJobId'] === null, 'Explicitly cleared active job telemetry was not preserved.');
$firmwareReady = oop_printer_normalize_telemetry([
    'state' => 'ready', 'uptimeMs' => 1234, 'paperStatus' => 'unavailable',
    'outputConfirmation' => 'uart-only', 'activeJobId' => '', 'bytesSent' => 0,
    'totalBytes' => 0, 'progressPercent' => 0, 'lastProgressAgeMs' => 0, 'elapsedMs' => 0,
], $now);
check($firmwareReady['outputConfirmation'] === 'uart-only' && $firmwareReady['activeJobId'] === null, 'Current firmware ready telemetry did not normalize.');
throws_status(fn() => oop_printer_normalize_telemetry(['state' => ['nested' => 'junk']], $now), 400);
throws_status(fn() => oop_printer_normalize_telemetry(['bytesSent' => 1_000_000_001], $now), 400);
throws_status(fn() => oop_printer_normalize_telemetry(['paperStatus' => 'paper-confirmed'], $now), 400);
throws_status(fn() => oop_printer_normalize_telemetry(['outputConfirmation' => 'printed'], $now), 400);
$badProgressState = oop_printer_initial_state();
throws_status(fn() => oop_printer_process_device_request($badProgressState, [
    'action' => 'claim', 'busy' => false,
    'printerState' => ['state' => 'ready', 'uptimeMs' => 1234, 'paperStatus' => 'unavailable', 'outputConfirmation' => 'uart-only', 'activeJobId' => ''],
    'progress' => ['bytesSent' => 1, 'outputConfirmation' => 'uart-only'],
], $now), 400);
check(oop_printer_cancel_request_id(['id' => 'job-1']) === 'job-1', 'Valid cancel request was rejected.');
throws_status(fn() => oop_printer_cancel_request_id([]), 400);
throws_status(fn() => oop_printer_cancel_request_id(['id' => '../state']), 400);
throws_status(fn() => oop_printer_cancel_request_id(['id' => 'job-1', 'force' => true]), 400);

$job = ['id' => 'job-1', 'kind' => 'text', 'status' => 'queued', 'created' => $now, 'updated' => $now, 'expires' => $now + 100, 'digest' => 'x', 'body' => ['id' => 'job-1', 'text' => "Receipt heading\nsecond line"], 'error' => '', 'events' => [], 'progress' => null];
$job['label'] = oop_printer_job_label($job);
oop_printer_job_event($job, 'queued', $now);
$state = ['version' => 1, 'device' => $oldDevice, 'jobs' => [$job]];
$cancelled = oop_printer_cancel_job($state, 'job-1', $now + 1);
check($cancelled['status'] === 'cancelled' && $cancelled['label'] === 'Receipt heading' && $cancelled['expires'] === $now + 100, 'Cancellation response lost public job details.');
check($cancelled['timeline'][1]['status'] === 'cancelled', 'Cancellation was not recorded in the timeline.');
check(oop_printer_cancel_job($state, 'job-1', $now + 2)['status'] === 'cancelled', 'Repeated cancellation must be idempotent.');
check(oop_printer_claim_next($state, $now + 3, false) === null, 'A cancelled job was claimed.');

$claimFirst = ['version' => 1, 'device' => [], 'jobs' => [$job]];
check(oop_printer_claim_next($claimFirst, $now + 1, false)['id'] === 'job-1', 'Queued job was not claimable.');
throws_status(fn() => oop_printer_cancel_job($claimFirst, 'job-1', $now + 2), 409);
check($claimFirst['jobs'][0]['status'] === 'delivering', 'Failed cancellation changed a claimed job.');

$firmwareRelayState = ['version' => 1, 'device' => ['lastSeen' => 0, 'version' => '', 'busy' => false, 'printerState' => null], 'jobs' => [$job]];
$claimPayload = [
    'action' => 'claim', 'version' => '1.7.4', 'busy' => false,
    'printerState' => ['state' => 'ready', 'uptimeMs' => 1234, 'paperStatus' => 'unavailable', 'outputConfirmation' => 'uart-only', 'activeJobId' => '', 'bytesSent' => 0, 'totalBytes' => 0, 'progressPercent' => 0, 'lastProgressAgeMs' => 0, 'elapsedMs' => 0],
];
$claimed = oop_printer_process_device_request($firmwareRelayState, $claimPayload, $now + 1);
check(($claimed['job']['id'] ?? null) === 'job-1' && $firmwareRelayState['jobs'][0]['status'] === 'delivering', 'Firmware-shaped idle claim did not claim a queued job.');
check($firmwareRelayState['device']['lastSeen'] === $now + 1 && $firmwareRelayState['device']['printerState']['activeJobId'] === null, 'Firmware-shaped claim did not refresh contact or normalize idle ID.');
$terminalPayload = [
    'action' => 'report', 'version' => '1.7.4', 'busy' => false, 'id' => 'job-1', 'status' => 'sent', 'error' => '',
    'printerState' => ['state' => 'cooling', 'uptimeMs' => 12345, 'paperStatus' => 'unavailable', 'outputConfirmation' => 'uart-only', 'activeJobId' => '', 'bytesSent' => 0, 'totalBytes' => 0, 'progressPercent' => 0, 'lastProgressAgeMs' => 0, 'elapsedMs' => 10000],
    'progress' => ['bytesSent' => 600, 'totalBytes' => 600, 'percent' => 100, 'elapsedMs' => 10000, 'lastProgressAgeMs' => 0],
];
$reportedTerminal = oop_printer_process_device_request($firmwareRelayState, $terminalPayload, $now + 2);
$finished = oop_printer_public_job($firmwareRelayState['jobs'][0]);
check(($reportedTerminal['ok'] ?? false) === true && $finished['status'] === 'sent', 'Firmware-shaped terminal report was rejected.');
check($finished['progress']['percent'] === 100 && $firmwareRelayState['device']['printerState']['activeJobId'] === null, 'Terminal report lost progress or retained a stale active ID.');

$reported = $claimFirst['jobs'][0];
$reported['progress'] = ['bytesSent' => 120, 'totalBytes' => 600, 'progressPercent' => 20, 'lastProgressAgeMs' => 40, 'elapsedMs' => 900, 'updated' => $now + 2];
$public = oop_printer_public_job($reported);
check($public['progress']['percent'] === 20 && $public['progress']['progressPercent'] === 20 && $public['updated'] === $now + 1, 'Public job omitted current progress, percent alias, or update time.');
$legacy = $reported;
$legacy['progress'] = ['bytesSent' => 12, 'totalBytes' => 60, 'progressPercent' => 20, 'updated' => $now + 2];
check(oop_printer_public_job($legacy)['progress']['percent'] === 20, 'Legacy per-job progressPercent was not exposed as percent.');
check(oop_printer_device_report_allowed('uncertain', 'sent') && oop_printer_device_report_allowed('uncertain', 'failed'), 'Late terminal outcome did not resolve an uncertain delivery.');
check(!oop_printer_device_report_allowed('uncertain', 'processing') && !oop_printer_device_report_allowed('sent', 'failed'), 'Invalid late status transition was allowed.');

$temporaryDirectory = sys_get_temp_dir() . '/printer-relay-status-' . bin2hex(random_bytes(6));
mkdir($temporaryDirectory, 0700);
putenv('OOP_PRINTER_RELAY_FILE=' . $temporaryDirectory . '/state.json');
oop_printer_with_state(function (array &$stored) use ($job): void { $stored['jobs'][] = $job; });
$storedCancel = oop_printer_with_state(fn(array &$stored): array => oop_printer_cancel_job($stored, 'job-1', $now + 1));
$storedClaim = oop_printer_with_state(fn(array &$stored): ?array => oop_printer_claim_next($stored, $now + 2, false));
check($storedCancel['status'] === 'cancelled' && $storedClaim === null, 'Persisted cancellation did not prevent a later claim.');
unlink($temporaryDirectory . '/state.json');
rmdir($temporaryDirectory);
putenv('OOP_PRINTER_RELAY_FILE');

fwrite(STDOUT, "printer relay status checks passed\n");
