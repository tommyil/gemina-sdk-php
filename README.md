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

`filetag` is deliberately absent: the upload endpoints reject it, and FileTag
has its own endpoints.

## What did an extraction cost?

Credits are charged *after* the result is delivered, so cost is a separate
lookup rather than a field on the extraction response. Ask for one extraction,
or up to 100 at a time:

```php
$single = $client->documents()->getExtractionCost($extractionId);
echo $single->getData()->getState(), ' ', $single->getData()->getCreditsConsumed(), PHP_EOL;

$bulk = $client->documents()->getExtractionCosts([$extractionId, $otherId]);
foreach ($bulk->getData()->getCosts() as $cost) {
    echo $cost->getExtractionId(), ' ', $cost->getState(), PHP_EOL;
}
```

`state` tells you whether the number is final:

- `settled` — the charge is on record; this is the authoritative number.
- `pending` — billing has not run yet. Retry.
- `not_charged` — billing finished without a charge. This never resolves, so
  don't poll it.

Enterprise accounts are billed in contract dollars: `creditsConsumed` is null
and `costCents` carries the amount. The bulk call silently omits ids you don't
own, so key the response by `extractionId` rather than assuming input order.

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

**Advanced filters & match highlights.** Beyond the promoted `filters`, filter on *any* structured field a document has with `structuredFilters` (`op` is one of `eq` / `neq` / `gt` / `lt` / `contains` / `exists`, max 8), and read back the line-item snippet that made a document match via `getMatchedChunks()`:

```php
use Gemina\Sdk\Model\RetrievalQueryInDTO;
use Gemina\Sdk\Model\StructuredFilterDTO;

$out = $client->retrieval()->retrievalQuery(new RetrievalQueryInDTO([
    'mode' => 'hybrid',
    'text' => '27-inch monitors',
    'structured_filters' => [
        new StructuredFilterDTO(['path' => 'position', 'op' => 'contains', 'value' => 'engineer']),
    ],
]));

foreach ($out->getItems() as $item) {
    foreach ($item->getMatchedChunks() ?? [] as $chunk) {
        printf("%s matched on: %s\n", $item->getDocumentId(), $chunk->getText());
    }
}
```

Discover which fields you can filter on with `$client->retrieval()->retrievalFields()` — it returns the structured field names per document type (names only, never values), so you can build a field picker from real data:

```php
foreach ($client->retrieval()->retrievalFields()->getFields() as $field) {
    // e.g. documentType "invoice", field "vendor_name", count 42
    printf("%s.%s (%d)\n", $field->getDocumentType(), $field->getField(), $field->getCount());
}
```

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

**Multi-turn conversations (memory).** For a back-and-forth where follow-ups keep context, use a **conversation** — it threads the server-issued `sessionId` for you:

```php
$chat = $client->conversation();
$chat->send('How much did I spend on hosting in June, and with which vendor?');
$follow = $chat->send('And which month was cheapest?'); // remembers hosting / June
printf("%s · session: %s\n", $follow->getAnswer(), $chat->getSessionId());

$chat->delete(); // end it server-side (or $chat->reset() to just forget it locally)
```

A conversation's live context expires after roughly 24h of inactivity; the next `send()` then throws the API's `404 CHAT_SESSION_NOT_FOUND` (an `ApiException`) — call `$chat->reset()` and resend to continue in a fresh one. The transcript itself is not lost: it stays available in chat history (below) until your data-retention window — or an explicit purge — removes it. One-shot `chatQuery()` with a `session_id` is still available if you'd rather hold the id yourself; every response returns `getSessionId()`.

## Chat history

Past conversations are kept as sessions you can list, reread, and purge:

```php
$listing = $client->chat()->listChatSessions(0, 20);
foreach ($listing->getSessions() as $session) {
    printf("%s · %d turns\n", $session->getTitle(), $session->getTurnCount());
}

$sessionId = $listing->getSessions()[0]->getId();
$transcript = $client->chat()->getChatSession($sessionId);
foreach ($transcript->getMessages() as $msg) {
    printf("[%s] %s\n", $msg->getRole(), $msg->getContent());
}

$client->chat()->purgeChatSession($sessionId);
```

`purgeChatSession()` permanently deletes the transcript and the server-side copy of its content — it cannot be undone. Purged sessions vanish from the list; pass `$with_purged = true` to see their content-free stubs — title cleared, `getPurgedAt()`/`getPurgeReason()` set; timestamps, `getTurnCount()`, and `getEndUserId()` survive. Transcripts also age out automatically under your account's data-retention setting (each session's `getPurgeAt()` tells you when). Purging requires an API key or a console sign-in — browser session tokens can list and read history, but never purge.

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

## Human verification in the browser

`@gemina/elements` also ships `<GeminaVerification>`: a drop-in review step that puts the document next to every extracted field, lets a person correct what's wrong, and sends the corrections back to Gemina for accuracy scoring.

Mint the token **scoped to the extraction being reviewed** — an unscoped token that reaches a browser can read every extraction in your account:

```php
$token = $client->sessions()->mintRetrievalToken(new SessionTokenInDTO([
    'extraction_ids' => [$extractionId],  // up to 10; pins the token to these
    'ttl_seconds' => 900,
]));
```

An empty list is rejected rather than quietly minting a tenant-wide token. Your endpoint must check that the requesting end-user is allowed to see those extractions: Gemina enforces the claim, you decide who gets it.

Upload with `evaluation` enabled to give the reviewer per-field confidence scores and the "hide everything already scored high" filter.

Verification is **one-shot** per extraction — a second submission is rejected with 409. Afterwards the extraction carries two new fields, both null until someone verifies it: `verifiedValues` — the same shape as `values`, with the reviewer's corrections merged in, so switching payloads is a one-name change — and `verifiedDiff`, the typed list of what they changed. To submit a review from your own UI instead of the widget:

```php
use Gemina\Sdk\Model\ExtractionValidationInDTO;

$summary = $client->documents()->validateDocumentExtraction($extractionId, new ExtractionValidationInDTO([
    'data' => $correctedValues,
]));

$summary->getData(); // per-field comparison against what was extracted
```

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
