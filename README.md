# network_local_connection

A small PHP agent that reads household energy hardware over the LAN and serves
one combined JSON document to an authenticated caller on the tailnet.

It exists because the devices are local-only. The Tesla Backup Gateway and the
Enphase Envoy both speak private-network APIs, so a public host — in this case
the `/power` dashboard on gbanker.com, which runs on EC2 — has no route to them.
Rather than exposing the devices or bridging the whole LAN into the cloud, this
agent sits on the home network, holds the device credentials, and hands out a
single read-only endpoint over Tailscale.

The public dashboard therefore never holds a device credential. It holds one
shared token, and can reach exactly one endpoint.

## Endpoints

| Route     | Auth   | Purpose                                          |
|-----------|--------|--------------------------------------------------|
| `/health` | none   | Liveness and whether each device is configured   |
| `/energy` | bearer | Battery and solar readings                       |
| `POST /marketplace/fetch` | bearer | One marketplace search, made from this house |

```json
{
  "generated_at": "2026-08-29T15:41:27+00:00",
  "battery": {
    "ok": true, "charge_percent": 62.6, "battery_watts": -1740,
    "grid_watts": 231, "load_watts": 275, "solar_watts": 1764,
    "grid_status": "SystemGridConnected", "grid_connected": true
  },
  "solar": {
    "ok": true, "producing_watts": 3472, "produced_today_kwh": 2.92,
    "inverters_active": 33, "measurement": "ct", "token_expires_in_days": 319
  }
}
```

Sign conventions, normalised across both vendors:

- `battery_watts` — positive discharging, negative charging
- `grid_watts` — positive importing, negative exporting

A failure reading one device does not blank the other: each carries its own
`ok` flag and, when false, an `error` explaining what went wrong.

## Requirements

PHP 8.1+ with cURL. No Composer, no framework, no dependencies — so it drops
onto anything from a NAS VM to a Raspberry Pi unchanged.

## Setup

```bash
cp .env.example .env      # fill in device credentials and generate AGENT_TOKEN
chmod 600 .env
bin/serve                 # foreground, for testing
```

To install as a service:

```bash
sudo cp deploy/local-energy.service /etc/systemd/system/
sudo systemctl enable --now local-energy
```

`BIND_ADDR` should be the machine's **Tailscale** address, not `0.0.0.0`. That
is what keeps the agent unreachable from the LAN and from the internet; only
tailnet peers can connect, and they still need the bearer token.

## Notes on the devices

**Tesla Backup Gateway** — every data endpoint returns 403 until the customer
password is exchanged for a bearer token at `/api/login/Basic`. The token is
cached on disk for an hour because the gateway authenticates slowly and
rate-limits repeated logins. A cached token can outlive a gateway restart, so a
401/403 triggers exactly one re-login before the read is treated as failed.

**Enphase Envoy** — firmware D7 and later has no local password. It validates a
JWT that Enphase's cloud issues against the Envoy's serial number. Homeowner
tokens last about a year, so `/energy` reports `token_expires_in_days`; when
that runs out, reissue from Enlighten and update `ENVOY_TOKEN`.

Both devices present self-signed certificates, so TLS verification is disabled.
That is only acceptable because every request stays on the local network.

## State of charge

`charge_percent` matches what the Tesla app shows. The gateway's local API
reports a raw figure that includes a 5% reserve the app hides — it displays
`(raw - 5) / 0.95` — so a raw 23.5% is 19% in the app. The raw value is
reported as `charge_percent_raw` for anyone who wants it.

## Which readings to trust

The gateway's own figures are each faithful to the slice of the system it is
wired to see, and each is the wrong number for a whole-house view:

- Its **solar** covers about half the array. The gateway's CT config shows one
  solar CT enabled (`valid = [false, true, false, false]`) against two on the
  site meter, and lifetime totals bear it out: 30.1 MWh logged here against the
  Envoy's 58.2 MWh, or 51.7%.
- Its **grid** is the gateway-to-panel tie, not the utility service — the
  gateway hangs off a breaker in the main panel, so everything upstream looks
  like "the grid" from inside it.
- Its **home** is the backed-up sub-panel only.

Consumers should take solar from the Envoy and grid from a meter at the service
entrance. See `docs/power-metering.md` in the gbanker.com repo, which records
the hour-by-hour validation against the SDG&E billing meter (14 hours, both
directions, worst hour ~2%).

The Envoy's **consumption** CTs are misconfigured or absent — `net-consumption`
reads `-0.0` and `total-consumption` exactly equals production — so only its
production figures are meaningful.

## Known data quirk

Both figures are passed through unmodified rather than reconciled here, so a
consumer can see the disagreement and decide. The `solar_watts` the gateway
reports is genuine — it is simply measuring one string.

## Marketplace search relay

`gbanker.com/market` searches OfferUp and Facebook. Both refuse AWS address
ranges — a blanket filter aimed at bulk scrapers — so the dashboard on EC2 gets
a `403` and a login page. With `MARKETPLACE_RELAY=true` it sends those searches
here instead, and they go out over the house connection: a handful of ordinary
anonymous page fetches every fifteen minutes, the same ones a browser here
would make.

```
POST /marketplace/fetch          Authorization: Bearer <AGENT_TOKEN>
{"method": "POST", "url": "https://offerup.com/api/graphql",
 "headers": {"content-type": "application/json"}, "body": "{...}"}
```

The upstream status, content type and body come back untouched, so the caller
sees exactly what the site said. A failure *here* is a `502` carrying
`X-Relay: failed`, so the two are never confused.

It is deliberately not a general proxy:

- only `offerup.com/api/graphql` and `www.facebook.com/marketplace/`, over https
- only the OfferUp operations `GetModularFeed` and `GetListingDetailByListingId`
- only the headers in `PASS` — **no `Authorization`, no `Cookie`**
- at most `MARKETPLACE_PER_MINUTE` requests a minute
- `AGENT_TOKEN` required, over the tailnet, like `/energy`

**Anonymous search only, on purpose.** The signed-in OfferUp calls — inbox,
replies, offers — are not relayed. Those go out as the account, and OfferUp's
anti-automation has already reacted to them once (a "new device" mail, then a
one-time-code prompt). They belong on a machine someone is sitting at, so
`rejectOperation()` refuses them here rather than trusting the caller.
