<?php

namespace Database\Seeders;

use App\Domain\Pages;
use App\Models\Investigator;
use Illuminate\Database\Seeder;

class InvestigatorSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;
        foreach (Pages::investigators() as [$slug, $name, $gender]) {
            Investigator::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'gender' => $gender, 'sort_order' => $sort++],
            );
        }
    }
}
