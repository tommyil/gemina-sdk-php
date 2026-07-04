<?php

declare(strict_types=1);

namespace Gemina\Sdk;

/**
 * Marks a processDocument() source as a URL reference.
 *
 * URLs are submitted via POST /v1/documents/requests/web instead of the
 * multipart file endpoint.
 */
final class UrlSource
{
    public function __construct(
        public readonly string $url,
    ) {
    }
}
