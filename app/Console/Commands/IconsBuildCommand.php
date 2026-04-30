<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('icons:build
    {--logo= : Transparent logo PNG path (default: public/icons/src/logo.png)}
    {--plate= : Plate/background PNG path (default: public/icons/src/plate.png)}
    {--logo-scale=0.72 : Logo width fraction of output canvas (0.1..0.95)}')]
#[Description('Build favicon/PWA icons from separate logo + plate PNG files.')]
class IconsBuildCommand extends Command
{
    /** @var array<string,int> */
    private array $targets = [
        'favicon-16.png' => 16,
        'favicon-32.png' => 32,
        'apple-touch-icon.png' => 180,
        'icon-192.png' => 192,
        'icon-512.png' => 512,
    ];

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('PHP GD extension not loaded. Install/enable gd first.');
            return self::FAILURE;
        }

        $logoPath = $this->resolveInputPath(
            (string) $this->option('logo'),
            public_path('icons/src/logo.png')
        );
        $platePath = $this->resolveInputPath(
            (string) $this->option('plate'),
            public_path('icons/src/plate.png')
        );

        if (! is_file($logoPath)) {
            $this->error("Logo not found: {$logoPath}");
            return self::FAILURE;
        }
        if (! is_file($platePath)) {
            $this->error("Plate not found: {$platePath}");
            return self::FAILURE;
        }

        $logoScale = (float) $this->option('logo-scale');
        if ($logoScale < 0.1 || $logoScale > 0.95) {
            $this->error('Option --logo-scale must be between 0.1 and 0.95');
            return self::FAILURE;
        }

        $logo = @imagecreatefrompng($logoPath);
        $plate = @imagecreatefrompng($platePath);
        if (! $logo) {
            $this->error("Cannot read PNG logo: {$logoPath}");
            return self::FAILURE;
        }
        if (! $plate) {
            imagedestroy($logo);
            $this->error("Cannot read PNG plate: {$platePath}");
            return self::FAILURE;
        }

        $outDir = public_path('icons');
        if (! is_dir($outDir) && ! @mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
            imagedestroy($logo);
            imagedestroy($plate);
            $this->error("Cannot create output dir: {$outDir}");
            return self::FAILURE;
        }

        foreach ($this->targets as $filename => $size) {
            $dst = imagecreatetruecolor($size, $size);
            if (! $dst) {
                continue;
            }

            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);

            // 1) Fill full square with plate (cover behavior, center-crop).
            $this->drawCover($dst, $plate, $size, $size);

            // 2) Place logo in center (contain behavior).
            $this->drawContainCentered($dst, $logo, (int) round($size * $logoScale));

            imagealphablending($dst, true);
            imagesavealpha($dst, true);

            $outPath = $outDir.'/'.$filename;
            imagepng($dst, $outPath, 9);
            imagedestroy($dst);
            $this->line("built: public/icons/{$filename} ({$size}x{$size})");
        }

        // Also refresh legacy /favicon.ico because many desktop browsers still
        // request it directly and can ignore/override PNG links when cached.
        $icoPath = public_path('favicon.ico');
        $this->buildIcoFromPngs($icoPath, [
            public_path('icons/favicon-16.png'),
            public_path('icons/favicon-32.png'),
        ]);
        $this->line('built: public/favicon.ico (16+32 PNG entries)');

        imagedestroy($logo);
        imagedestroy($plate);

        $this->info('Done. If icon did not update on phone/browser: clear website data or change icon filename.');
        return self::SUCCESS;
    }

    private function resolveInputPath(string $option, string $default): string
    {
        $option = trim($option);
        if ($option === '') return $default;
        if (str_starts_with($option, '/')) return $option;
        return base_path($option);
    }

    private function drawCover(\GdImage $dst, \GdImage $src, int $dstW, int $dstH): void
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) return;

        $scale = max($dstW / $srcW, $dstH / $srcH);
        $drawW = (int) round($srcW * $scale);
        $drawH = (int) round($srcH * $scale);
        $dstX = (int) floor(($dstW - $drawW) / 2);
        $dstY = (int) floor(($dstH - $drawH) / 2);

        imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $drawW, $drawH, $srcW, $srcH);
    }

    private function drawContainCentered(\GdImage $dst, \GdImage $src, int $maxBox): void
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $dstW = imagesx($dst);
        $dstH = imagesy($dst);
        if ($srcW <= 0 || $srcH <= 0 || $maxBox <= 0) return;

        $scale = min($maxBox / $srcW, $maxBox / $srcH);
        $drawW = max(1, (int) round($srcW * $scale));
        $drawH = max(1, (int) round($srcH * $scale));
        $dstX = (int) floor(($dstW - $drawW) / 2);
        $dstY = (int) floor(($dstH - $drawH) / 2);

        imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $drawW, $drawH, $srcW, $srcH);
    }

    /**
     * Build .ico file with PNG-compressed icon images.
     *
     * @param list<string> $pngPaths
     */
    private function buildIcoFromPngs(string $icoPath, array $pngPaths): void
    {
        $entries = [];

        foreach ($pngPaths as $path) {
            if (! is_file($path)) continue;
            $size = @getimagesize($path);
            if (! $size || ($size[2] ?? null) !== IMAGETYPE_PNG) continue;
            $w = (int) ($size[0] ?? 0);
            $h = (int) ($size[1] ?? 0);
            if ($w <= 0 || $h <= 0) continue;
            $bin = file_get_contents($path);
            if ($bin === false || $bin === '') continue;

            $entries[] = [
                'w' => $w,
                'h' => $h,
                'bin' => $bin,
                'len' => strlen($bin),
            ];
        }

        if (! count($entries)) return;

        $count = count($entries);
        $header = pack('vvv', 0, 1, $count); // reserved, type=icon, count
        $dir = '';
        $offset = 6 + ($count * 16);
        $images = '';

        foreach ($entries as $e) {
            $wByte = $e['w'] >= 256 ? 0 : $e['w'];
            $hByte = $e['h'] >= 256 ? 0 : $e['h'];
            // width, height, colorCount, reserved, planes, bitCount, size, offset
            $dir .= pack('CCCCvvVV', $wByte, $hByte, 0, 0, 1, 32, $e['len'], $offset);
            $images .= $e['bin'];
            $offset += $e['len'];
        }

        file_put_contents($icoPath, $header.$dir.$images);
    }
}
