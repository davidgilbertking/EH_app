<?php

namespace App\Http\Middleware;

use App\Models\AncientOne;
use App\Models\Investigator;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $state = $user?->state()->with('ancientOne')->first();
        $ancient = $state?->ancientOne;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'assetPreload' => fn () => $user ? [
                'imageUrls' => $this->imagePreloadUrls(),
            ] : null,
            'gameState' => fn () => $state ? [
                'ancientOne' => $ancient ? [
                    'id' => $ancient->id,
                    'slug' => $ancient->slug,
                    'name' => $ancient->name,
                    'imageUrl' => $ancient->imageUrl(),
                    'bgImageUrl' => $ancient->bgImageUrl(),
                ] : null,
                'blobs' => $state->blobs ?? [],
            ] : null,
        ];
    }

    /**
     * Warm browser cache for all image-heavy views right after login.
     * Includes:
     *  - 4 maps
     *  - 59 investigators (DB-driven)
     *  - 17 ancient ones + their background upscales (DB-driven)
     *  - 16 disaster icons
     *  - 11 Other World contact icons
     *
     * @return array<int, string>
     */
    private function imagePreloadUrls(): array
    {
        $static = [
            '/maps/main.jpg',
            '/maps/antarctica.jpg',
            '/maps/egypt.jpg',
            '/maps/dreamlands.jpg',

            '/images/disaster/arkham.png',
            '/images/disaster/buenos-aires.png',
            '/images/disaster/destructive-cyclone.png',
            '/images/disaster/frozen-rails.png',
            '/images/disaster/istanbul.png',
            '/images/disaster/london.png',
            '/images/disaster/meteor-showers.png',
            '/images/disaster/otherworldly-rifts.png',
            '/images/disaster/polar-vortex.png',
            '/images/disaster/rome.png',
            '/images/disaster/san-francisco.png',
            '/images/disaster/shanghai.png',
            '/images/disaster/sydney.png',
            '/images/disaster/tokyo.png',
            '/images/disaster/upheaval.png',
            '/images/disaster/waterspouts.png',

            '/images/other-worlds/abyss.png',
            '/images/other-worlds/carcosa.png',
            '/images/other-worlds/celaeno.png',
            '/images/other-worlds/dreamlands.png',
            '/images/other-worlds/future.png',
            '/images/other-worlds/great-race.png',
            '/images/other-worlds/kadath.png',
            '/images/other-worlds/leng.png',
            '/images/other-worlds/past.png',
            '/images/other-worlds/underworld.png',
            '/images/other-worlds/yuggoth.png',
        ];

        $investigators = Investigator::query()
            ->orderBy('sort_order')
            ->get(['image_path'])
            ->map(fn (Investigator $i) => $i->imageUrl());

        $ancients = AncientOne::query()
            ->orderBy('sort_order')
            ->get(['image_path', 'bg_image_path'])
            ->flatMap(fn (AncientOne $a) => [$a->imageUrl(), $a->bgImageUrl()]);

        return collect($static)
            ->concat($investigators)
            ->concat($ancients)
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
    }
}
