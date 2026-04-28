<?php

namespace App\Http\Controllers;

use App\Models\AncientOne;
use App\Models\Investigator;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Home');
    }

    // ---- Contacts tree ----
    public function contacts(): Response
    {
        return Inertia::render('Contacts/Index');
    }

    public function general(): Response
    {
        return Inertia::render('Contacts/General');
    }

    public function wilderness(): Response
    {
        return Inertia::render('Contacts/Wilderness');
    }

    public function generalCity(): Response
    {
        return Inertia::render('Contacts/GeneralCity');
    }

    public function generalSea(): Response
    {
        return Inertia::render('Contacts/GeneralSea');
    }

    public function obstruction(): Response
    {
        return Inertia::render('Contacts/Obstruction');
    }

    public function bigCity(): Response
    {
        return Inertia::render('Contacts/BigCity');
    }

    public function outerWorld(): Response
    {
        return Inertia::render('Contacts/OuterWorld');
    }

    public function past(): Response
    {
        return Inertia::render('Contacts/Past');
    }

    public function future(): Response
    {
        return Inertia::render('Contacts/Future');
    }

    public function defeated(): Response
    {
        return Inertia::render('Contacts/Defeated');
    }

    public function expedition(): Response
    {
        return Inertia::render('Contacts/Expedition');
    }

    public function expeditions(): Response
    {
        return Inertia::render('Contacts/Expeditions');
    }

    public function addMap(): Response
    {
        return Inertia::render('Contacts/AddMap');
    }

    public function antarctica(): Response
    {
        return Inertia::render('Contacts/Antarctica');
    }

    public function egypt(): Response
    {
        return Inertia::render('Contacts/Egypt');
    }

    public function dreamlands(): Response
    {
        return Inertia::render('Contacts/Dreamlands');
    }

    // ---- Special tree ----
    public function special(): Response
    {
        return Inertia::render('Special/Index');
    }

    public function disaster(): Response
    {
        return Inertia::render('Special/Disaster');
    }

    public function disasterCity(): Response
    {
        return Inertia::render('Special/DisasterCity');
    }

    public function disasterWeather(): Response
    {
        return Inertia::render('Special/DisasterWeather');
    }

    public function disasterLocation(): Response
    {
        return Inertia::render('Special/DisasterLocation');
    }

    public function investigators(): Response
    {
        $all = Investigator::orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'gender', 'image_path']);

        $shape = fn ($i) => [
            'id' => $i->id,
            'slug' => $i->slug,
            'name' => $i->name,
            'imageUrl' => $i->imageUrl(),
            'folderSlug' => "special/characters/{$i->slug}",
        ];

        return Inertia::render('Special/Investigators', [
            'men'   => $all->where('gender', 'M')->values()->map($shape)->all(),
            'women' => $all->where('gender', 'F')->values()->map($shape)->all(),
        ]);
    }

    public function ancientOnes(): Response
    {
        $items = AncientOne::orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'image_path'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'slug' => $a->slug,
                'name' => $a->name,
                'imageUrl' => $a->imageUrl(),
            ])
            ->all();

        return Inertia::render('Special/AncientOnes', [
            'items' => $items,
        ]);
    }
}
