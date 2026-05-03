<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class YellowSignIconController extends Controller
{
    public function __invoke(Request $request)
    {
        $seed = (string) ($request->query('seed') ?: $request->session()->get('yellow_sign_seed', 'the-yellow-sign'));
        $size = (int) $request->query('size', 64);
        $size = max(16, min($size, 256));

        $png = (new YellowSignPainter($seed, $size))->render();

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}

final class YellowSignRng
{
    private int $state;

    public function __construct(string $seed)
    {
        $hash = hash('sha256', $seed, true);
        $n = unpack('N', substr($hash, 0, 4))[1] ?? 1;
        $this->state = $n > 0 ? $n : 1;
    }

    public function f(): float
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;
        return $this->state / 4294967295;
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        return $min + (int) floor($this->f() * (($max - $min) + 1));
    }

    public function chance(float $p): bool
    {
        return $this->f() < $p;
    }

    public function range(float $min, float $max): float
    {
        if ($max <= $min) {
            return $min;
        }
        return $min + ($this->f() * ($max - $min));
    }

    /**
     * @template T
     * @param array<int, T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @template T
     * @param array<int, T> $items
     * @return array<int, T>
     */
    public function shuffle(array $items): array
    {
        $last = count($items) - 1;
        for ($i = $last; $i > 0; $i--) {
            $j = $this->int(0, $i);
            if ($i === $j) {
                continue;
            }
            $tmp = $items[$i];
            $items[$i] = $items[$j];
            $items[$j] = $tmp;
        }
        return $items;
    }
}

final class YellowSignPainter
{
    private YellowSignRng $rng;
    private \GdImage $img;
    private int $strokeColor;
    private int $glowColor;
    private int $size;
    private int $workSize;
    private float $cx = 50.0;
    private float $cy = 50.0;

    public function __construct(string $seed, int $size)
    {
        $this->rng = new YellowSignRng($seed);
        $this->size = $size;
        // Oversampling produces cleaner anti-aliased edges after downscale.
        $this->workSize = min(1024, $size * 4);
    }

    public function render(): string
    {
        $this->img = imagecreatetruecolor($this->workSize, $this->workSize);
        imagealphablending($this->img, true);
        imagesavealpha($this->img, true);

        $transparent = imagecolorallocatealpha($this->img, 0, 0, 0, 127);
        imagefill($this->img, 0, 0, $transparent);

        $this->strokeColor = imagecolorallocatealpha($this->img, 242, 201, 76, 0);
        $this->glowColor = imagecolorallocatealpha($this->img, 242, 201, 76, 102);

        $symmetry = $this->rng->pick([
            'none', 'none', 'none',
            'vertical', 'horizontal',
            'radial2', 'radial4',
        ]);
        $baseRadius = $this->rng->range(0.75, 1.45);

        if ($this->rng->chance(0.28)) {
            $this->drawRing($symmetry, $baseRadius);
        }

        foreach ($this->buildPrimitiveQueue() as $kind) {
            $radius = max(0.55, $baseRadius + $this->rng->range(-0.28, 0.35));
            $this->drawPrimitive($kind, $symmetry, $radius);
        }
        $this->drawDots($symmetry, $baseRadius);

        $scaled = imagescale($this->img, $this->size, $this->size, IMG_BICUBIC_FIXED);
        if (!$scaled) {
            $scaled = imagecreatetruecolor($this->size, $this->size);
            imagealphablending($scaled, true);
            imagesavealpha($scaled, true);
            $scaledTransparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            imagefill($scaled, 0, 0, $scaledTransparent);
            imagecopyresampled($scaled, $this->img, 0, 0, 0, 0, $this->size, $this->size, $this->workSize, $this->workSize);
        }

        ob_start();
        imagepng($scaled);
        $png = (string) ob_get_clean();
        imagedestroy($scaled);
        imagedestroy($this->img);
        return $png;
    }

    /**
     * @return array<int, string>
     */
    private function buildPrimitiveQueue(): array
    {
        $kinds = ['line', 'curve', 'arc', 'chevron', 'hook'];
        $count = $this->rng->int(4, 7);
        $queue = ['line', 'curve', 'dot-carrier'];

        while (count($queue) < $count) {
            $queue[] = $this->rng->pick($kinds);
        }

        return $this->rng->shuffle($queue);
    }

    private function drawPrimitive(string $kind, string $symmetry, float $radius): void
    {
        if ($kind === 'dot-carrier') {
            $base = [$this->randomPolarPoint(0, 20), $this->randomPolarPoint(16, 40)];
            $this->strokeWithSymmetry($base, $symmetry, $radius);
            return;
        }

        if ($kind === 'line') {
            $points = [$this->randomPolarPoint(8, 44), $this->randomPolarPoint(10, 46)];
            $this->strokeWithSymmetry($points, $symmetry, $radius);
            return;
        }

        if ($kind === 'chevron') {
            $pivot = $this->randomPolarPoint(2, 26);
            $baseAngle = $this->rng->range(0, 2 * M_PI);
            $spread = deg2rad($this->rng->range(25, 110));
            $armA = $this->pointFrom($pivot, $baseAngle - $spread, $this->rng->range(8, 24));
            $armB = $this->pointFrom($pivot, $baseAngle + $spread, $this->rng->range(8, 24));
            $this->strokeWithSymmetry([$armA, $pivot, $armB], $symmetry, $radius);
            return;
        }

        if ($kind === 'arc') {
            $center = [
                $this->cx + $this->rng->range(-15, 15),
                $this->cy + $this->rng->range(-15, 15),
            ];
            $arc = $this->sampleArc(
                $center,
                $this->rng->range(8, 33),
                $this->rng->range(8, 33),
                $this->rng->range(0, 2 * M_PI),
                deg2rad($this->rng->range(35, 240))
            );
            $this->strokeWithSymmetry($arc, $symmetry, $radius);
            return;
        }

        if ($kind === 'hook') {
            $start = $this->randomPolarPoint(10, 42);
            $mid = $this->pointFrom($start, $this->rng->range(0, 2 * M_PI), $this->rng->range(8, 20));
            $bend = $this->pointFrom($mid, $this->rng->range(0, 2 * M_PI), $this->rng->range(5, 13));
            $tail = $this->sampleArc(
                $bend,
                $this->rng->range(5, 16),
                $this->rng->range(4, 14),
                $this->rng->range(0, 2 * M_PI),
                deg2rad($this->rng->range(65, 170))
            );
            $poly = [$start, $mid, $bend];
            $this->strokeWithSymmetry(array_merge($poly, $tail), $symmetry, $radius);
            return;
        }

        // curve
        $p0 = $this->randomPolarPoint(9, 42);
        $p3 = $this->randomPolarPoint(9, 42);
        $c1 = $this->pointFrom($p0, $this->rng->range(0, 2 * M_PI), $this->rng->range(8, 24));
        $c2 = $this->pointFrom($p3, $this->rng->range(0, 2 * M_PI), $this->rng->range(8, 24));
        $curve = $this->sampleBezier($p0, $c1, $c2, $p3);
        $this->strokeWithSymmetry($curve, $symmetry, $radius);
    }

    private function drawRing(string $symmetry, float $baseRadius): void
    {
        $center = [
            $this->cx + $this->rng->range(-6, 6),
            $this->cy + $this->rng->range(-6, 6),
        ];
        $ring = $this->sampleArc(
            $center,
            $this->rng->range(24, 45),
            $this->rng->range(24, 45),
            $this->rng->range(0, 2 * M_PI),
            deg2rad($this->rng->chance(0.52) ? $this->rng->range(175, 330) : $this->rng->range(70, 150))
        );
        $this->strokeWithSymmetry($ring, $symmetry, max(0.5, $baseRadius - 0.25));
    }

    private function drawDots(string $symmetry, float $baseRadius): void
    {
        $dotCount = $this->rng->int(1, 4);
        for ($i = 0; $i < $dotCount; $i++) {
            $p = $this->randomPolarPoint(8, 36);
            $r = max(0.75, $baseRadius * $this->rng->range(1.0, 1.65));
            $this->drawDiscNormalized($p[0], $p[1], $r * 1.15, $this->glowColor);
            $this->drawDiscNormalized($p[0], $p[1], $r, $this->strokeColor);

            if ($symmetry !== 'none' && $this->rng->chance(0.82)) {
                foreach ($this->pointTransforms($p, $symmetry) as $variant) {
                    $this->drawDiscNormalized($variant[0], $variant[1], $r * 1.15, $this->glowColor);
                    $this->drawDiscNormalized($variant[0], $variant[1], $r, $this->strokeColor);
                }
            }
        }
    }

    private function strokeWithSymmetry(array $points, string $symmetry, float $radius): void
    {
        $this->strokePolyline($points, $radius);

        if ($symmetry === 'none' || !$this->rng->chance(0.74)) {
            return;
        }

        foreach ($this->polylineTransforms($points, $symmetry) as $variant) {
            $this->strokePolyline($variant, $radius);
        }
    }

    private function strokePolyline(array $points, float $radius): void
    {
        $count = count($points);
        if ($count < 2) {
            return;
        }

        $radiusPx = max(1.0, $this->normToPx($radius));
        $glowPx = $radiusPx + max(0.8, $radiusPx * 0.45);

        for ($i = 1; $i < $count; $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $dx = $b[0] - $a[0];
            $dy = $b[1] - $a[1];
            $len = hypot($dx, $dy);
            $steps = max(1, (int) ceil($len / 0.55));

            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / $steps;
                $x = $a[0] + ($dx * $t);
                $y = $a[1] + ($dy * $t);
                $this->drawDiscNormalized($x, $y, $glowPx, $this->glowColor);
                $this->drawDiscNormalized($x, $y, $radiusPx, $this->strokeColor);
            }
        }
    }

    private function drawDiscNormalized(float $x, float $y, float $radiusPx, int $color): void
    {
        $diameter = max(2, (int) round($radiusPx * 2));
        imagefilledellipse(
            $this->img,
            $this->sx($x),
            $this->sy($y),
            $diameter,
            $diameter,
            $color
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function randomPolarPoint(float $rMin, float $rMax): array
    {
        $radius = $this->rng->range($rMin, $rMax);
        $angle = $this->rng->range(0, 2 * M_PI);
        return $this->clampPoint([
            $this->cx + cos($angle) * $radius,
            $this->cy + sin($angle) * $radius,
        ]);
    }

    /**
     * @param array{0: float, 1: float} $origin
     * @return array{0: float, 1: float}
     */
    private function pointFrom(array $origin, float $angle, float $len): array
    {
        return $this->clampPoint([
            $origin[0] + cos($angle) * $len,
            $origin[1] + sin($angle) * $len,
        ]);
    }

    /**
     * @param array{0: float, 1: float} $center
     * @return array<int, array{0: float, 1: float}>
     */
    private function sampleArc(array $center, float $rx, float $ry, float $start, float $sweep): array
    {
        $samples = max(9, (int) ceil(abs($sweep) * 11));
        $points = [];

        for ($i = 0; $i <= $samples; $i++) {
            $t = $i / $samples;
            $a = $start + ($sweep * $t);
            $points[] = $this->clampPoint([
                $center[0] + cos($a) * $rx,
                $center[1] + sin($a) * $ry,
            ]);
        }

        return $points;
    }

    /**
     * @param array{0: float, 1: float} $p0
     * @param array{0: float, 1: float} $c1
     * @param array{0: float, 1: float} $c2
     * @param array{0: float, 1: float} $p3
     * @return array<int, array{0: float, 1: float}>
     */
    private function sampleBezier(array $p0, array $c1, array $c2, array $p3): array
    {
        $samples = 28;
        $points = [];

        for ($i = 0; $i <= $samples; $i++) {
            $t = $i / $samples;
            $x = ((1 - $t) ** 3 * $p0[0])
                + (3 * (1 - $t) ** 2 * $t * $c1[0])
                + (3 * (1 - $t) * ($t ** 2) * $c2[0])
                + (($t ** 3) * $p3[0]);
            $y = ((1 - $t) ** 3 * $p0[1])
                + (3 * (1 - $t) ** 2 * $t * $c1[1])
                + (3 * (1 - $t) * ($t ** 2) * $c2[1])
                + (($t ** 3) * $p3[1]);
            $points[] = $this->clampPoint([$x, $y]);
        }

        return $points;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $points
     * @return array<int, array<int, array{0: float, 1: float}>>
     */
    private function polylineTransforms(array $points, string $symmetry): array
    {
        $variants = [];

        if ($symmetry === 'vertical') {
            $variants[] = array_map(fn (array $p) => [($this->cx * 2) - $p[0], $p[1]], $points);
            return $variants;
        }

        if ($symmetry === 'horizontal') {
            $variants[] = array_map(fn (array $p) => [$p[0], ($this->cy * 2) - $p[1]], $points);
            return $variants;
        }

        if ($symmetry === 'radial2') {
            $variants[] = array_map(fn (array $p) => [($this->cx * 2) - $p[0], ($this->cy * 2) - $p[1]], $points);
            return $variants;
        }

        if ($symmetry === 'radial4') {
            foreach ([M_PI_2, M_PI, 3 * M_PI_2] as $angle) {
                if ($this->rng->chance(0.88)) {
                    $variants[] = array_map(fn (array $p) => $this->rotatePoint($p, $angle), $points);
                }
            }
        }

        return $variants;
    }

    /**
     * @param array{0: float, 1: float} $point
     * @return array<int, array{0: float, 1: float}>
     */
    private function pointTransforms(array $point, string $symmetry): array
    {
        if ($symmetry === 'vertical') {
            return [[($this->cx * 2) - $point[0], $point[1]]];
        }
        if ($symmetry === 'horizontal') {
            return [[$point[0], ($this->cy * 2) - $point[1]]];
        }
        if ($symmetry === 'radial2') {
            return [[($this->cx * 2) - $point[0], ($this->cy * 2) - $point[1]]];
        }
        if ($symmetry === 'radial4') {
            return [
                $this->rotatePoint($point, M_PI_2),
                $this->rotatePoint($point, M_PI),
                $this->rotatePoint($point, 3 * M_PI_2),
            ];
        }
        return [];
    }

    /**
     * @param array{0: float, 1: float} $p
     * @return array{0: float, 1: float}
     */
    private function rotatePoint(array $p, float $angle): array
    {
        $dx = $p[0] - $this->cx;
        $dy = $p[1] - $this->cy;
        $x = $this->cx + ($dx * cos($angle)) - ($dy * sin($angle));
        $y = $this->cy + ($dx * sin($angle)) + ($dy * cos($angle));
        return $this->clampPoint([$x, $y]);
    }

    private function normToPx(float $v): float
    {
        return ($v / 100.0) * $this->workSize;
    }

    private function sx(float $x): int
    {
        return (int) round(($x / 100.0) * $this->workSize);
    }

    private function sy(float $y): int
    {
        return (int) round(($y / 100.0) * $this->workSize);
    }

    /**
     * @param array{0: float, 1: float} $p
     * @return array{0: float, 1: float}
     */
    private function clampPoint(array $p): array
    {
        return [
            max(3.0, min(97.0, $p[0])),
            max(3.0, min(97.0, $p[1])),
        ];
    }
}
