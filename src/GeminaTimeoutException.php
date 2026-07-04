<?php

declare(strict_types=1);

namespace Gemina\Sdk;

use Gemina\Sdk\Model\DocumentProcessingResultOutDTO;

/**
 * Thrown when the processDocument() polling deadline is exceeded.
 *
 * Carries the correlation ID and the last seen (non-terminal) result so
 * callers can resume polling themselves via
 * `$client->documents()->getDocumentProcessingResultByCorrelationId($id)`.
 */
class GeminaTimeoutException extends GeminaException
{
    public function __construct(
        private readonly string $correlationId,
        private readonly ?DocumentProcessingResultOutDTO $lastResult = null,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Timed out waiting for document processing to finish (correlationId: %s)',
                $correlationId,
            ),
        );
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getLastResult(): ?DocumentProcessingResultOutDTO
    {
        return $this->lastResult;
    }
}
