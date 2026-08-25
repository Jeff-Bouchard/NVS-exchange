import math
import pathlib
import re
import unittest


ADDON = pathlib.Path(__file__).resolve().parents[1]


class RandPayEconomicsTests(unittest.TestCase):
    def test_expected_value_is_preserved(self):
        agents = 250
        expected_price = 10_000
        for risk in (1, 20, 50, 250, 1000):
            winning_settlement = expected_price * risk
            expected_total = agents * (1 / risk) * winning_settlement
            self.assertEqual(expected_total, agents * expected_price)

    def test_risk_50_has_five_expected_winners_and_low_zero_win_probability(self):
        agents, risk = 250, 50
        self.assertEqual(agents / risk, 5)
        self.assertLess((1 - 1 / risk) ** agents, 0.007)

    def test_risk_250_has_one_expected_winner_but_high_variance(self):
        agents, risk = 250, 250
        self.assertEqual(agents / risk, 1)
        self.assertAlmostEqual((1 - 1 / risk) ** agents, math.exp(-1), delta=0.002)


class RandPayImplementationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.endpoint = (ADDON / "web" / "randpay.php").read_text()
        cls.installer = (ADDON / "install.sh").read_text()
        cls.batch_patch = (ADDON / "batch.patch").read_text()
        cls.randpay_patch = (ADDON / "randpay.patch").read_text()

    def test_only_real_emercoin_rpc_names_are_used(self):
        combined = "\n".join(
            [self.endpoint, self.batch_patch, self.randpay_patch, (ADDON / "README.md").read_text()]
        )
        self.assertNotIn("name_updatemany", combined)
        for method in ("randpay_mkchap", "decoderawtransaction", "randpay_accept", "name_new"):
            self.assertIn(method, combined)

    def test_non_naive_exact_only_preflight_is_present(self):
        self.assertIn("ecececececececececececececececececececececececececececececec", self.endpoint)
        self.assertRegex(self.endpoint, r"randpay_accept\(\$raw,\s*2\)")
        self.assertIn("decoded['vout'][0]['value']", self.endpoint)
        self.assertIn("($won && !$sent) || (!$won && $sent)", self.endpoint)

    def test_valid_authorization_is_persisted_before_service_execution(self):
        saved = self.endpoint.index("save_authorization($record)")
        served = self.endpoint.index("processAuthorizedSlot($slotId)")
        self.assertLess(saved, served)
        self.assertIn("RandPay authorized; EmerNVS execution will resume", self.endpoint)

    def test_server_secrets_stay_outside_web_tree(self):
        self.assertIn("dirname(__DIR__) . '/.nvs-batch-addon'", self.endpoint)
        self.assertIn("randpay.key", self.installer)
        self.assertIn("chmod 0600", self.installer)
        self.assertIn("chmod 0700", self.installer)
        self.assertNotIn("randpay.key\n  web/", self.installer)

    def test_installer_applies_real_batch_before_randpay_extension(self):
        batch = self.installer.index('randpay.patch"')
        base = self.installer.index('batch.patch"')
        self.assertLess(base, batch)
        self.assertIn("web/randpay.php", self.installer)
        self.assertIn('php -l "$stage/root/$relative"', self.installer)

    def test_android_page_has_qr_and_exact_nch_fallback(self):
        self.assertIn("ness-qrcode.js", self.endpoint)
        self.assertIn("emercoin://randpay", self.endpoint)
        self.assertIn("exact one-transaction NCH cohort payment", self.endpoint)
        self.assertIn("/slot.php?slot=", self.endpoint)

    def test_no_external_qr_dependency_is_reintroduced(self):
        ui_patch = (ADDON / "ui.patch").read_text()
        self.assertNotIn('+    <script src="https://cdn.jsdelivr.net/npm/qrcode', ui_patch)
        self.assertIn("/js/ness-qrcode.js", ui_patch)

    def test_token_fields_are_hmac_authenticated(self):
        self.assertIn("hash_hmac('sha256', $payload, signing_key(), true)", self.endpoint)
        self.assertIn("hash_equals", self.endpoint)
        self.assertIn("Slot quote changed after the RandPay challenge", self.endpoint)


if __name__ == "__main__":
    unittest.main()
