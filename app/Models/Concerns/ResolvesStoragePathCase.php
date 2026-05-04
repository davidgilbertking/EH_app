<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

trait ResolvesStoragePathCase
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $publicDiskCaseIndexCache = [];

    protected function publicStorageAsset(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return asset('storage/'.$this->resolvePublicStoragePathCase($path));
    }

    protected function resolvePublicStoragePathCase(string $path): string
    {
        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            return $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $dir = trim((string) pathinfo($normalized, PATHINFO_DIRNAME), '/');
        $dirKey = $dir === '.' ? '' : $dir;

        if (! isset(self::$publicDiskCaseIndexCache[$dirKey])) {
            $index = [];
            foreach ($disk->files($dirKey) as $file) {
                $index[strtolower($file)] = $file;
            }
            self::$publicDiskCaseIndexCache[$dirKey] = $index;
        }

        return self::$publicDiskCaseIndexCache[$dirKey][strtolower($normalized)] ?? $path;
    }
}
