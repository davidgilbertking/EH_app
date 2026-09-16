<?php

namespace App\Lighting;

interface LightingExecutor
{
    public function recover(): void;

    public function tick(?int $now = null): bool;
}
