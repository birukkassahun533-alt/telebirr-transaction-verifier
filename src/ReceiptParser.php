<?php

declare(strict_types=1);

namespace TelebirrVerifier;

use DOMDocument;
use DOMXPath;

/**
 * Parses the HTML receipt page served by
 * https://transactioninfo.ethiotelecom.et/receipt/{receiptNo}
 *
 * The page is a table layout with bilingual (Amharic/English) labels, e.g.
 *   <td>የከፋይ ስም/Payer Name</td><td>John Doe</td>
 * Invoice details use a header row followed by a value row:
 *   Invoice No. | Payment date | Settled Amount
 *   CGP5Q3APB5  | 25-07-2025 13:22:34 | 63.00 Birr
 */
final class ReceiptParser
{
    /** Marker text Ethio Telecom returns for unknown / malformed receipt numbers. */
    public const NOT_FOUND_MARKER = 'This request is not correct';

    /** English label fragments => output keys (matched case-insensitively). */
    private const LABELS = [
        'payer_name'            => 'Payer Name',
        'payer_phone'           => 'Payer telebirr no',
        'payer_account_type'    => 'Payer account type',
        'payer_tin'             => 'Payer TIN No',
        'receiver_name'         => 'Credited Party name',
        'receiver_account'      => 'Credited party account no',
        'bank_account'          => 'Bank account number',
        'status'                => 'transaction status',
        'vat'                   => 'VAT ',
        'stamp_duty'            => 'Stamp Duty',
        'discount'              => 'Discount Amount',
        'service_fee'           => 'service fee',
        'service_fee_vat'       => 'Service fee VAT',
        'total_paid'            => 'Total Paid Amount',
        'amount_in_words'       => 'Total Amount in word',
    ];

    public static function isNotFoundPage(string $html): bool
    {
        return stripos($html, self::NOT_FOUND_MARKER) !== false;
    }

    /**
     * @return array<string, mixed>|null Parsed receipt, or null if the page has no receipt data.
     */
    public static function parse(string $html): ?array
    {
        if (trim($html) === '' || self::isNotFoundPage($html)) {
            return null;
        }

        $rows = self::extractRows($html);
        if ($rows === []) {
            return null;
        }

        $fields = [];
        foreach (self::LABELS as $key => $label) {
            $fields[$key] = self::valueForLabel($rows, $label, $key);
        }

        $invoice = self::extractInvoice($rows);

        if ($fields['payer_name'] === null && $fields['receiver_name'] === null && $invoice['receipt_no'] === null) {
            return null;
        }

        // Wallet-to-bank transfers: "Credited Party" is the bank, the real destination
        // is the "Bank account number" row, formatted like "1000123456789 JOHN DOE".
        $bank = null;
        if (!empty($fields['bank_account'])) {
            $bank = ['bank_name' => $fields['receiver_name'], 'account' => $fields['bank_account'], 'holder' => null];
            if (preg_match('/^(\d[\d*]*)\s+(.+)$/u', $fields['bank_account'], $m)) {
                $bank['account'] = $m[1];
                $bank['holder'] = trim($m[2]);
            }
        }

        return [
            'receipt_no'   => $invoice['receipt_no'],
            'payment_date' => $invoice['payment_date'],
            'status'       => $fields['status'],
            'from' => [
                'name'         => $fields['payer_name'],
                'phone'        => $fields['payer_phone'],
                'account_type' => $fields['payer_account_type'],
                'tin'          => $fields['payer_tin'],
            ],
            'to' => [
                'name'    => $bank['holder'] ?? $fields['receiver_name'],
                'account' => $bank['account'] ?? $fields['receiver_account'],
                'bank'    => $bank['bank_name'] ?? null,
            ],
            'amount' => [
                'settled'         => self::toAmount($invoice['settled_amount']),
                'total_paid'      => self::toAmount($fields['total_paid']),
                'service_fee'     => self::toAmount($fields['service_fee']),
                'service_fee_vat' => self::toAmount($fields['service_fee_vat']),
                'vat'             => self::toAmount($fields['vat']),
                'stamp_duty'      => self::toAmount($fields['stamp_duty']),
                'discount'        => self::toAmount($fields['discount']),
                'currency'        => 'ETB',
                'in_words'        => $fields['amount_in_words'],
            ],
        ];
    }

    /**
     * @return list<list<string>> Table rows, each a list of normalized cell texts.
     */
    private static function extractRows(string $html): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8 so Amharic labels survive.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new DOMXPath($doc);
        $rows = [];
        // The page nests layout tables; only innermost rows hold label/value pairs.
        foreach ($xpath->query('//tr[not(.//tr)]') as $tr) {
            $cells = [];
            foreach ($xpath->query('./td|./th', $tr) as $td) {
                $cells[] = self::clean($td->textContent);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    /** @param list<list<string>> $rows */
    private static function valueForLabel(array $rows, string $label, string $key): ?string
    {
        foreach ($rows as $cells) {
            foreach ($cells as $i => $cell) {
                if (stripos($cell, $label) === false) {
                    continue;
                }
                // Avoid "Service fee VAT" matching the plain "service fee" / "VAT" labels, etc.
                if ($key === 'service_fee' && stripos($cell, 'VAT') !== false) {
                    continue;
                }
                if ($key === 'vat' && stripos($cell, 'Service fee') !== false) {
                    continue;
                }
                if ($key === 'vat' && stripos($cell, 'Reg') !== false) {
                    continue;
                }
                // Value is the first non-empty cell to the right of the label.
                for ($j = $i + 1, $n = count($cells); $j < $n; $j++) {
                    if ($cells[$j] !== '') {
                        return $cells[$j];
                    }
                }
                return null;
            }
        }
        return null;
    }

    /**
     * @param list<list<string>> $rows
     * @return array{receipt_no: ?string, payment_date: ?string, settled_amount: ?string}
     */
    private static function extractInvoice(array $rows): array
    {
        $out = ['receipt_no' => null, 'payment_date' => null, 'settled_amount' => null];

        foreach ($rows as $r => $cells) {
            $col = null;
            foreach ($cells as $i => $cell) {
                if (stripos($cell, 'Invoice No') !== false) {
                    $col = $i;
                    break;
                }
            }
            if ($col === null) {
                continue;
            }
            // The next row with enough cells holds the values.
            for ($k = $r + 1, $n = count($rows); $k < $n; $k++) {
                $vals = $rows[$k];
                if (count($vals) >= $col + 3 && $vals[$col] !== '') {
                    $out['receipt_no'] = $vals[$col];
                    $out['payment_date'] = $vals[$col + 1] !== '' ? $vals[$col + 1] : null;
                    $out['settled_amount'] = $vals[$col + 2] !== '' ? $vals[$col + 2] : null;
                    return $out;
                }
            }
        }
        return $out;
    }

    private static function clean(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function toAmount(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $num = preg_replace('/[^\d.]/', '', str_replace(',', '', $value));
        return ($num === '' || $num === null || !is_numeric($num)) ? null : round((float) $num, 2);
    }
}
