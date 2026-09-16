<?php

namespace App\Lighting\Drivers;

use RuntimeException;

/** Only allowlisted categories and numeric vendor codes may leave the client. */
class TuyaCloudException extends RuntimeException
{
    public function __construct(string $category, public readonly ?string $vendorCode = null, public readonly bool $writeOutcomeUnknown = false)
    {
        parent::__construct($category);
    }
}
