<?php

namespace Local\Energy;

/**
 * Sends gbanker.com's marketplace searches out over the house connection.
 *
 * OfferUp and Facebook both refuse AWS address ranges — a blanket filter aimed
 * at bulk scrapers — so the dashboard on EC2 gets a 403 and a login page. The
 * searches themselves are a handful of ordinary anonymous page fetches every
 * fifteen minutes, the same ones a browser here would make, so they go out from
 * here instead and the answer goes back.
 *
 * Anonymous search only. Signed-in OfferUp calls — the inbox, replies, offers —
 * are not relayed: those go out as the account, and OfferUp's anti-automation
 * has already reacted to them once. They stay on a machine someone is sitting
 * at. That is enforced here rather than left to the caller's good behaviour:
 * no credential header is passed on, and only the two anonymous GraphQL
 * operations are accepted.
 *
 * Deliberately not a general proxy:
 *
 *   - only the exact hosts and paths in ALLOWED, by GET or POST
 *   - only the GraphQL operations in OPERATIONS
 *   - only the request headers in PASS — no Authorization, no Cookie
 *   - a per-minute cap, so a bug upstream can't turn this into a scraper
 *   - the caller still needs AGENT_TOKEN, over the tailnet
 *
 * The upstream status, content type and body are returned untouched, so the
 * caller sees exactly what the site said. A failure *here* is a 502 carrying
 * `X-Relay: failed`, so the two are never confused.
 */
final class Marketplace
{
    /** host => path prefixes that may be requested on it. */
    private const ALLOWED = [
        'offerup.com' => ['/api/graphql'],
        'www.facebook.com' => ['/marketplace/'],
    ];

    /** The only OfferUp GraphQL operations relayed — both anonymous. */
    private const OPERATIONS = ['GetModularFeed', 'GetListingDetailByListingId'];

    /**
     * Headers passed upstream, lowercase. They arrive in the request envelope
     * rather than as this request's own headers, so the tailnet bearer token
     * that authenticates the caller never travels on by accident. Authorization
     * and Cookie are absent on purpose: nothing signed in goes through here.
     */
    private const PASS = [
        'accept', 'accept-language', 'content-type',
        'origin', 'referer', 'sec-fetch-dest', 'sec-fetch-mode', 'sec-fetch-site',
        'user-agent', 'upgrade-insecure-requests', 'x-ou-operation-name',
    ];

    private const MAX_BODY = 65536;

    public function __construct(
        private readonly Cache $cache,
        private readonly int $timeout = 25,
        private readonly int $perMinute = 60,
    ) {}

    /**
     * @param  array<string, string>  $headers  headers for the upstream request
     * @return array{status: int, type: string, body: string, relayed: bool}
     */
    public function forward(string $method, string $url, array $headers, string $body): array
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST'], true)) {
            return $this->refuse(405, "Method $method not allowed here");
        }
        if (strlen($body) > self::MAX_BODY) {
            return $this->refuse(413, 'Request body too large');
        }
        if (($why = $this->reject($url)) !== null) {
            return $this->refuse(403, $why);
        }
        if (($why = $this->rejectOperation($url, $body)) !== null) {
            return $this->refuse(403, $why);
        }
        if (! $this->withinRate()) {
            return $this->refuse(429, "More than {$this->perMinute} requests in a minute — refusing, this relay is for a handful of searches");
        }

        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), self::PASS, true)) {
                $out[] = "$name: $value";
            }
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $out,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING => '',  // undo whatever compression the site picks
            CURLOPT_FOLLOWLOCATION => false,
            // A public host, unlike the LAN devices: verify it.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream');
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return $this->refuse(502, "Could not reach the site: $error");
        }

        return ['status' => $status, 'type' => $type, 'body' => (string) $response, 'relayed' => true];
    }

    /** Why this URL may not be requested, or null if it may. */
    private function reject(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return 'Only https is relayed';
        }
        $host = strtolower($parts['host'] ?? '');
        if (! isset(self::ALLOWED[$host])) {
            return "$host is not a site this relay will fetch";
        }
        $path = $parts['path'] ?? '/';
        foreach (self::ALLOWED[$host] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return null;
            }
        }

        return "$path is not a path this relay will fetch on $host";
    }

    /**
     * Anything posted to OfferUp's GraphQL endpoint has to name one of the
     * anonymous search operations. This is what keeps the account's own calls —
     * GetChats, ReplyChat, MakeOffer — off the relay.
     */
    private function rejectOperation(string $url, string $body): ?string
    {
        if (! str_contains(strtolower($url), 'offerup.com')) {
            return null;
        }
        $operation = (string) (json_decode($body, true)['operationName'] ?? '');
        if (! in_array($operation, self::OPERATIONS, true)) {
            return ($operation === '' ? 'An unnamed OfferUp operation' : "OfferUp's {$operation}")
                . ' is not relayed — this relay carries anonymous searches only';
        }

        return null;
    }

    /**
     * A rolling count of the last minute's requests, kept in the same file
     * cache the gateway token uses. The cap is far above normal use — a
     * search is a handful of requests every fifteen minutes — so it only
     * catches something looping.
     */
    private function withinRate(): bool
    {
        $now = time();
        $recent = array_filter(
            array_map('intval', explode(',', (string) $this->cache->get('marketplace-rate'))),
            fn ($at) => $at > $now - 60,
        );
        if (count($recent) >= $this->perMinute) {
            return false;
        }
        $recent[] = $now;
        $this->cache->put('marketplace-rate', implode(',', $recent), 120);

        return true;
    }

    private function refuse(int $status, string $why): array
    {
        return ['status' => $status, 'type' => 'application/json', 'relayed' => false,
            'body' => json_encode(['error' => $why], JSON_UNESCAPED_SLASHES)];
    }
}
