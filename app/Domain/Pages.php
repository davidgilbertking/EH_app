<?php

namespace App\Domain;

use App\Models\SoundFolder;

/**
 * Single manifest of all pages and buttons in the app.
 * Used by:
 *  - SoundFolderSeeder to ensure every folder slug exists in DB
 *  - Page controllers to send button configs to Vue (via Inertia)
 *
 * Button shape:
 *  type:    'play' | 'nav' | 'special'
 *  label:   visible string
 *  folder:  audio folder slug (for type=play)
 *  mode:    optional override 'random_pos_fade' | 'from_start_no_fade'
 *  to:      route name (for type=nav)
 *  variant: optional color hint ('action'|'combat'|'mythos'|'contacts'|'special'|'default')
 *
 * Page shape (returned by config()):
 *  title:        page heading shown below header
 *  parentTitle:  optional ancestor title shown as breadcrumb prefix (e.g. "Encounters - General")
 *  layout:       'grid' | 'map' | 'list'
 *  cols:         number of columns when layout=grid
 *  rows:         array of rows, each row = array of buttons (when layout=grid)
 *  background:   image path for layout=map
 *  hotspots:     array of buttons with x,y,w,h percentages (when layout=map)
 */
class Pages
{
    public const MODE_RANDOM = SoundFolder::MODE_RANDOM_POS_FADE;
    public const MODE_FROM_START = SoundFolder::MODE_FROM_START_NO_FADE;

    /** All folder slugs that must exist with their default mode and display name. */
    public static function allFolders(): array
    {
        $folders = [];

        // header
        $folders += self::row([
            ['action', 'Action'],
            ['action-muted', 'Muted Action'],
            ['combat', 'Combat'],
            ['combat-epic', 'Epic Combat'],
            ['mythos', 'Mythos'],
        ], self::MODE_RANDOM);

        // contacts/general/city  — one folder per black city-space
        foreach (self::generalCityButtons() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }

        // contacts/general/sea    — one folder per blue sea-space
        foreach (self::generalSeaButtons() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }

        // contacts/general/wilderness
        foreach (self::wildernessButtons() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }

        // contacts/obstruction
        $folders += self::row([
            ['contacts/obstruction/detained', 'Detained'],
            ['contacts/obstruction/lost', 'Lost in Time and Space'],
        ], self::MODE_RANDOM);

        // contacts/big-city (also reused by special/disaster/city with mode override)
        foreach (self::bigCityButtons() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }

        // contacts/other-world leaves
        foreach (self::otherWorldLeaves() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }

        // contacts/other-world/past + future (8 each, real phrases)
        foreach (self::pastLabels() as $i => $label) {
            $folders['contacts/other-world/past/'.($i + 1)] = [
                'name' => $label,
                'mode' => self::MODE_RANDOM,
            ];
        }
        foreach (self::futureLabels() as $i => $label) {
            $folders['contacts/other-world/future/'.($i + 1)] = [
                'name' => $label,
                'mode' => self::MODE_RANDOM,
            ];
        }

        // contacts/defeated
        $folders += self::row([
            ['contacts/defeated/sanity', 'Sanity'],
            ['contacts/defeated/health', 'Health'],
        ], self::MODE_RANDOM);

        // contacts/expedition
        $folders['contacts/expedition/ruins'] = ['name' => 'Mystic Ruins', 'mode' => self::MODE_RANDOM];
        $folders += self::row([
            ['contacts/expedition/expeditions/hot', 'Hot'],
            ['contacts/expedition/expeditions/cold', 'Cold'],
            ['contacts/expedition/expeditions/city', 'City'],
        ], self::MODE_RANDOM);

        // contacts/devastation
        $folders['contacts/devastation'] = ['name' => 'Devastation', 'mode' => self::MODE_RANDOM];

        // contacts/antarctica, egypt, dreamlands (Side Boards)
        foreach (self::antarcticaLocations() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }
        foreach (self::egyptLocations() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }
        foreach (self::dreamlandsLocations() as [$slug, $label]) {
            $folders[$slug] = ['name' => $label, 'mode' => self::MODE_RANDOM];
        }

        // special tree (mode = from_start_no_fade)
        $folders += self::row([
            ['special/defeat', 'Defeat'],
            ['special/victory', 'Victory'],
            ['special/awakening', 'Awakening'],
            ['special/death-sanity', 'Death (Sanity)'],
            ['special/death-health', 'Death (Health)'],
            ['special/death-sacrifice', 'Sacrifice'],
            ['special/devoured', 'Devoured'],
            ['special/honey-pie', 'Honey Pie'],
        ], self::MODE_FROM_START);

        $folders += self::row([
            ['special/disaster/weather/waterspouts', 'Waterspouts'],
            ['special/disaster/weather/polar-vortex', 'Polar Vortex'],
            ['special/disaster/weather/frozen-rails', 'Frozen Rails'],
            ['special/disaster/location/meteor-showers', 'Meteor Showers'],
            ['special/disaster/location/upheaval', 'Upheaval'],
            ['special/disaster/location/destructive-cyclone', 'Destructive Cyclone'],
            ['special/disaster/location/otherworldly-rifts', 'Otherworldly Rifts'],
        ], self::MODE_FROM_START);

        // special/disaster/city/* — disaster-specific tracks per big city.
        // Reuses the main board for picking, but tracks are separate from Encounters › Named Cities.
        foreach (self::bigCityButtons() as [$slug, $label]) {
            $citySlug = str_replace('contacts/big-city/', '', $slug);
            $folders["special/disaster/city/$citySlug"] = [
                'name' => "Disaster — $label",
                'mode' => self::MODE_FROM_START,
            ];
        }

        // per-character folders (one per investigator).
        // Exception: Patrice Hathaway plays by general rules (random pos + fade-in).
        foreach (self::investigators() as [$slug, $name]) {
            $folders["special/characters/$slug"] = [
                'name' => $name,
                'mode' => $slug === 'patrice-hathaway'
                    ? self::MODE_RANDOM
                    : self::MODE_FROM_START,
            ];
        }

        // per-ancient-one folder (used by Encounters -> Ancient One)
        foreach (self::ancientOnes() as [$slug, $name]) {
            $folders["ancient/$slug"] = [
                'name' => $name,
                'mode' => self::MODE_RANDOM,
            ];
        }

        return $folders;
    }

    private static function row(array $pairs, string $mode): array
    {
        $out = [];
        foreach ($pairs as [$slug, $label]) {
            $out[$slug] = ['name' => $label, 'mode' => $mode];
        }
        return $out;
    }

    /** 8 phrases shown on Past contacts screen (order matches past/1..past/8). */
    public static function pastLabels(): array
    {
        return [
            'Сквозь щели в дверях чулана вы видите самого себя в детстве, сидящего в постели.',
            'Вы стоите перед старинным научным оборудованием.',
            'Среди барханов вы замечаете мужчину, похожего на безумца.',
            'Вы оказываетесь в Провиденсе, штат Род-Айленд, более шестидесяти лет назад.',
            'Вы оказались на континенте Му в его последний день.',
            'По другую сторону портала вы оказываетесь в пустом городе с огромными примитивными зданиями из зеленого камня.',
            'Вы не можете поверить, когда видите более молодого себя, участвующего в ритуале культа, открывшего этот портал.',
            'Жители Аркхэма бродят по улицам, охотясь на ведьм.',
        ];
    }

    /** 8 phrases shown on Future contacts screen (order matches future/1..future/8). */
    public static function futureLabels(): array
    {
        return [
            'Вы стоите на Таймс-сквер в Нью-Йорке, но не узнаете площадь.',
            'Вы оказываетесь в знакомом, но лишённом электричества городе.',
            'Вокруг вас расстилается незнакомая местность со следами пожара.',
            'Вы попали в недалёкое будущее, где вас держат в карантине вместе с сотнями жертв эпидемии.',
            'Вы переходите вброд бесконечную реку времени.',
            'Это далёкое будущее очень похоже на утраченные древние цивилизации, где обитали волшебники и идолопоклонники.',
            'Вы оказываетесь в эпохе технологических чудес.',
            'Будущий вы лежите в затхлом гостиничном номере, бессвязно бормоча и медленно умирая от жестоких ран.',
        ];
    }

    public static function generalCityButtons(): array
    {
        return [
            ['contacts/general/city/space-1',  'Space 1'],
            ['contacts/general/city/space-5',  'Space 5'],
            ['contacts/general/city/space-6',  'Space 6'],
            ['contacts/general/city/space-7',  'Space 7'],
            ['contacts/general/city/space-14', 'Space 14'],
            ['contacts/general/city/space-15', 'Space 15'],
            ['contacts/general/city/space-16', 'Space 16'],
            ['contacts/general/city/space-17', 'Space 17'],
            ['contacts/general/city/space-20', 'Space 20'],
        ];
    }

    public static function generalSeaButtons(): array
    {
        return [
            ['contacts/general/sea/space-2',   'Space 2'],
            ['contacts/general/sea/space-3',   'Space 3'],
            ['contacts/general/sea/space-8',   'Space 8'],
            ['contacts/general/sea/space-11',  'Space 11'],
            ['contacts/general/sea/space-12',  'Space 12'],
            ['contacts/general/sea/space-13',  'Space 13'],
            ['contacts/general/sea/space-18',  'Space 18'],
            ['contacts/general/sea/antarctica', 'Antarctica'],
        ];
    }

    public static function wildernessButtons(): array
    {
        return [
            ['contacts/general/wilderness/space-4', 'Space 4'],
            ['contacts/general/wilderness/space-9', 'Space 9'],
            ['contacts/general/wilderness/amazon', 'The Amazon'],
            ['contacts/general/wilderness/space-10', 'Space 10'],
            ['contacts/general/wilderness/pyramids', 'The Pyramids'],
            ['contacts/general/wilderness/heart-of-africa', 'The Heart of Africa'],
            ['contacts/general/wilderness/tunguska', 'Tunguska'],
            ['contacts/general/wilderness/space-19', 'Space 19'],
            ['contacts/general/wilderness/himalayas', 'The Himalayas'],
            ['contacts/general/wilderness/space-21', 'Space 21'],
        ];
    }

    public static function bigCityButtons(): array
    {
        return [
            ['contacts/big-city/san-francisco', 'San Francisco'],
            ['contacts/big-city/london', 'London'],
            ['contacts/big-city/shanghai', 'Shanghai'],
            ['contacts/big-city/arkham', 'Arkham'],
            ['contacts/big-city/rome', 'Rome'],
            ['contacts/big-city/tokyo', 'Tokyo'],
            ['contacts/big-city/buenos-aires', 'Buenos Aires'],
            ['contacts/big-city/istanbul', 'Istanbul'],
            ['contacts/big-city/sydney', 'Sydney'],
        ];
    }

    public static function otherWorldLeaves(): array
    {
        return [
            ['contacts/other-world/carcosa', 'Carcosa'],
            ['contacts/other-world/great-race', 'Great Race'],
            ['contacts/other-world/yuggoth', 'Yuggoth'],
            ['contacts/other-world/celaeno', 'Celaeno'],
            ['contacts/other-world/leng', 'Leng'],
            ['contacts/other-world/dreamlands', 'Dreamlands'],
            ['contacts/other-world/kadath', 'Kadath'],
            ['contacts/other-world/underworld', 'Underworld'],
            ['contacts/other-world/abyss', 'Abyss'],
        ];
    }

    public static function antarcticaLocations(): array
    {
        return [
            ['contacts/antarctica/frozen-waste', 'Frozen Waste'],
            ['contacts/antarctica/lake-camp', 'Lake Camp'],
            ['contacts/antarctica/miskatonic-outpost', 'Miskatonic Outpost'],
            ['contacts/antarctica/city-of-elder-things', 'City of the Elder Things'],
            ['contacts/antarctica/plateau-of-leng', 'Plateau of Leng'],
            ['contacts/antarctica/snowy-mountains', 'Snowy Mountains'],
        ];
    }

    public static function egyptLocations(): array
    {
        return [
            ['contacts/egypt/alexandria', 'Alexandria'],
            ['contacts/egypt/bent-pyramid', 'The Bent Pyramid'],
            ['contacts/egypt/cairo', 'Cairo'],
            ['contacts/egypt/sahara-desert', 'The Sahara Desert'],
            ['contacts/egypt/tel-el-amarna', 'Tel el-Amarna'],
            ['contacts/egypt/nile-river', 'The Nile River'],
        ];
    }

    public static function dreamlandsLocations(): array
    {
        return [
            ['contacts/dreamlands/unknown-kadath', 'Unknown Kadath'],
            ['contacts/dreamlands/enchanted-wood', 'The Enchanted Wood'],
            ['contacts/dreamlands/celephais', 'Celephaïs'],
            ['contacts/dreamlands/ulthar', 'Ulthar'],
            ['contacts/dreamlands/dylath-leen', 'Dylath-Leen'],
            ['contacts/dreamlands/underworld', 'The Underworld'],
            ['contacts/dreamlands/moon', 'The Moon'],
        ];
    }

    /**
     * Investigators sorted alphabetically.
     * Returned as [slug, name, gender] where gender is 'M' or 'F'.
     */
    public static function investigators(): array
    {
        // [name, gender]
        $rows = [
            ['Agatha Crane', 'F'],     ['Agnes Baker', 'F'],     ['Akachi Onyele', 'F'],   ['Amanda Sharpe', 'F'],
            ['Ashcan Pete', 'M'],      ['Becky Bishop', 'F'],    ['Bob Jenkins', 'M'],     ['Calvin Wright', 'M'],
            ['Carolyn Fern', 'F'],     ['Carson Sinclair', 'M'], ['Charlie Kane', 'M'],    ['Daisy Walker', 'F'],
            ['Daniela Reyes', 'F'],    ['Darrell Simmons', 'M'], ['Dexter Drake', 'M'],    ['Diana Stanley', 'F'],
            ['Father Mateo', 'M'],     ['Finn Edwards', 'M'],    ['George Barnaby', 'M'],  ['Gloria Goldberg', 'F'],
            ['Hank Samson', 'M'],      ['Harvey Walters', 'M'],  ['Jack Waters', 'M'],     ['Jacqueline Fine', 'F'],
            ['Jenny Barnes', 'F'],     ['Jim Culver', 'M'],      ['Joe Diamond', 'M'],     ['Kate Winthrop', 'F'],
            ['Leo Anderson', 'M'],     ['Lily Chen', 'F'],       ['Lola Hayes', 'F'],      ['Luke Robinson', 'M'],
            ['Mandy Thompson', 'F'],   ['Marie Lambeau', 'F'],   ['Mark Harrigan', 'M'],   ['Michael McGlen', 'M'],
            ['Minh Thi Phan', 'F'],    ['Monterey Jack', 'M'],   ['Norman Withers', 'M'],  ['Patrice Hathaway', 'F'],
            ['Preston Fairmont', 'M'], ['Rex Murphy', 'M'],      ['Rita Young', 'F'],      ['Roland Banks', 'M'],
            ['Sefina Rousseau', 'F'],  ['Silas Marsh', 'M'],     ['Simon Fishbourne', 'M'],['Sister Mary', 'F'],
            ['Skids O\'Toole', 'M'],   ['Tommy Muldoon', 'M'],   ['Tony Morgan', 'M'],     ['Trish Scarborough', 'F'],
            ['Ursula Downs', 'F'],     ['Vincent Lee', 'M'],     ['Wendy Adams', 'F'],     ['William Yorick', 'M'],
            ['Wilson Richards', 'M'],  ['Zadoc Allen', 'M'],     ['Zoey Samaras', 'F'],
        ];
        usort($rows, fn ($a, $b) => strnatcasecmp($a[0], $b[0]));
        $out = [];
        foreach ($rows as [$name, $gender]) {
            $out[] = [self::slugify($name), $name, $gender];
        }
        return $out;
    }

    /** Ancient Ones sorted alphabetically. */
    public static function ancientOnes(): array
    {
        $names = [
            'Abhoth', 'Antediluvium', 'Atlach-Nacha', 'Azathoth',
            'Cthulhu', 'Dagon', 'Hastur', 'Hypnos',
            'Ithaqua', 'Nephren-Ka', 'Nyarlathotep', 'Rise of the Elder Things',
            'Shub-Niggurath', 'Shudde M\'ell', 'Syzygy', 'Yig', 'Yog-Sothoth',
        ];
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [];
        foreach ($names as $name) {
            $out[] = [self::slugify($name), $name];
        }
        return $out;
    }

    public static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace(['"', "'", '.'], '', $s);
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
        return trim($s, '-');
    }
}
