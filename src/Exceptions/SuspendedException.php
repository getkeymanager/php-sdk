<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Exceptions;

/**
 * Suspended Exception - License is suspended
 * 
 * @package GetKeyManager\SDK\Exceptions
 */
class SuspendedException extends LicenseStatusException
{
    public const ERROR_LICENSE_SUSPENDED = 'LICENSE_SUSPENDED';
}
