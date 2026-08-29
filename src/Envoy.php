<?php

namespace Local\Energy;

/**
 * Enphase Envoy local API, firmware D7 and newer.
 *
 * Unlike older Envoys there is no local password: the gateway validates a JWT
 * that Enphase's cloud issues against the Envoy's serial. Homeowner tokens last
 * about a year, so this class does not try to mint one — it reports how long
 * the configured token has left, and the dashboard surfaces that before it can
 * expire quietly and take solar readings down with it.
 */
final class Envoy
{
    public function __construct(
        private readonly string $host,
        private readonly string $token,
        private readonly Http $http,
    ) {}

    public function configured(): bool
    {
        return $this->host !== '' && $this->token !== '';
    }

    /** Seconds until the JWT expires, or null if it cannot be parsed. */
    public function tokenExpiresIn(): ?int
    {
        $parts = explode('.', $this->token);
        if (count($parts) < 2) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), false);
        $claims = json_decode((string) $payload, true);

        return isset($claims['exp']) ? (int) $claims['exp'] - time() : null;
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        try {
            $data = $this->http->json('GET', "https://{$this->host}/production.json",
                ['Authorization' => 'Bearer ' . $this->token]);
        } catch (DeviceException $e) {
            if ($e->status === 401) {
                throw new DeviceException(
                    'Envoy rejected the token. Enphase homeowner tokens expire yearly — '
                    . 'reissue one from Enlighten and update ENVOY_TOKEN.',
                    401,
                );
            }
            throw $e;
        }

        // Two production sources are reported. "eim" is the CT clamp and is the
        // accurate one; "inverters" is the sum the panels self-report and runs
        // slightly low. Prefer eim, fall back to inverters if no CT is fitted.
        $eim = $this->pick($data['production'] ?? [], 'eim');
        $inverters = $this->pick($data['production'] ?? [], 'inverters');
        $production = $eim ?? $inverters;

        $consumption = $this->pick($data['consumption'] ?? [], 'eim');

        return [
            'producing_watts' => round((float) ($production['wNow'] ?? 0)),
            'produced_today_kwh' => round((float) ($production['whToday'] ?? 0) / 1000, 2),
            'produced_lifetime_kwh' => round((float) ($production['whLifetime'] ?? 0) / 1000, 1),
            'inverters_active' => $inverters['activeCount'] ?? null,
            'consuming_watts' => $consumption ? round((float) ($consumption['wNow'] ?? 0)) : null,
            'measurement' => $eim ? 'ct' : 'inverters',
            'token_expires_in_days' => ($s = $this->tokenExpiresIn()) !== null ? intdiv($s, 86400) : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $readings
     * @return array<string, mixed>|null
     */
    private function pick(array $readings, string $type): ?array
    {
        foreach ($readings as $reading) {
            if (($reading['type'] ?? null) === $type) {
                return $reading;
            }
        }

        return null;
    }
}
