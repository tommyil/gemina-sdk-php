<?php

declare(strict_types=1);

namespace Gemina\Sdk;

/**
 * Base exception for all hand-written SDK errors.
 *
 * Transport/HTTP errors raised by the generated client
 * (\Gemina\Sdk\ApiException) pass through unwrapped.
 */
class GeminaException extends \RuntimeException
{
}
