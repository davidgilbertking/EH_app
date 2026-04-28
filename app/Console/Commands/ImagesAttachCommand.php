<?php

namespace App\Console\Commands;

use App\Models\AncientOne;
use App\Models\Investigator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('images:attach')]
#[Description('Match files in storage/app/public/{ancients,investigators}/ to DB rows by slug and fill image_path.')]
class ImagesAttachCommand extends Command
{
    public function handle(): int
    {
        $updated = 0;
        $missing = [];

        foreach ([
            ['ancients',      AncientOne::class],
            ['investigators', Investigator::class],
        ] as [$dir, $modelClass]) {
            foreach ($modelClass::orderBy('sort_order')->get() as $row) {
                $match = $this->findFile($dir, $row->slug);
                if ($match) {
                    if ($row->image_path !== $match) {
                        $row->image_path = $match;
                        $row->save();
                        $updated++;
                        $this->line("  + {$row->slug} → {$match}");
                    }
                } else {
                    $missing[] = "$dir/{$row->slug}.*";
                }

                // Ancient ones have an extra upscaled background image in
                // ancients/bg/{slug}.* — pick that up too if present.
                if ($modelClass === AncientOne::class) {
                    $bg = $this->findFile("$dir/bg", $row->slug);
                    if ($bg && $row->bg_image_path !== $bg) {
                        $row->bg_image_path = $bg;
                        $row->save();
                        $updated++;
                        $this->line("  + {$row->slug} (bg) → {$bg}");
                    }
                }
            }
        }

        $this->info("Done. Updated $updated rows.");
        if ($missing) {
            $this->warn(count($missing).' slugs have no matching image file:');
            foreach (array_slice($missing, 0, 20) as $m) {
                $this->line("  - $m");
            }
            if (count($missing) > 20) {
                $this->line('  …'.(count($missing) - 20).' more');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Look for public/<dir>/<slug>.{jpg,jpeg,png,webp} (case-insensitive).
     * Returns the relative path from the public disk root if found, null otherwise.
     */
    private function findFile(string $dir, string $slug): ?string
    {
        $disk = Storage::disk('public');
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $rel = "$dir/$slug.$ext";
            if ($disk->exists($rel)) {
                return $rel;
            }
        }
        return null;
    }
}
