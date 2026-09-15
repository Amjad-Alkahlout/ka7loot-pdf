# Ka7loot PDF - Production-Grade PDF & Image Processing Platform

**Ka7loot PDF** is a modern, privacy-focused online utility platform built with **Laravel 13**, **PHP 8.5.5**, **MySQL 8.4**, **Livewire 3**, **Vite 7**, and **Tailwind CSS 4**.

Created & maintained by **Amjad Alkahloot** (Software Developer)  
GitHub: [https://github.com/Amjad-Alkahlout](https://github.com/Amjad-Alkahlout)  
Contact: [akahloot2005@gmail.com](mailto:akahloot2005@gmail.com)

---

## Key Features

1. **Pure-PHP Document & Image Clustering**:
   - **PDF Merge**: Combines multiple PDF files into one while preserving per-page orientations, dimensions, and metadata.
   - **PDF Split**: Extract specific page ranges (e.g. `1-3, 5, 8-10`) or extract all pages as separate PDFs into a unified ZIP archive.
   - **Organize & Rotate PDF**: Arbitrary page reordering, selective deletion, and native 90°/180°/270° orientation rotation (`RotatableFpdi`).
   - **JPG to PDF**: Converts collections of JPG, PNG, and WebP images into PDFs with customizable page sizes (`A4`, `Letter`, `Legal`, `Fit to Image`), orientations, and margins.
   - **Image Compression & Optimization**: High-speed lossy and lossless compression for JPG, PNG, and WebP using Intervention Image v3 GD driver.
   - **Image Resizing & Conversion**: Pixels-based image resizing (`Contain`, `Cover`, `Fill`) and format conversion across JPG, PNG, WebP, GIF, and BMP.

2. **Dual-Engine PDF Compression Transparency**:
   - **Deep Compression (Ghostscript)**: Employs Ghostscript (`gs`) stream downsampling, font subsetting, and flate recompression when available on the host environment.
   - **Limited Optimization (Pure PHP)**: When Ghostscript is not installed, performs structural PDF stream cleanup and object repack via `RotatableFpdi`. Clearly labeled in the UI as *"Limited optimization (Pure PHP)"* with an informative notice indicating Ghostscript is required for deep compression.

3. **Subprocess Security & Execution Isolation**:
   - External CLI commands (`gs`, `pdftoppm`) are executed through [`SubprocessExecutorService`](file:///C:/Users/pdx/Desktop/PDF%20Project/app/Services/System/SubprocessExecutorService.php) using argument arrays to eliminate shell injection vulnerabilities, with strict timeouts and error sanitization.

4. **Ephemeral Storage & Strict Data Governance**:
   - Every file operation takes place in an isolated per-job directory: `storage/app/secure_temp/{job_uuid}/`.
   - File uploads are validated via magic-byte inspection (`finfo_file`), stripping path traversal characters.
   - Anonymized telemetry (`tool_usage`) uses SHA-256 IP hashes with zero storage of raw client IP addresses.
   - Automated cleanup scheduler (`php artisan app:cleanup-expired`) purges expired directories every 15 minutes.
   - "Delete Now" button allows users to immediately and irreversibly delete their files from the disk on demand.

5. **Authoritative Status & Idempotent Asynchronous Processing**:
   - UI reflects authentic database `FileJob::$status` transitions (`pending` $\rightarrow$ `processing` $\rightarrow$ `completed` / `failed`).
   - Indeterminate progress indicators avoid false progress estimates.
   - Server-anchored countdown timer computes expiration directly against database `expires_at` UTC timestamps.
   - Atomic cache locking (`Cache::lock`) blocks duplicate concurrent workers from running the same job.
   - Retries cleanly purge prior outputs and update `processed_files` and `tool_usage` in-place without duplicate records.

6. **Bilingual RTL Support (English & Arabic)**:
   - Dynamic direction switching (`dir="ltr"` and `dir="rtl"`).
   - Tailored Arabic typography with Cairo font support.

---

## Technical Stack

- **Framework**: Laravel 13 (`v13.31.0`)
- **Runtime**: PHP 8.5.5 (Extensions: `gd`, `exif`, `intl`, `zip`, `pdo_mysql`, `fileinfo`)
- **Database**: MySQL 8.4
- **Reactivity**: Livewire 3 (`v3.8.8`)
- **Styling**: Tailwind CSS 4 (`v4.3.3`) with `@tailwindcss/vite`
- **Frontend Bundler**: Vite 7 (`v7.3.6`)

---

## Prerequisites & Installation

### 1. Host Requirements
- PHP 8.5.5+ with GD, Exif, Intl, Fileinfo, and Zip extensions enabled in `php.ini`.
- MySQL 8.4+ database server.
- Node.js 20+ & npm.
- *(Optional for Deep PDF Compression)* Ghostscript (`gs` / `gswin64c.exe`):
  ```powershell
  choco install ghostscript -y
  ```
- *(Optional for PDF-to-Image)* Poppler (`pdftoppm`):
  ```powershell
  choco install poppler -y
  ```

### 2. Setup Application
```bash
# Clone repository
git clone <repo-url> pdf-project
cd pdf-project

# Install composer packages
composer install

# Install npm packages & compile assets
npm install
npm run build

# Configure environment
cp .env.example .env
php artisan key:generate

# Run database migrations
php artisan migrate
```

### 3. Check System Binaries & Capabilities
Verify host capabilities and external binary discovery:
```bash
php artisan app:check-system-binaries
```

---

## Running the Platform

### Local Development
Run the development servers concurrently:
```bash
# Terminal 1: Laravel Server
php artisan serve

# Terminal 2: Queue Worker (listens on default queue)
php artisan queue:work

# Terminal 3: Asset Dev Server (if modifying styles)
npm run dev
```

### Automated Pruning & Scheduled Tasks
Ensure the Laravel scheduler runs periodically to purge expired files:
```bash
# Windows Task Scheduler or Linux Cron (every 15 minutes):
php artisan schedule:run
```
Manual cleanup:
```bash
php artisan app:cleanup-expired
```

---

## Automated Test Suite

Run the full platform test suite:
```bash
php artisan test
```
All tests cover:
- Unit file sanitization and security path traversal validation
- FileStorageManager ephemeral isolation
- Intervention Image v3 GD processing (compress, resize, convert, archive)
- Pure-PHP PDF manipulation cluster (merge, split, organize, rotate, JPG-to-PDF)
- Subprocess isolation & Ghostscript capability detection
- ProcessFileJob idempotency and concurrent worker protection
- Feature download validation, expiration rejection, immediate deletion, and RTL locale switching.
