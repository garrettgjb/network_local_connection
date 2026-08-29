<?php

namespace Local\Energy;

/**
 * Tesla Backup Gateway (TEG) local API.
 *
 * Every data endpoint returns 403 until you exchange the customer password for
 * a bearer token at /api/login/Basic. The token is good for hours, so it is
 * cached on disk between requests rather than re-logging in every poll — the
 * gateway is slow to authenticate and rate-limits repeated logins.
 */
final class Powerwall
{
    public function __construct(
        private readonly string $host,
        private readonly string $password,
        private readonly string $email,
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    public function configured(): bool
    {
        return $this->host !== '' && $this->password !== '';
    }

    private function token(bool $forceRefresh = false): string
    {
        $key = 'powerwall-token';

        if (! $forceRefresh && ($cached = $this->cache->get($key)) !== null) {
            return $cached;
        }

        $payload = json_encode([
            'username' => 'customer',
            'email' => $this->email,
            'password' => $this->password,
            'force_sm_off' => false,
        ], JSON_THROW_ON_ERROR);

        $result = $this->http->json('POST', "https://{$this->host}/api/login/Basic",
            ['Content-Type' => 'application/json'], $payload);

        $token = $result['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new DeviceException('Powerwall login returned no token');
        }

        // Well inside the gateway's own expiry, so a poll never races it.
        $this->cache->put($key, $token, 3600);

        return $token;
    }

    /** @return array<mixed> */
    private function get(string $path): array
    {
        try {
            return $this->http->json('GET', "https://{$this->host}$path",
                ['Authorization' => 'Bearer ' . $this->token()]);
        } catch (DeviceException $e) {
            // A cached token can outlive a gateway restart; retry once with a
            // fresh login before treating it as a real failure.
            if ($e->status === 401 || $e->status === 403) {
                return $this->http->json('GET', "https://{$this->host}$path",
                    ['Authorization' => 'Bearer ' . $this->token(forceRefresh: true)]);
            }
            throw $e;
        }
    }

    /**
     * Battery percentage, power flows and grid state.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $soe = $this->get('/api/system_status/soe');
        $meters = $this->get('/api/meters/aggregates');
        $grid = $this->get('/api/system_status/grid_status');

        // Sign convention, normalised for the dashboard:
        //   battery_watts  > 0 discharging, < 0 charging
        //   grid_watts     > 0 importing,   < 0 exporting
        $battery = (float) ($meters['battery']['instant_power'] ?? 0);
        $site = (float) ($meters['site']['instant_power'] ?? 0);

        return [
            'charge_percent' => round((float) ($soe['percentage'] ?? 0), 1),
            'battery_watts' => round($battery),
            'grid_watts' => round($site),
            'load_watts' => round((float) ($meters['load']['instant_power'] ?? 0)),
            'solar_watts' => round((float) ($meters['solar']['instant_power'] ?? 0)),
            'grid_status' => $grid['grid_status'] ?? null,
            'grid_connected' => ($grid['grid_status'] ?? null) === 'SystemGridConnected',
            'energy_exported_kwh' => round((float) ($meters['site']['energy_exported'] ?? 0) / 1000, 1),
            'energy_imported_kwh' => round((float) ($meters['site']['energy_imported'] ?? 0) / 1000, 1),
        ];
    }
}
