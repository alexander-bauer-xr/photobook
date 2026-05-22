# Contributing to Photobook

Thanks for your interest in contributing.

Photobook is an early self-hosted photobook generator. The current focus is reliability, installability, print correctness, and making the codebase easier to understand.

## Project goals

Photobook should:

- stay self-hostable
- keep private photos on the user's own infrastructure
- work well with Nextcloud/WebDAV
- produce editable photobook layouts
- export print-ready PDFs
- be understandable for contributors
- avoid unnecessary SaaS lock-in

## Good first contribution areas

Useful contributions include:

- documentation improvements
- setup/install fixes
- Docker support
- tests
- print/PDF export improvements
- safer path validation
- frontend state cleanup
- UI polish
- Nextcloud/WebDAV compatibility fixes

## Development setup

```bash
git clone https://github.com/alexander-bauer-xr/photobook.git
cd photobook

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate

npx playwright install chromium
python3 -m pip install -r scripts/requirements.txt
```

Start the app:

```bash
composer run dev
```

Or run services manually:

```bash
php artisan serve
php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=0
npm run dev
```

Open:

```txt
http://localhost:8000/photobook
```

## Code style

For PHP:

```bash
./vendor/bin/pint
```

For tests:

```bash
composer test
```

For frontend build:

```bash
npm run build
```

## Before opening a pull request

Please check:

```bash
composer test
npm run build
```

If your change affects PDF export, also test:

```bash
npx playwright install chromium
python3 -m pip install -r scripts/requirements.txt
```

Then export a real PDF and verify:

- pages render correctly
- images load
- crop marks are correct when enabled
- trim/bleed boxes are correct when print mode is enabled
- no private photos or generated PDFs are committed

## Pull request guidelines

Please keep PRs focused.

Good PR examples:

- “Add hash validation to photobook API routes”
- “Extract SettingsRepository from PhotobookController”
- “Improve README setup instructions”
- “Fix PDF TrimBox/BleedBox metadata”
- “Add Docker Compose setup”

Avoid mixing unrelated changes like UI redesign, backend refactor, and print export changes in one PR.

## Commit style

Use clear, boring commit messages:

```txt
Add print export setup docs
Fix WebDAV asset path validation
Extract photobook settings repository
Add PDF box post-processing test
```

## Generated files

Do not commit:

```txt
.env
database/database.sqlite
storage/app/pdf-exports
storage/app/pdf-exports/_cache
node_modules
vendor
generated PDFs
cached photos
```

## Security-related changes

If your PR touches any of these areas, mention it clearly:

- WebDAV paths
- asset serving
- file downloads
- generated PDFs
- album hashes
- environment variables
- Playwright rendering
- Python post-processing

## License

By contributing, you agree that your contribution is licensed under the project license.
