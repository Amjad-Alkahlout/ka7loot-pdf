# Ka7loot PDF

Ka7loot PDF is a bilingual web app for working with PDF and image files. It is built with Laravel, Livewire, and Vite. Files are processed in temporary, per-job storage and are automatically removed after their retention period.

Created and maintained by **Amjad Alkahloot**.

GitHub: [Amjad Alkahloot](https://github.com/Amjad-Alkahlout)

Contact: [akahloot2005@gmail.com](mailto:akahloot2005@gmail.com)

## Features

### PDF tools

- Merge PDFs, split by page ranges, and organize pages by reordering, rotating, or deleting them.
- Compress PDFs. Ghostscript enables deeper compression; a PHP-based optimization path is available without it.
- Convert PDF pages to JPG or PNG with Poppler (`pdftoppm`).
- Convert JPG, PNG, and WebP images to PDF, and convert HEIC/HEIF images to PDF.
- Sign PDFs and edit them with text, images, and page-level changes.
- Preview PDF pages progressively in the browser before processing.
- Process multiple files in supported tools, with per-file status and progress feedback.

### Image tools

- Compress, resize, and convert images. Supported formats include JPG, PNG, WebP, GIF, BMP, HEIC, and HEIF where the selected operation supports them.
- Remove an image background or replace it with a color or image. Background removal uses a configurable processing engine.

### Accounts, privacy, and localization

- English and Arabic interfaces, including right-to-left layout support.
- Clerk-based sign-in and account management, with optional Google sign-in configured through Clerk.
- Default daily processing quotas are 20 files for guests and 50 for signed-in users. The separate default batch limit is 50 files.
- Temporary per-job file storage, expiring download links, immediate deletion, and temporary share links with QR codes.
- Anonymous tool-usage records store hashed IP addresses rather than raw client IPs.

## Technology

- PHP 8.3 or later and Laravel 13
- Livewire 3
- Node.js and npm; Vite 7 and Tailwind CSS 4
- SQLite for the default local setup, or MySQL with `pdo_mysql`
- PHP extensions: GD, Fileinfo, Intl, Zip, and the PDO driver for the configured database
- Optional system tools: Ghostscript for deeper PDF compression and Poppler for PDF-to-image conversion

Install dependencies from the lock files with Composer and npm to get the project’s exact package versions.

## Local setup

### Requirements

Install PHP 8.3+, Composer, Node.js (20+ recommended), and npm. Enable the PHP extensions listed above. The repository's `.env.example` uses SQLite by default; MySQL can be configured instead.

Install optional tools if you need their capabilities:

```powershell
# Windows with Chocolatey
choco install ghostscript poppler -y
```

On Debian or Ubuntu, install `ghostscript` and `poppler-utils` with the system package manager. If a binary is not on `PATH`, set `FP_GHOSTSCRIPT_PATH` or `FP_POPPLER_PATH` in `.env` to its executable path.

### Install and run

```bash
git clone https://github.com/Amjad-Alkahlout/ka7loot-pdf.git
cd ka7loot-pdf
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

For local development, run the application, queue worker, and Vite in separate terminals:

```bash
php artisan serve
php artisan queue:work
npm run dev
```

For a single-command development environment, the Composer `dev` script starts Laravel, the queue listener, logs, and Vite together:

```bash
composer run dev
```

If you stop Vite and want to use the built assets, remove the stale `public/hot` file before running the app:

```powershell
Remove-Item public/hot -ErrorAction SilentlyContinue
```

## Configuration

### Database and queue

The example environment is configured for SQLite, a database-backed queue, sessions, and cache. Create the SQLite database file if it does not exist, then run migrations:

```powershell
New-Item -ItemType File -Path database/database.sqlite -Force
php artisan migrate
```

For MySQL, configure `DB_CONNECTION`, host, database, username, and password in `.env`, and ensure `pdo_mysql` is enabled. Keep a queue worker running in production to process queued jobs.

### Clerk authentication

Clerk is used for authentication. Set these deployment values in `.env`; do not commit secret keys:

```dotenv
CLERK_PUBLISHABLE_KEY=pk_test_...
CLERK_JWT_KEY="-----BEGIN PUBLIC KEY-----..."
CLERK_ISSUER=https://your-instance.clerk.accounts.dev
CLERK_AUTHORIZED_PARTIES=https://your-domain.example
CLERK_SECRET_KEY=sk_test_...
CLERK_API_URL=https://api.clerk.com/v1
```

In the Clerk Dashboard, enable the sign-in methods you intend to offer, configure email verification as needed, and add the local and production application origins. Google sign-in is configured as a Clerk social connection; this app does not implement Google OAuth directly.

The existing Vite entry point initializes Clerk and reads `CLERK_PUBLISHABLE_KEY` from the Laravel layout. Do not add a second Clerk bootstrap or a separate `VITE_CLERK_PUBLISHABLE_KEY`. If you add a Content Security Policy, allow the Clerk Frontend API domain in `script-src` and Clerk endpoints in `connect-src`.

Laravel verifies Clerk session tokens server-side and maps users to the local `users.clerk_id`. The app stores the Clerk ID and basic verified profile fields, not passwords or provider tokens. After changing environment values, clear or rebuild the configuration cache:

```bash
php artisan config:clear
# Production only, after configuring the environment:
php artisan config:cache
```

### Processing limits

These values can be overridden in `.env`:

| Variable | Default | Purpose |
| --- | ---: | --- |
| `FP_DAILY_GUEST_FILES` | `20` | Daily file quota for guests |
| `FP_DAILY_AUTH_FILES` | `50` | Daily file quota for signed-in users |
| `FP_MAX_BATCH_FILES` | `50` | Maximum files in one batch |
| `FP_MAX_PDF_SIZE_KB` | `51200` | Maximum size of one PDF (50 MB) |
| `FP_MAX_IMAGE_SIZE_KB` | `25600` | Maximum size of one image (25 MB) |
| `FP_MAX_TOTAL_BATCH_KB` | `102400` | Maximum combined batch size (100 MB) |
| `FP_FILE_RETENTION_MINUTES` | `60` | Retention time for processed files |

PHP upload limits and the web server's request size limits must be large enough for the files you allow. Livewire's temporary upload limit is configured separately in `config/livewire.php`.

## System capability check

Check which optional binaries and core image/PDF capabilities are available:

```bash
php artisan app:check-system-binaries
```

Ghostscript is used for deeper PDF compression. Poppler (`pdftoppm`) is required for PDF-to-JPG/PNG rendering. Most other PDF operations use the PHP FPDI/FPDF libraries. Node.js is used for HEIC/HEIF conversion and some image-processing engines.

## Scheduled cleanup

The scheduler removes expired job files and old usage records. Run Laravel's scheduler every minute in production so scheduled tasks can run:

```cron
* * * * * cd /path/to/ka7loot-pdf && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, configure Task Scheduler to run `php artisan schedule:run` regularly. You can also invoke file cleanup directly:

```bash
php artisan app:cleanup-expired
```

## Tests

Run the automated suite with:

```bash
php artisan test
```

The suite covers PDF and image tools, upload and job handling, preview and progress flows, authorization and guest isolation, temporary sharing, cleanup, account flows, and English/Arabic UI behavior. Some HEIC, image-background, PDF-rendering, and browser verification paths depend on the required runtime or optional system tools.
