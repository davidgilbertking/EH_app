<?php

namespace Database\Seeders;

use App\Domain\Pages;
use App\Models\AncientOne;
use Illuminate\Database\Seeder;

class AncientOneSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;
        foreach (Pages::ancientOnes() as [$slug, $name]) {
            AncientOne::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'sort_order' => $sort++],
            );
        }
    }
}
