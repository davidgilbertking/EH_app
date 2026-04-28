# Eldritch Horror Music Switcher

iPad-friendly web app for fast music switching during sessions of the *Eldritch
Horror* board game. Single user, dark theme, persistent audio across page
navigation, long-press to "blob" tracks onto the home page round-playlist.

## Stack

- **Laravel 13** + **Inertia.js + Vue 3** (SPA navigation: audio survives across pages)
- **SQLite** by default (single user, file-based; trivial to switch to PostgreSQL)
- **Howler.js** for cross-fade audio playback
- **Tailwind CSS** dark theme
- **PWA** manifest for install-to-home-screen on iPad

## Requirements

- PHP 8.3+ (project tested on 8.4)
- Composer 2
- Node 20+ (project pinned via `.nvmrc`)
- ~30 GB disk for audio + system

## First-time setup

```bash
nvm use                                                # Node 22 (per .nvmrc)
composer install
npm install

cp .env.example .env
php artisan key:generate

# Optional: customise login creds (otherwise admin@local / change-me)
echo 'EH_USER_EMAIL=you@example.com'      >> .env
echo 'EH_USER_PASSWORD=your-strong-pass'   >> .env

php artisan migrate
php artisan db:seed                                    # creates user + 173 sound folders + 17 ancients + 59 investigators
php artisan storage:link                               # for ancient-one images
php artisan audio:init                                 # scaffolds storage/app/private/audio/<slug>/ trees

npm run build
php artisan serve --port=8787                          # http://127.0.0.1:8787
```

The default Laravel `php artisan serve` listens on 8000 which is often busy on
dev macs (DevContainers, Docker, etc.). Pick whatever port is free, the app
itself does not care.

## Adding music

Drop audio files (`.mp3`, `.m4a`, `.ogg`, `.wav`, `.flac`) into the matching
folder under `storage/app/private/audio/<slug>/` then re-index:

```bash
php artisan audio:scan              # all folders
php artisan audio:scan --folder=mythos   # one folder
```

Each folder represents one playable button. List all available slugs:

```bash
php artisan tinker
>>> \App\Models\SoundFolder::pluck('slug')->all();
```

| Slug pattern | What plays it                                                 |
|------|---------------------------------------------------------------|
| `action`, `action-muted` | Header **Action** (tap / hold)                                |
| `combat`, `combat-epic` | Header **Combat** (tap / hold)                                |
| `mythos` | Header **Mythos**                                             |
| `contacts/*` | Pages under Encounters (Named Cities, Other World, Wilderness, …) |
| `special/*` | Pages under Other (Disaster, Death-*, Honey Pie, …)                |
| `ancient/<slug>` | Encounters → Ancient One, picked from current Ancient One         |
| `special/characters/<slug>` | Tap on an investigator on Other → Characters                 |

Run `php artisan audio:init` to (re)create empty folders for every slug.
Inspect `app/Domain/Pages.php` for the full slug catalogue.

## Adding images for Ancient Ones / Investigators

1. Drop files into `storage/app/public/ancients/` and `storage/app/public/investigators/`.
2. Filename must match the row's slug, e.g. `cthulhu.jpg`, `ashcan-pete.jpg`.
3. Run:
   ```bash
   php artisan images:attach
   ```
   This walks both folders and fills the `image_path` column for every match.
   Files not matching any slug are ignored; missing slugs are listed.
4. Run `php artisan storage:link` once if you have not yet (creates
   `public/storage` → `storage/app/public`).

Supported extensions: `jpg`, `jpeg`, `png`, `webp`.

## Audio behaviour

- **Default mode** (Encounters pages, Action/Combat/Mythos): fade-in 3 s,
  resumes from a random position in the first ~60 % of the track.
- **Special mode** (Other pages, Disaster city overrides): plays from
  position 0 with no fade-in.
- Switching tracks while one is playing always cross-fades the previous out
  over 2 s.
- **Tap** any audio button = play.
- **Long-press** (≥ 2 s) any audio button = adds it as a "blob" to the home
  page round-playlist. Header buttons have a different long-press: Action →
  Muted Action, Combat → Epic Combat, Mythos has none.

## Maps (Antarctica / Egypt / Dreamlands)

The three "Side Boards" sub-pages render absolutely-positioned hotspots over a
background image expected at `public/maps/<name>.jpg`. Drop these three files
in `public/maps/`:
- `antarctica.jpg`
- `egypt.jpg`
- `dreamlands.jpg`

Hotspot percentages live inside each Vue page
(`resources/js/Pages/Contacts/{Antarctica,Egypt,Dreamlands}.vue`) and are easy
to fine-tune in the browser.

## Inspecting the database

The app uses SQLite — a single file at `database/database.sqlite`. There are
no credentials.

To browse it from PhpStorm/DataGrip:
1. Database tool window → **`+` → Data Source → SQLite**
2. **File**: `/<absolute-path>/EH_app/database/database.sqlite`
3. PhpStorm will prompt to download the SQLite driver — accept.
4. Test Connection → Apply.

CLI alternative:
```bash
sqlite3 database/database.sqlite
> .tables
> SELECT slug, name FROM ancient_ones;
```

## iPad install

1. Visit the app URL in mobile Safari.
2. Tap **Share → Add to Home Screen**.
3. Launch from home — runs full-screen, dark, no Safari chrome.

The app holds a Wake Lock while open so the screen does not sleep mid-game.
On a desktop browser the long-press still works with the mouse: click and
hold for 2 s.

## Deploy

A cheap VPS + Caddy gives you HTTPS with one config block:

```caddy
eh.example.com {
    root * /var/www/EH_app/public
    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
    encode zstd gzip
    header /audio/* Cache-Control "private, max-age=31536000"
}
```

For local-only home use, `php artisan serve --host=0.0.0.0 --port=8787` from a
laptop on the same Wi-Fi works without any VPS or domain.

## Test

```bash
php artisan test
```

Covers:
- All 23 page routes render for an authenticated user.
- Login / logout / profile flows.
- State endpoints (set ancient one, save/clear blobs).
- Audio pick endpoint 404 when folder empty.

## Useful console commands

```bash
php artisan audio:init            # scaffold empty folders for every sound_folder
php artisan audio:scan            # index files into sound_tracks (uses getID3 for duration)
php artisan images:attach         # link Ancient One / Investigator portraits to DB rows
php artisan migrate:fresh --seed  # nuke DB + reseed (loses blobs/state)
php artisan tinker                # interactive REPL
```

## IDE / PhpStorm note

If PhpStorm shows `PHP 5.6` for this project: it just hasn't been pointed at
your installed PHP yet.

1. **Settings → Languages & Frameworks → PHP**
2. CLI Interpreter → `…` → `+ → From Local` → set the path to your PHP binary
   (on macOS Homebrew: `/opt/homebrew/bin/php`; check with `which php`).
3. PHP language level: `8.3` or `8.4`.
4. Apply.
