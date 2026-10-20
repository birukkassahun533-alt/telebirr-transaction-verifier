# Telebirr Transaction Verifier

A small PHP API that verifies **telebirr** transactions using the receipt number. It reads the official Ethio Telecom receipt page at
`https://transactioninfo.ethiotelecom.et/receipt/{RECEIPT_NO}` and returns **who paid (from)**, **who received (to)**, the amount, the status, and the date as JSON. You can also have it check that the payment went to the right account and was for the right amount.

## Requirements

- PHP 8.1+ with the `curl`, `dom`, and `mbstring` extensions (XAMPP includes all three)
- Outbound HTTPS access to `transactioninfo.ethiotelecom.et`

> **Note:** Ethio Telecom's receipt server can block or throttle traffic from outside Ethiopia. If it does, host the API on a server in Ethiopia.

## Setup

```bash
git clone https://github.com/birukkassahun533-alt/telebirr-transaction-verifier.git
cd telebirr-transaction-verifier
cp config.example.php config.php   # optional: API key, CORS, SSL settings
php -S 127.0.0.1:8000              # or put the folder under XAMPP's htdocs
```

## Endpoint

`GET|POST /api/verify.php`

| Field              | Required | Description                                                         |
|--------------------|----------|---------------------------------------------------------------------|
| `receipt`          | yes      | Receipt number (`CGP5Q3APB5`) or the full transactioninfo URL       |
| `receiver_name`    | no       | Expected credited party name (case-insensitive)                     |
| `receiver_account` | no       | Expected receiver phone/account (`0911…`, `251911…`, masked numbers OK) |
| `payer_name`       | no       | Expected payer name                                                 |
| `payer_phone`      | no       | Expected payer phone (masked numbers OK, e.g. `2519****4429`)       |
| `amount`           | no       | Exact expected settled amount (ETB)                                 |
| `min_amount`       | no       | Minimum settled amount (ETB)                                        |

`verified` is `true` only when the transaction status is **Completed** and every expectation you sent matches.

### Example

```bash
curl "http://127.0.0.1:8000/api/verify.php?receipt=CGP5Q3APB5&receiver_account=111222&amount=63"
```

```json
{
  "success": true,
  "verified": true,
  "receipt_no": "CGP5Q3APB5",
  "receipt_url": "https://transactioninfo.ethiotelecom.et/receipt/CGP5Q3APB5",
  "transaction": {
    "receipt_no": "CGP5Q3APB5",
    "payment_date": "25-07-2025 13:22:34",
    "status": "Completed",
    "from": { "name": "John Doe", "phone": "2519****4429", "account_type": "Individual Customer", "tin": null },
    "to":   { "name": "Ethio telecom", "account": "111222", "bank": null },
    "amount": { "settled": 63, "total_paid": 63, "service_fee": 0, "currency": "ETB" }
  },
  "checks": {
    "status_completed": { "expected": "Completed", "actual": "Completed", "passed": true },
    "receiver_account": { "expected": "111222", "actual": "111222", "passed": true },
    "amount":           { "expected": 63, "actual": 63, "passed": true }
  }
}
```

POST works the same way, with a JSON or form body:

```bash
curl -X POST http://127.0.0.1:8000/api/verify.php \
  -H "Content-Type: application/json" \
  -d '{"receipt":"CGP5Q3APB5","receiver_name":"Ethio telecom","min_amount":50}'
```

### HTTP status codes

| Code | Meaning                                                 |
|------|---------------------------------------------------------|
| 200  | Receipt found (check `verified`)                        |
| 400  | Missing or invalid input                                |
| 401  | Wrong or missing API key (only when `api_key` is set)   |
| 404  | Ethio Telecom has no receipt with that number           |
| 502  | Ethio Telecom could not be reached                      |

For wallet-to-bank transfers, `to.bank` holds the bank name, and `to.name` / `to.account` hold the destination account holder and account number.

## Tests

```bash
php tests/run.php
```

The tests run offline against a saved real receipt page in `tests/fixtures/`.

## Security tips

- Store each receipt number you accept and reject repeats, so the same receipt can't be used twice.
- Always send `receiver_account` and `amount`. Otherwise any completed receipt will pass.
- Set `api_key` in `config.php` before exposing the API publicly.
