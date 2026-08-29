<?php

namespace Local\Energy;

/**
 * Minimal curl wrapper. Deliberately dependency-free: this agent is meant to
 * drop onto any box with PHP and no composer install — a Pi, a NAS, a VM.
 *
 * Both devices present self-signed certificates on the LAN, so verification is
 * off. That is acceptable only because every request stays on the local
 * network; nothing here should ever be pointed at a public host.
 */
final class Http
{
    public function __construct(private readonly int $timeout = 15) {}

    /**
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);

        $out = [];
        foreach ($headers as $name => $value) {
            $out[] = "$name: $value";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $out,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            // LAN devices, self-signed certs — see the class docblock.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new DeviceException("Request to $url failed: $error");
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /** @return array<mixed> */
    public function json(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $r = $this->request($method, $url, $headers, $body);

        if ($r['status'] >= 400) {
            throw new DeviceException("HTTP {$r['status']} from $url", $r['status']);
        }

        $decoded = json_decode($r['body'], true);
        if (! is_array($decoded)) {
            throw new DeviceException("Malformed JSON from $url");
        }

        return $decoded;
    }
}
