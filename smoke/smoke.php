<?php

/**
 * Live smoke test: authenticated call against a real Gemina deployment.
 *
 * Usage:
 *   GEMINA_BASE_URL=https://api.staging.gemina.co GEMINA_API_KEY=... php smoke/smoke.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Gemina\Sdk\GeminaClient;

$baseUrl = getenv('GEMINA_BASE_URL');
$apiKey = getenv('GEMINA_API_KEY');

if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "GEMINA_API_KEY is not set\n");
    exit(1);
}

if ($baseUrl === false || $baseUrl === '') {
    $baseUrl = GeminaClient::DEFAULT_BASE_URL;
}

try {
    $client = new GeminaClient($apiKey, $baseUrl);
    $status = $client->retrieval()->retrievalStatus();

    printf(
        "retrieval status OK — baseUrl=%s indexedDocuments=%s servedAt=%s\n",
        $baseUrl,
        var_export($status->getIndexedDocuments(), true),
        $status->getServedAt() instanceof \DateTimeInterface
            ? $status->getServedAt()->format(DATE_ATOM)
            : 'n/a',
    );
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("Smoke test failed: %s: %s\n", get_class($e), $e->getMessage()));
    exit(1);
}
