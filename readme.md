# Emercoin NVS exchange

## Buy NVS records for EMC, NESS, NCH

Execute

`mv config/config.sample.php config/config.php`

Edit `config.php`

### Commands

`php exec/self-test.php [-debug]`

System self-test


`php list-addr.php [wallet-filename]`

List addresses for default (main_wallet_id in config.php) or selected ness wallet


`php exec/new-addr.php [wallet-filename] [new addresses count]`

Make new address or addresses
## Shared Faucet portal

See [portal integration](docs/PORTAL.md) for the new JSON API and the matching Faucet interface. Run `php tests/run.php` before deployment.
