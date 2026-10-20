<?php

declare(strict_types=1);

namespace TelebirrVerifier;

use RuntimeException;

/**
 * Fetches receipt pages from Ethio Telecom and verifies them against expectations.
 */
final class Verifier
{
    public const BASE_URL = 'https://transactioninfo.ethiotelecom.et/receipt/';

    /** @param array{timeout?: int, verify_ssl?: bool, ca_bundle?: ?string} $options */
    public function __construct(private array $options = [])
    {
    }

    /**
     * Accepts a bare receipt number ("CGP5Q3APB5") or a full receipt URL.
     * Returns the normalized receipt number, or null when invalid.
     */
    public static function normalizeReceiptNo(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('~transactioninfo\.ethiotelecom\.et/receipt/([A-Za-z0-9]+)~i', $input, $m)) {
            $input = $m[1];
        }
        $input = strtoupper($input);
        return preg_match('/^[A-Z0-9]{6,20}$/', $input) ? $input : null;
    }

    /**
     * Downloads the raw receipt HTML.
     *
     * @throws RuntimeException on network / HTTP failure.
     */
    public function fetchHtml(string $receiptNo): string
    {
        $ch = curl_init(self::BASE_URL . rawurlencode($receiptNo));
        $verifySsl = $this->options['verify_ssl'] ?? true;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->options['timeout'] ?? 20,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            ],
        ];
        if (!empty($this->options['ca_bundle'])) {
            $opts[CURLOPT_CAINFO] = $this->options['ca_bundle'];
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Could not reach Ethio Telecom: ' . $err);
        }
        if ($code >= 500) {
            throw new RuntimeException("Ethio Telecom returned HTTP $code");
        }
        return (string) $body;
    }

    /**
     * Fetch + parse + optionally check expectations.
     *
     * Supported expectations (all optional):
     *   receiver_name, receiver_account, payer_name, payer_phone, amount, min_amount
     *
     * @param array<string, string|float|null> $expect
     * @return array{found: bool, receipt?: array<string, mixed>, verified?: bool, checks?: array<string, array<string, mixed>>}
     */
    public function verify(string $receiptNo, array $expect = []): array
    {
        $html = $this->fetchHtml($receiptNo);
        $receipt = ReceiptParser::parse($html);
        if ($receipt === null) {
            return ['found' => false];
        }
        return ['found' => true] + self::evaluate($receipt, $expect);
    }

    /**
     * @param array<string, mixed> $receipt
     * @param array<string, string|float|null> $expect
     * @return array{receipt: array<string, mixed>, verified: bool, checks: array<string, array<string, mixed>>}
     */
    public static function evaluate(array $receipt, array $expect = []): array
    {
        $checks = [];

        $checks['status_completed'] = [
            'expected' => 'Completed',
            'actual'   => $receipt['status'],
            'passed'   => strcasecmp((string) $receipt['status'], 'Completed') === 0,
        ];

        $settled = $receipt['amount']['settled'] ?? $receipt['amount']['total_paid'];

        $map = [
            'receiver_name'    => [$receipt['to']['name'], 'name'],
            'receiver_account' => [$receipt['to']['account'], 'account'],
            'payer_name'       => [$receipt['from']['name'], 'name'],
            'payer_phone'      => [$receipt['from']['phone'], 'account'],
        ];
        foreach ($map as $key => [$actual, $type]) {
            if (!isset($expect[$key]) || $expect[$key] === '') {
                continue;
            }
            $exp = (string) $expect[$key];
            $passed = $type === 'name'
                ? self::namesMatch($exp, (string) $actual)
                : self::accountsMatch($exp, (string) $actual);
            $checks[$key] = ['expected' => $exp, 'actual' => $actual, 'passed' => $passed];
        }

        if (isset($expect['amount']) && $expect['amount'] !== '') {
            $exp = (float) $expect['amount'];
            $checks['amount'] = [
                'expected' => $exp,
                'actual'   => $settled,
                'passed'   => $settled !== null && abs($settled - $exp) < 0.01,
            ];
        }
        if (isset($expect['min_amount']) && $expect['min_amount'] !== '') {
            $exp = (float) $expect['min_amount'];
            $checks['min_amount'] = [
                'expected' => $exp,
                'actual'   => $settled,
                'passed'   => $settled !== null && $settled + 0.001 >= $exp,
            ];
        }

        $verified = array_reduce($checks, fn (bool $ok, array $c) => $ok && $c['passed'], true);

        return ['receipt' => $receipt, 'verified' => $verified, 'checks' => $checks];
    }

    /** Case/space-insensitive name match. */
    public static function namesMatch(string $expected, string $actual): bool
    {
        $norm = fn (string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? '');
        return $norm($expected) !== '' && $norm($expected) === $norm($actual);
    }

    /**
     * Phone/account match that tolerates the receipt's masking ("2519****4429")
     * and different phone prefixes (0911..., 251911..., +251911...).
     */
    public static function accountsMatch(string $expected, string $actual): bool
    {
        $e = preg_replace('/[^0-9]/', '', $expected) ?? '';
        $a = preg_replace('/[^0-9*]/', '', $actual) ?? '';
        if ($e === '' || $a === '') {
            return false;
        }

        $variants = [$e];
        if (preg_match('/^0(9|7)\d{8}$/', $e)) {
            $variants[] = '251' . substr($e, 1);
        } elseif (preg_match('/^251(9|7)\d{8}$/', $e)) {
            $variants[] = '0' . substr($e, 3);
        }

        $pattern = '/^' . str_replace('\*', '\d', preg_quote($a, '/')) . '$/';
        foreach ($variants as $v) {
            if ($v === $a || preg_match($pattern, $v)) {
                return true;
            }
        }
        return false;
    }
}
