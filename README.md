# Gemina PHP SDK

Official PHP client for the Gemina API — invoice OCR and document intelligence: upload documents, get typed structured data back, then search, aggregate, and chat over everything you've processed.

## Install

```bash
composer require gemina/sdk
```

Requires PHP 8.1+ with the `curl`, `json`, and `mbstring` extensions.

## Authenticate

Get your API key from the [Gemina Console](https://console.gemina.co). The client sends it as the `X-API-Key` header on every request:

```php
use Gemina\Sdk\GeminaClient;

$client = new GeminaClient('YOUR_API_KEY');
```

Never ship the API key in browser or mobile code. For browser embedding, mint short-lived session tokens server-side (`POST /v1/sessions/token`) — see [Session tokens](#session-tokens-browser-embedding) below and the Document Intelligence guide at [console.gemina.co/docs](https://console.gemina.co/docs).

## Quickstart — process an invoice in one call

`processDocument()` submits the file through the async API, polls with exponential backoff until processing finishes, and returns the final typed result:

```php
<?php

require 'vendor/autoload.php';

use Gemina\Sdk\GeminaClient;

$client = new GeminaClient(getenv('GEMINA_API_KEY'));

$result = $client->processDocument('invoice.png', ['invoice_headers']);

echo 'Status: ', $result->getStatus(), PHP_EOL;

$extraction = $result->getData()->getExtractions()[0];
$values = $extraction->getValues();

// Each field is an object with ->value (plus ->coordinates and ->confidence when available)
echo 'Supplier: ', $values['vendorName']->value ?? 'n/a', PHP_EOL;
echo 'Total:    ', $values['totalAmount']->value ?? 'n/a', PHP_EOL;
echo 'Date:     ', $values['invoiceDate']->value ?? 'n/a', PHP_EOL;
```

Documents reachable by URL work the same way — wrap the URL in `UrlSource`:

```php
use Gemina\Sdk\UrlSource;

$result = $client->processDocument(
    new UrlSource('https://example.com/invoices/2026-06.pdf'),
    ['invoice_headers'],
);
```

## What you get back

`processDocument()` returns a `DocumentProcessingResultOutDTO`:

- `getStatus()` — `success` | `partial` | `empty` (`failed` throws `GeminaProcessingException` instead; `partial` and `empty` still carry usable data and meta).
- `getData()->getExtractions()` — one entry per requested extraction type. Each has `getMeta()->getExtractionType()` and `getValues()`, a map of field name to an object with `value`, `coordinates`, and `confidence`.
- `getMeta()->getDocumentId()` / `getMeta()->getCorrelationId()` — the stored document's ID and the async request's correlation ID.

Extraction types:

| Type | What it extracts |
|---|---|
| `ocr` | Raw text (OCR only) |
| `invoice_headers` | Vendor, buyer, invoice number, dates, amounts, payment method |
| `invoice_line_items` | Line items table |
| `document_details_hebrew` | Hebrew document header details |
| `document_line_items_hebrew` | Hebrew document line items |
| `custom_template` | Fields defined by your own template (pass `templateId` in options) |
| `filetag` | Document classification and file-naming metadata |

## Search & aggregate your documents

Everything you process is indexed for retrieval. Query it in natural language plus structured filters:

```php
use Gemina\Sdk\Model\RetrievalQueryInDTO;

$out = $client->retrieval()->retrievalQuery(new RetrievalQueryInDTO([
    'text' => 'cloud hosting invoices from June',
    'top_k' => 10,
]));

foreach ($out->getItems() as $item) {
    printf(
        "%s | %s | %s %s\n",
        $item->getDocumentId(),
        $item->getVendorName(),
        $item->getTotalAmount(),
        $item->getCurrency(),
    );
}
```

Results carry citations back to your documents: each item includes `getDocumentId()` and `getDocumentExtractionId()`.

Aggregate across documents (sum/avg/min/max/count over amounts, grouped by any dimension):

```php
use Gemina\Sdk\Model\AggregateMetricDTO;
use Gemina\Sdk\Model\RetrievalAggregateInDTO;

$agg = $client->retrieval()->retrievalAggregate(new RetrievalAggregateInDTO([
    'metrics' => [new AggregateMetricDTO(['op' => 'sum', 'field' => 'total_amount'])],
    'group_by' => ['vendor_name'],
]));

foreach ($agg->getRows() as $row) {
    print_r($row->getGroup());
    print_r($row->getValues());
}
```

Check how many of your documents are indexed with `$client->retrieval()->retrievalStatus()->getIndexedDocuments()`.

## Chat with your documents

Ask questions in natural language; answers come back with citations:

```php
use Gemina\Sdk\Model\ChatQueryInDTO;

$chat = $client->chat()->chatQuery(new ChatQueryInDTO([
    'message' => 'How much did I spend on hosting in June, and with which vendor?',
]));

echo $chat->getAnswer(), PHP_EOL;
echo 'Confident: ', $chat->getConfident() ? 'yes' : 'no', PHP_EOL;
print_r($chat->getCitations());
```

Chat requires a plan with Document Intelligence enabled — see [pricing](https://gemina.co). Without it, the API returns `402`/`403`.

## Session tokens (browser embedding)

To let a browser query retrieval or chat directly, mint a short-lived session token server-side and hand that to your frontend — never the API key:

```php
use Gemina\Sdk\Model\SessionTokenInDTO;

$token = $client->sessions()->mintRetrievalToken(new SessionTokenInDTO([
    'end_user_id' => 'user-42',
    'ttl_seconds' => 900,
]));

echo $token->getToken(); // pass to the frontend
```

A client can also authenticate with a session token directly (bearer auth) — useful for server-side code acting within a session scope:

```php
$sessionClient = GeminaClient::withSessionToken($sessionToken);
```

For a drop-in chat UI, see the `@gemina/elements` package on npm.

## Going deeper

### Full API surface

The generated client covers the entire API. Reach any group through the accessors — `documents()`, `retrieval()`, `chat()`, `templates()`, `files()`, `fileTag()`, `sessions()`, `subscriptions()`, `billing()`:

```php
$page = $client->documents()->findDocuments(0, 20);
foreach ($page->getData()->getDocuments() as $doc) {
    echo $doc->getMeta()->getDocumentId(), PHP_EOL;
}
```

### Polling knobs

`processDocument()` accepts polling options (defaults: 300 s deadline, 2 s initial interval growing ×1.5 up to 15 s, with jitter):

```php
$result = $client->processDocument('invoice.png', ['invoice_headers'], [
    'timeoutSeconds' => 600,
    'initialIntervalSeconds' => 1.0,
    'maxIntervalSeconds' => 10.0,
]);
```

Other options mirror the endpoint's form fields: `externalId` (defaults to a generated unique ID), `templateId`, `modelType`, `thinking`, `evaluation`, `correction`, `includeCoordinates`, `endUserId`.

If the deadline is exceeded, `GeminaTimeoutException` carries the correlation ID so you can resume polling yourself:

```php
use Gemina\Sdk\GeminaTimeoutException;

try {
    $result = $client->processDocument('invoice.png', ['invoice_headers'], ['timeoutSeconds' => 30]);
} catch (GeminaTimeoutException $e) {
    // Later — or from another process:
    $result = $client->documents()
        ->getDocumentProcessingResultByCorrelationId($e->getCorrelationId());
}
```

### Error handling

```php
use Gemina\Sdk\ApiException;
use Gemina\Sdk\GeminaProcessingException;
use Gemina\Sdk\GeminaTimeoutException;

try {
    $result = $client->processDocument('invoice.png', ['invoice_headers']);
} catch (GeminaProcessingException $e) {
    // Terminal "failed" — the full result is attached
    print_r($e->getResult()->getErrors());
} catch (GeminaTimeoutException $e) {
    echo 'Still processing: ', $e->getCorrelationId(), PHP_EOL;
} catch (ApiException $e) {
    // Transport/HTTP errors from the generated client pass through unwrapped
    echo $e->getCode(), ': ', $e->getResponseBody(), PHP_EOL;
}
```

### Custom base URL

Point the client at a different deployment (staging, self-hosted):

```php
$client = new GeminaClient('YOUR_API_KEY', 'https://api.staging.gemina.co');
```

## Requirements & support

- PHP 8.1 or newer (`curl`, `json`, `mbstring` extensions)
- Docs: [console.gemina.co/docs](https://console.gemina.co/docs)
- Issues: [github.com/tommyil/gemina-sdk/issues](https://github.com/tommyil/gemina-sdk/issues)
- Email: support@gemina.co
