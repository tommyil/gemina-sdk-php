<?php

declare(strict_types=1);

namespace Gemina\Sdk;

/**
 * Package version consumed by the User-Agent string.
 *
 * This is the single hand-written source of truth for the SDK version
 * (Packagist versions come from git tags; generated metadata is discarded).
 */
final class SdkVersion
{
    public const VERSION = '0.1.1';

    private function __construct()
    {
    }
}
