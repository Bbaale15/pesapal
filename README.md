# Pesapal Payment Gateway for OpenCart 4

A payment extension that integrates [Pesapal API 3.0](https://developer.pesapal.com/) with OpenCart 4.x, supporting Mobile Money, Visa/Mastercard and Bank Transfer payments in UGX, USD and other Pesapal-supported currencies.

Built and maintained by [Toolbox Technologies](https://toolbox.ug).

## Features

- **Sandbox & Live environments** — switch with one dropdown, no code changes
- **Hosted payment page** — customers are redirected to Pesapal's secure page (Mobile Money, Visa/Mastercard, Bank Transfer)
- **IPN / webhook processing** — server-to-server payment notifications with idempotent handling (repeated notifications never create duplicate order history)
- **Callback verification** — transaction status is always verified against the Pesapal API; redirect parameters are never trusted
- **Order status mapping** — configurable OpenCart statuses for Pending, Completed, Failed and Cancelled payments
- **Transaction records** — every payment attempt is stored in a dedicated `pesapal_transaction` table
- **Debug logging** — optional logging to `storage/logs/pesapal.log` with automatic redaction of keys, secrets and tokens
- **Geo zone support** — restrict the payment method to specific regions

## Requirements

- OpenCart **4.0.x / 4.1.x**
- PHP **8.0+** (8.1+ recommended)
- PHP cURL extension
- A Pesapal merchant account ([register here](https://www.pesapal.com/))

## Installation

1. Download `pesapal.ocmod.zip` from the [Releases](../../releases) page (or build it — see below).
2. In the OpenCart admin, go to **Extensions → Installer** and upload the zip.
3. Go to **Extensions → Extensions → Payments**, find **Pesapal Payment Gateway** and click **Install** (green `+`). This creates the transaction table.
4. Click **Edit** to configure.

## Configuration

| Setting | Description |
|---|---|
| Consumer Key / Secret | From your Pesapal merchant dashboard. Sandbox and Live use **different** credentials. |
| Environment | `Sandbox` for testing, `Live` for production. |
| Order statuses | Map Pesapal outcomes (Pending / Completed / Failed / Cancelled) to OpenCart order statuses. |
| Geo Zone | Optionally limit availability to a geo zone. |
| Debug Logging | Writes API activity to `storage/logs/pesapal.log` (secrets are redacted). |
| Status | Enable the payment method at checkout. |

The IPN URL is registered with Pesapal automatically on the first checkout — no manual setup needed.

### Sandbox testing

Set Environment to **Sandbox** and use Pesapal's demo credentials from the [API reference](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/api-reference) ("Sandbox merchant accounts" section). Live merchant keys will **not** work in sandbox, and vice versa.

## How it works

1. Customer selects Pesapal at checkout and clicks **Pay with Pesapal**.
2. The extension authenticates (`/api/Auth/RequestToken`), registers the IPN URL once (`/api/URLSetup/RegisterIPN`), submits the order (`/api/Transactions/SubmitOrderRequest`) and redirects the customer to Pesapal's hosted payment page.
3. After payment, Pesapal redirects the customer to the **callback** route and independently notifies the **IPN** route.
4. Both routes verify the real status via `/api/Transactions/GetTransactionStatus`, update the transaction record and order history (idempotently), and route the customer to the success or failure page.

### Routes

| Route | Purpose |
|---|---|
| `extension/pesapal/payment/pesapal.confirm` | Starts a payment (AJAX from checkout) |
| `extension/pesapal/payment/callback` | Customer redirect after payment |
| `extension/pesapal/payment/ipn` | Server-to-server notification from Pesapal |

## Project structure

```
extension/pesapal/
├── install.json                     # Extension metadata
├── admin/
│   ├── controller/payment/pesapal.php
│   ├── model/payment/pesapal.php    # Creates the transaction table
│   ├── language/en-gb/payment/pesapal.php
│   └── view/template/payment/pesapal.twig
├── catalog/
│   ├── controller/payment/
│   │   ├── pesapal.php              # Checkout + confirm
│   │   ├── callback.php             # Customer return URL
│   │   └── ipn.php                  # Webhook endpoint
│   ├── model/payment/pesapal.php
│   ├── language/en-gb/payment/pesapal.php
│   └── view/template/payment/pesapal.twig
└── system/library/pesapal/          # Framework-independent API client
    ├── Config.php                   # Environment & endpoints
    ├── Logger.php                   # File logging with secret redaction
    ├── Client.php                   # cURL HTTP client
    ├── Auth.php                     # Bearer token requests
    └── Order.php                    # IPN registration, order submit, status query
```

## Building the zip

```bash
zip -r pesapal.ocmod.zip install.json extension/pesapal/ --exclude "*.DS_Store"
```

## Troubleshooting

- **"Payment could not be initiated"** — enable Debug Logging and check `storage/logs/pesapal.log`; the most common cause is `invalid_consumer_key_or_secret_provided` (wrong environment for the credentials, or stray whitespace in the keys).
- **Order status not updating after payment** — confirm your store URL is publicly reachable (Pesapal must be able to call the IPN route) and that the four order statuses are mapped in settings.
- **Nothing in `pesapal.log`** — logging only writes when the Debug setting is enabled.

## Security notes

- Consumer secrets and bearer tokens are never written to logs (automatic redaction).
- Bearer tokens are cached in the session only, never stored in the database.
- Payment status is always verified via the Pesapal API — callback/IPN query parameters are never trusted.

## License

MIT — see [LICENSE](LICENSE).
