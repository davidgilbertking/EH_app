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

        $rng = new YellowSignRng($seed);
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        $yellow = imagecolorallocate($img, 242, 201, 76);
        imagesetthickness($img, max(2, (int) round($size / 16)));

        $scale = $size / 64;
        $cx = 32 + ($rng->f() - 0.5) * 4;
        $topY = 6 + $rng->f() * 6;
        $splitY = 24 + $rng->f() * 8;
        $waistY = 35 + $rng->f() * 7;
        $bottomY = 56 - $rng->f() * 4;

        $leftX = 10 + $rng->f() * 9;
        $rightX = 46 + $rng->f() * 9;
        $armY = 17 + $rng->f() * 8;
        $hookY = 45 + $rng->f() * 8;

        $draw = function (array $p0, array $c1, array $c2, array $p3) use ($img, $yellow, $scale): void {
            $steps = 26;
            $prev = $p0;
            for ($i = 1; $i <= $steps; $i++) {
                $t = $i / $steps;
                $x = (1 - $t) ** 3 * $p0[0]
                    + 3 * (1 - $t) ** 2 * $t * $c1[0]
                    + 3 * (1 - $t) * $t ** 2 * $c2[0]
                    + $t ** 3 * $p3[0];
                $y = (1 - $t) ** 3 * $p0[1]
                    + 3 * (1 - $t) ** 2 * $t * $c1[1]
                    + 3 * (1 - $t) * $t ** 2 * $c2[1]
                    + $t ** 3 * $p3[1];

                imageline(
                    $img,
                    (int) round($prev[0] * $scale),
                    (int) round($prev[1] * $scale),
                    (int) round($x * $scale),
                    (int) round($y * $scale),
                    $yellow
                );
                $prev = [$x, $y];
            }
        };

        $draw(
            [$cx, $topY],
            [$cx - 8 - $rng->f() * 4, $topY + 8 + $rng->f() * 5],
            [$cx + 8 + $rng->f() * 4, $splitY - $rng->f() * 5],
            [$cx, $bottomY]
        );

        $draw(
            [$leftX, $armY],
            [$cx - 10 - $rng->f() * 3, $armY - 5 - $rng->f() * 4],
            [$cx - 3, $waistY - 2],
            [$cx, $waistY]
        );

        $draw(
            [$rightX, $armY + ($rng->f() - 0.5) * 2],
            [$cx + 10 + $rng->f() * 3, $armY - 4 - $rng->f() * 4],
            [$cx + 3, $waistY + 2],
            [$cx, $waistY]
        );

        $draw(
            [$cx - 9 - $rng->f() * 4, $hookY],
            [$cx - 2, $hookY + 9 + $rng->f() * 3],
            [$cx + 8 + $rng->f() * 4, $hookY + 7 + $rng->f() * 3],
            [$cx + 13 + $rng->f() * 4, $hookY - 2 - $rng->f() * 4]
        );

        if ($rng->f() > 0.45) {
            imageline(
                $img,
                (int) round(($cx - 3 - $rng->f() * 4) * $scale),
                (int) round(($topY + 4 + $rng->f() * 5) * $scale),
                (int) round(($cx + 10 + $rng->f() * 6) * $scale),
                (int) round(($topY + $rng->f() * 5) * $scale),
                $yellow
            );
        }

        if ($rng->f() > 0.6) {
            $draw(
                [$cx - 2 - $rng->f() * 3, $waistY - 11 - $rng->f() * 3],
                [$cx + 1 + $rng->f() * 4, $waistY - 16 - $rng->f() * 4],
                [$cx + 9 + $rng->f() * 4, $waistY - 12 - $rng->f() * 3],
                [$cx + 7 + $rng->f() * 3, $waistY - 6 - $rng->f() * 2]
            );
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

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
}
