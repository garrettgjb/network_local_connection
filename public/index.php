<?php

/**
 * Local energy agent.
 *
 * Reads the Tesla Backup Gateway and Enphase Envoy over the LAN and serves one
 * combined JSON document. Runs on any machine that can see both devices — today
 * a VM, tomorrow a Pi — so device credentials stay on the home network and the
 * public dashboard only ever holds the shared token below.
 *
 * Routes:
 *   GET  /health             liveness plus per-device reachability, no auth
 *   GET  /energy             combined battery + solar reading, bearer auth
 *   POST /marketplace/fetch  one marketplace search, made from this house on
 *                            gbanker.com's behalf (see src/Marketplace.php),
 *                            bearer auth
 */

declare(strict_types=1);

namespace Local\Energy;

foreach (['DeviceException', 'Http', 'Cache', 'Config', 'Powerwall', 'Envoy', 'Marketplace'] as $class) {
    require __DIR__ . "/../src/$class.php";
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

$config = new Config(__DIR__ . '/../.env');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$http = new Http((int) ($config->get('TIMEOUT', '15')));
$cache = new Cache($config->get('CACHE_DIR', sys_get_temp_dir() . '/local-energy'));

$powerwall = new Powerwall(
    $config->get('POWERWALL_HOST'),
    $config->get('POWERWALL_PASSWORD'),
    $config->get('POWERWALL_EMAIL'),
    $http,
    $cache,
);

$envoy = new Envoy($config->get('ENVOY_HOST'), $config->get('ENVOY_TOKEN'), $http);

function send(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

if ($path === '/health') {
    send(200, ['ok' => true, 'powerwall_configured' => $powerwall->configured(),
        'envoy_configured' => $envoy->configured(), 'time' => gmdate('c')]);
}

$marketplace = $path === '/marketplace/fetch' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($path !== '/energy' && ! $marketplace) {
    send(404, ['error' => 'Not found']);
}

// Constant-time compare so the token cannot be recovered by timing the endpoint.
$expected = $config->get('AGENT_TOKEN');
$offered = '';
if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
    $offered = trim($m[1]);
}

if ($expected === '' || ! hash_equals($expected, $offered)) {
    send(401, ['error' => 'Unauthorized']);
}

if ($marketplace) {
    if ($config->get('MARKETPLACE_RELAY') !== 'true') {
        send(404, ['error' => 'Marketplace relay is off (MARKETPLACE_RELAY)']);
    }

    $envelope = json_decode((string) file_get_contents('php://input'), true);
    if (! is_array($envelope) || ! isset($envelope['url'])) {
        send(400, ['error' => 'Expected {method, url, headers, body}']);
    }

    $r = (new Marketplace($cache, (int) $config->get('MARKETPLACE_TIMEOUT', '25'), (int) $config->get('MARKETPLACE_PER_MINUTE', '60')))
        ->forward(
            (string) ($envelope['method'] ?? 'GET'),
            (string) $envelope['url'],
            (array) ($envelope['headers'] ?? []),
            (string) ($envelope['body'] ?? ''),
        );

    http_response_code($r['status']);
    header('Content-Type: ' . $r['type']);
    // So the caller can tell "the site said this" from "the relay said this".
    header('X-Relay: ' . ($r['relayed'] ? 'ok' : 'failed'));
    echo $r['body'];
    exit;
}

/**
 * One device failing must not blank the other, so each is captured
 * independently and its error reported in place of its readings.
 */
$read = function (callable $fn): array {
    try {
        return ['ok' => true] + $fn();
    } catch (DeviceException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Unexpected failure reading device'];
    }
};

$battery = $powerwall->configured() ? $read(fn () => $powerwall->read()) : ['ok' => false, 'error' => 'Not configured'];
$solar = $envoy->configured() ? $read(fn () => $envoy->read()) : ['ok' => false, 'error' => 'Not configured'];

send(200, [
    'generated_at' => gmdate('c'),
    'battery' => $battery,
    'solar' => $solar,
]);
