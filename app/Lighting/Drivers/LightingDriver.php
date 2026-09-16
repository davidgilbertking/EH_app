<?php

namespace App\Lighting\Drivers;

interface LightingDriver
{
    public function capabilities(): array;

    /** Values use UI percentages, not device DP values. Completion must be explicit. */
    public function readState(?array $lastObservation, int $now): array;

    /** Synchronous acknowledgement of one bounded step, called only by the worker. */
    public function execute(array $command, ?array $lastObservation, int $now): array;
}
