# Minimal YouTube link converter (Full PHP)

A deliberately small project: one PHP page contains the HTML, CSS, JavaScript, and public API; one PHP worker handles queued jobs; one downloader module contains the supplied provider integration.

## Files

- `public/index.php` — UI and the `create` / `status` API endpoints.
- `worker.php` — background job worker.
- `downloader.php` — the `ytdownloader(url, format)` function.
- `storage/jobs/` — private job files, outside the public document root.

## Requirements

- PHP 8.2+.
- PHP `curl`, `json`, and `openssl` extensions.
- PHP needs write permission to the project's `storage/` directory.

## Run locally

From the project root:

```bash
./start.sh
```

Or manually:

```bash
# In terminal 1
php worker.php

# In terminal 2
php -S 127.0.0.1:8000 -t public
```

Open `http://127.0.0.1:8000`.

The PHP process and PHP worker must share the same filesystem and storage path. If you customize the storage path, set `YTMP3_DATA_DIR` to the same absolute directory in both processes.

## Production deployment

Set the web document root to the `public/` directory only. Keep `worker.php`, `downloader.php`, and `storage/` outside the public document root. Run the PHP worker as a persistent service (for example, through Supervisor or systemd) and give both PHP processes read/write access to the job directory.

## How asynchronous jobs work

1. The browser POSTs a URL and format to `index.php?api=create`.
2. PHP creates a private JSON job and returns a job ID immediately.
3. `worker.php` watches the shared job directory and calls `ytdownloader(url, format)`.
4. The browser polls `index.php?api=status&id=...` until the job completes or fails.

No database or framework is required for this small implementation.

## Downloader integration notes

The original logic was ported from a JavaScript implementation. `downloader.php` retains the provider flow and two provider pairs from the original snippet. Provider endpoints and parameters can change independently of this UI; if a provider has changed its protocol, inspect its current documented behavior rather than assuming that the website layout is responsible.

Only use this tool for media you own or have permission to download. Never use it to bypass DRM, authentication requirements, or other access controls.

## Quick checks

```bash
php -l worker.php
php -l downloader.php
php -l public/index.php
```

A successful syntax check does not prove that the external conversion provider is currently available. Real conversion still needs an end-to-end test with a permitted source URL.
