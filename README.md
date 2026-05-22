# Photobook

Self-hosted photobook generation for private photo libraries.

Photobook turns a Nextcloud/WebDAV photo folder into an editable photobook and exports a print-ready PDF. It is built for people who want to keep family photos on their own server instead of uploading them to a third-party photo-book service.

## What it does

- Imports photos from a Nextcloud/WebDAV folder
- Groups photos into pages automatically
- Chooses page layouts automatically
- Lets you edit pages in a browser
- Lets you replace photos, change templates, and adjust crops
- Saves manual overrides so rebuilds do not destroy your edits
- Exports a PDF with Playwright
- Supports print bleed, crop marks, and PDF trim/bleed boxes
- Keeps source photos on your own infrastructure

## Current status

This is an early `v0.1` self-hosted release.

It is useful, but not yet polished like a commercial product. Expect rough edges around setup, print-provider compatibility, and larger photo libraries.

## Architecture

```txt
Laravel backend
├── Nextcloud/WebDAV import
├── image probing and caching
├── layout planning
├── background build jobs
├── settings and page persistence
└── Playwright PDF export

React editor
├── album selection
├── page editor
├── cover editor
├── photo replacement
├── layout/template changes
└── PDF export trigger
```

## Requirements

You need:

- PHP 8.3+
- Composer
- Node.js 20+
- npm
- SQLite, MySQL, or PostgreSQL
- Python 3.10+
- `pypdf`
- Playwright Chromium
- A Nextcloud/WebDAV account

For local development, SQLite is the easiest option.

## Quick start

```bash
git clone https://github.com/alexander-bauer-xr/photobook.git
cd photobook

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate
```

Install browser and PDF-box dependencies:

```bash
npx playwright install chromium
python3 -m pip install -r scripts/requirements.txt
```

Build frontend assets:

```bash
npm run build
```

Start the app:

```bash
php artisan serve
```

In another terminal, start the queue worker:

```bash
php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=0
```

Open:

```txt
http://localhost:8000/photobook
```

## Development mode

You can run the Laravel server, queue worker, logs, and Vite together:

```bash
composer run dev
```

Or use the npm helper:

```bash
npm run dev:all
```

## Configure Nextcloud

Set these values in `.env`:

```env
NEXTCLOUD_BASE_URI=https://cloud.example.com/remote.php/dav/files/USERNAME/
NEXTCLOUD_USERNAME=USERNAME
NEXTCLOUD_PASSWORD=APP_PASSWORD
PHOTOBOOK_FOLDER=Photos
```

Use a Nextcloud app password instead of your main account password.

The `NEXTCLOUD_BASE_URI` should point to the WebDAV files endpoint for the user.

Example:

```env
NEXTCLOUD_BASE_URI=https://cloud.example.com/remote.php/dav/files/alex/
NEXTCLOUD_USERNAME=alex
NEXTCLOUD_PASSWORD=your-nextcloud-app-password
PHOTOBOOK_FOLDER=Photos/Family/2026
```

## Create a photobook

1. Open `/photobook`
2. Enter a Nextcloud folder path, for example:

   ```txt
   Photos/Family/2026
   ```

3. Click **Build**
4. Wait until the background job finishes
5. Edit the generated pages
6. Click **Export PDF**

Generated data is stored under:

```txt
storage/app/pdf-exports/
storage/app/pdf-exports/_cache/
```

## Print export

Photobook exports PDFs through Playwright.

When print mode is enabled, the exporter can add:

- bleed area
- crop marks
- spine margin
- safe zone
- PDF `MediaBox`
- PDF `TrimBox`
- PDF `BleedBox`
- PDF `CropBox`
- PDF `ArtBox`

Print settings are configured in `.env` and can be overridden from the app settings UI.

Important: crop marks alone are not enough for professional printing. The PDF also needs correct trim and bleed boxes. This project uses `scripts/apply_pdf_boxes.py` for that post-processing step.

## Privacy model

Photobook is designed for self-hosting.

Your photos are read from your configured Nextcloud/WebDAV source and cached locally inside your Photobook installation. The app does not require uploading your photo library to a third-party service.

You are responsible for securing your own deployment.

For now, do not expose a development installation publicly without adding authentication, HTTPS, and access controls.

## Useful commands

Run tests:

```bash
composer test
```

Run Laravel Pint:

```bash
./vendor/bin/pint
```

Build frontend:

```bash
npm run build
```

Run Vite:

```bash
npm run dev
```

Run queue worker:

```bash
php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=0
```

Clear caches:

```bash
php artisan optimize:clear
```

## Troubleshooting

### Playwright Chromium is missing

Run:

```bash
npx playwright install chromium
```

### PDF export says `No module named 'pypdf'`

Run:

```bash
python3 -m pip install -r scripts/requirements.txt
```

### Build starts but never finishes

Make sure the queue worker is running:

```bash
php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=0
```

### No photos are found

Check:

- `NEXTCLOUD_BASE_URI`
- `NEXTCLOUD_USERNAME`
- `NEXTCLOUD_PASSWORD`
- `PHOTOBOOK_FOLDER`
- whether the folder path is relative to the WebDAV user root

### Local SQLite database missing

Run:

```bash
touch database/database.sqlite
php artisan migrate
```

## Roadmap

Near-term:

- Docker Compose setup
- sample/demo album
- better install diagnostics
- stronger validation around folders, hashes, and asset paths
- controller/service split
- improved print preview
- more tests

Later:

- Nextcloud connector app
- hosted/managed version
- template gallery
- better face-aware cropping
- optional ML/photo-quality scoring

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Please do not report security issues in public issues. See [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
