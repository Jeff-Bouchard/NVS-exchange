# Shared Faucet and Exchange portal

Use this branch with Faucet branch `codex/unified-faucet-countdown` in `Jeff-Bouchard/Faucet`.

The unified interface is served by Faucet. This repository supplies `web/api.php` for existing NVS registration and NCH exchange slot creation, status, and payment processing. Both services must be served under one HTTPS origin. Keep NVS-exchange at the public root and proxy Faucet under `/faucet/`; set `FAUCET_PORTAL_URL=/faucet/` here and `EXCHANGE_API_PATH=/api.php` in Faucet. See Faucet `docs/INTEGRATION.md` and `deploy/nginx.conf.example` for the full setup and operational limits.

The API uses a separate strict HttpOnly session cookie and CSRF header, preserves existing payment wallet adapters, serializes its own mutations, and recovers repeated creates within the session. It uses the current `worm:exchange:ness_exchange_v1_v2` service descriptor, with both historical descriptor element spellings supported. The descriptor encoder emits `<exchange>` consistently and escapes XML attributes. No faucet time token is converted into a WORM token.

Keep `data/` writable for the portal lock. Configure `db.filename` with the existing SQLite path. The sample now includes `../data/exchange.db`, but do not replace an existing database. Set `COOKIE_SECURE=1` behind HTTPS and a finite PHP default_socket_timeout. The legacy record editor and status URLs remain available and retain their previous access model.

Run `php tests/run.php` with PHP sodium/PDO SQLite/cURL/SimpleXML available. Real exchange processing also requires the configured Emercoin and NESS RPC nodes and the resolved external exchange service. A successful unit test is not evidence of a funded or healthy live exchange.
