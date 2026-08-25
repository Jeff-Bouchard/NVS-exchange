# Addon 1.3.0 quality check

Checked 2026-08-25 against upstream NVS-exchange commit `b198dbe`.

- All patches apply in installer order to a pristine upstream archive.
- `install.sh --check` completes without changing the target.
- PHP 8.3 parses all nine staged PHP files with DOM and Sodium enabled.
- Install, repeated-install detection, and rollback complete successfully.
- After rollback, all upstream files are byte-identical to their originals.
- The generated RandPay HMAC key is exactly 32 bytes and remains outside the
  web root after rollback so accepted authorizations are not orphaned.
- Eleven focused tests cover expected-value arithmetic, real RPC names,
  non-naive RandPay input validation, exact-only acceptance, HMAC/quote
  binding, authorization-before-service persistence, QR self-hosting, secret
  placement, and exact NCH fallback.

The first full PHP preflight found an unclosed conditional in the QR UI patch.
Nothing had been pushed or installed; the patch was corrected and the full
pristine preflight was rerun successfully.

Not claimed: no real NCH/EMC was sent, no `name_new` was broadcast, and no live
wallet callback was accepted during packaging. Run the read-only installer
preflight and then a controlled live EMC RandPay test on the actual server.
Native RandPay is EMC-only; exact NCH cohort and one-by-one payments remain.
