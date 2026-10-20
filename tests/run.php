<?php

/**
 * Offline tests against a saved real receipt page. Run: php tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/ReceiptParser.php';
require __DIR__ . '/../src/Verifier.php';

use TelebirrVerifier\ReceiptParser;
use TelebirrVerifier\Verifier;

$failures = 0;
$total = 0;
function check(string $name, bool $cond): void
{
    global $failures, $total;
    $total++;
    echo ($cond ? "  PASS " : "  FAIL ") . $name . PHP_EOL;
    if (!$cond) {
        $failures++;
    }
}

$html = file_get_contents(__DIR__ . '/fixtures/sample_receipt.html');
$r = ReceiptParser::parse($html);

echo "Parser\n";
check('parses receipt', $r !== null);
check('receipt_no', $r['receipt_no'] === 'CGP5Q3APB5');
check('payment_date', $r['payment_date'] === '25-07-2025 13:22:34');
check('status', $r['status'] === 'Completed');
check('from.name', $r['from']['name'] === 'John Doe');
check('from.phone', $r['from']['phone'] === '2519****4429');
check('from.account_type', $r['from']['account_type'] === 'Individual Customer');
check('to.name', $r['to']['name'] === 'Ethio telecom');
check('to.account', $r['to']['account'] === '111222');
check('amount.settled', $r['amount']['settled'] === 63.0);
check('amount.total_paid', $r['amount']['total_paid'] === 63.0);
check('amount.service_fee', $r['amount']['service_fee'] === 0.0);

echo "Not-found page\n";
$nf = '<html><body><div>This request is not correct</div></body></html>';
check('not found => null', ReceiptParser::parse($nf) === null);

echo "Receipt number normalization\n";
check('bare id', Verifier::normalizeReceiptNo('cgp5q3apb5') === 'CGP5Q3APB5');
check('full url', Verifier::normalizeReceiptNo('https://transactioninfo.ethiotelecom.et/receipt/CGP5Q3APB5') === 'CGP5Q3APB5');
check('rejects junk', Verifier::normalizeReceiptNo('../etc/passwd') === null);

echo "Matching\n";
check('masked phone 0-prefix', Verifier::accountsMatch('0912344429', '2519****4429'));
check('masked phone +251', Verifier::accountsMatch('+251912344429', '2519****4429'));
check('masked phone mismatch', !Verifier::accountsMatch('0912340000', '2519****4429'));
check('name case-insensitive', Verifier::namesMatch('ethio  TELECOM', 'Ethio telecom'));

echo "Evaluate\n";
$ok = Verifier::evaluate($r, ['receiver_name' => 'Ethio telecom', 'receiver_account' => '111222', 'amount' => '63', 'payer_phone' => '0912344429']);
check('all expectations pass => verified', $ok['verified'] === true);
$bad = Verifier::evaluate($r, ['amount' => '100']);
check('wrong amount => not verified', $bad['verified'] === false && $bad['checks']['amount']['passed'] === false);
$min = Verifier::evaluate($r, ['min_amount' => '50']);
check('min_amount passes', $min['verified'] === true);

echo PHP_EOL . ($total - $failures) . "/$total passed" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
