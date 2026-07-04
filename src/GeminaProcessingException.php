<?php

declare(strict_types=1);

namespace Gemina\Sdk;

use Gemina\Sdk\Model\DocumentProcessingResultOutDTO;

/**
 * Thrown when document processing reaches the terminal "failed" status.
 *
 * The full result is attached; its errors list has the details:
 * `$e->getResult()->getErrors()`.
 */
class GeminaProcessingException extends GeminaException
{
    public function __construct(
        private readonly DocumentProcessingResultOutDTO $result,
        ?string $message = null,
    ) {
        if ($message === null) {
            $message = 'Document processing failed';
            $errors = $result->getErrors();
            if (!empty($errors)) {
                $encoded = json_encode($errors);
                if (is_string($encoded)) {
                    if (mb_strlen($encoded) > 500) {
                        $encoded = mb_substr($encoded, 0, 500) . '…';
                    }
                    $message .= ': ' . $encoded;
                }
            }
        }

        parent::__construct($message);
    }

    public function getResult(): DocumentProcessingResultOutDTO
    {
        return $this->result;
    }
}
