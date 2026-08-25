# NVS Exchange multi-name batch addon

This server addon is required by the Pocket Node batch button. It upgrades the
current `NESS-Network/NVS-exchange` code so one NCH payment slot can authorize
many independent EmerNVS names using Emercoin's real singular `name_new` RPC.

It does **not** collapse agents into one aggregate NVS value. Every
`worm:<random-id>` remains independently retrievable with `name_show`.

The efficiency boundary is the payer's NESS transaction: 250 records share one
NCH payment address and require one payer spend. The exchange subsequently
executes up to 250 ordinary Emercoin `name_new` calls from its own Emercoin
wallet. This does not pretend Emercoin exposes a multi-name RPC.

Version 1.3 also offers **native EMC RandPay** for expected-value settlement.
The server uses the actual Emercoin Core `randpay_mkchap`,
`decoderawtransaction`, and `randpay_accept` RPCs. It rejects naive tickets,
requires the special RandPay input, exact winning amount, exact stored CHAP
amount/risk, and `exact_only` acceptance. A valid win or a valid loss authorizes
the complete slot. This is an EMC option; exact NCH cohort payment remains the
Android-safe default because NESS does not currently implement native RandPay.

## Safe installation on the nvs.ness.cx server

Run this as the Unix user that owns the deployed NVS-exchange files. First run
the read-only preflight against the actual checkout:

```bash
chmod +x /path/to/nvs-exchange-batch-addon/install.sh
/path/to/nvs-exchange-batch-addon/install.sh --check /path/to/NVS-exchange
```

When run from the NVS-exchange checkout—or when exactly one compatible checkout
exists under `/var/www`, `/opt`, or `/srv`—the path may be omitted:

```bash
/path/to/nvs-exchange-batch-addon/install.sh --check
/path/to/nvs-exchange-batch-addon/install.sh --install
```

If discovery finds zero or multiple compatible checkouts, it stops without
changing anything and asks for the explicit path.

Only when that reports `Preflight passed`, install:

```bash
/path/to/nvs-exchange-batch-addon/install.sh --install /path/to/NVS-exchange
```

The installer does all patching in a private temporary staging tree first. It
requires PHP 7.1+, DOM, Sodium, and `patch`; lints every affected PHP file;
smoke-tests the staged endpoint; then saves timestamped originals before using
same-filesystem atomic renames. It never changes configuration, wallet files,
the slot database, Apache, or PHP-FPM, and it does not restart a service.

If a deployed file differs from the supported upstream version, preflight
stops before making a server-side change instead of forcing a fuzzy patch.

The successful installation prints its exact backup directory and rollback
command. The generic form is:

```bash
/path/to/nvs-exchange-batch-addon/install.sh --rollback /path/to/NVS-exchange
```

Any failure after the first production file is replaced triggers the same
rollback automatically.

Verify before using real NCH:

```bash
NVS_URL=https://nvs.ness.cx
curl -i "$NVS_URL/batch.php"
```

Expected response: HTTP 405 with `POST an XML nameBatch`.

Open the RandPay option for an existing unpaid slot:

```text
https://nvs.ness.cx/randpay.php?slot=0123456789abcdef0123456789abcdef
```

The page shows the expected EMC value, risk, possible winning settlement,
native `emercoin://randpay` link, exact URI text, and a locally generated QR.
The default risk is 50. Set `NVS_RANDPAY_MAX_EMC` to cap the largest winning
settlement and `NVS_PUBLIC_URL` when the public HTTPS origin differs from
`https://nvs.ness.cx`.

The installer creates a random 32-byte HMAC key and a private authorization
receipt directory under `.nvs-batch-addon`. They never enter a WORM object or
the web tree. The PHP worker must be able to read the key and write the receipt
directory. If the installer and PHP worker use different Unix identities, set
an explicit private `NVS_RANDPAY_KEY_FILE`/`NVS_RANDPAY_STATE_DIR` with the
required ACL; the endpoint returns HTTP 503 rather than weakening permissions.

## Payment UI

- Every generated EMC, NESS, and NCH payment address gets a locally generated,
  scannable QR code beside the exact text address and Copy button.
- QR generation is self-hosted in `web/js/ness-qrcode.js`; payment pages no
  longer depend on the external jQuery QR plugin.
- The NVS form opens with editable, valid-format example values instead of
  disappearing placeholder examples.
- Submitted V1/V2 addresses remain in the form after a validation error.
- Form values are HTML-escaped before being returned to the page.
- The addon also repairs the unmatched PHP `if/else` blocks currently present
  in the upstream V1/V2 form pages; these repairs are linted before commit.

## EmerDNS mobile magic links

For a valid `dns:` record in the `.coin`, `.emc`, `.lib`, or `.bazar` zones,
the slot page displays a normal HTTPS magic link in this form:

```text
https://sd.ness.cx/example.coin/
```

The exact URL is shown as text, exposed through an Open link and Copy button,
and encoded into a QR for phone cameras and Brave. The QR contains no Android
intent, search redirect, authentication token, NVS value, or wallet material.
The `sd.ness.cx` bridge must resolve the corresponding EmerDNS record and apply
Emercoin's native parent `SD=` rules; the bridge does not become naming
authority. Every generated EmerDNS URL ends in `/` so a mobile browser treats
it as a URL rather than a search phrase.

## Economics

- The quoted NCH amount remains proportional to record count and rental days.
- Batch processing compares received NCH to that full quoted amount; a single-
  record minimum cannot accidentally release a 250-record batch.
- The payer makes one NESS transaction to the exchange, so the NCH burn is
  applied once for the whole payment instead of once per agent.
- Native EMC RandPay can reduce settlement further across repeated cohorts:
  expected value is `winning settlement / risk`, and only winners are
  broadcast. Both valid outcomes receive the purchased service.
- The exchange performs singular `name_new` operations internally after one
  batch payment. Completed records are detected by exact `name_show` value
  comparison, making a retry safe after a partial server interruption.
- The endpoint validates every Ed25519 `objectAnchor` signature before creating
  a payment slot. The batch contains public commitments only—never mnemonics,
  secret keys, labels, capabilities, or decrypted WORM transitions.
