# LE Editor

A Turkish-first, bilingual AI book editor built with Laravel 10, Breeze, Blade, Tailwind CSS and OpenRouter. Upload a DOCX, combine editing presets, review inline changes and download a Word document with real tracked revisions.

## Features

- Turkish and English interfaces; warm paper and dark themes.
- Breeze registration, authentication, password reset and account management.
- DOCX import; title and language inferred from the first 2,000 words and editable afterward.
- Ten bilingual presets covering fiction, nonfiction, academic writing and poetry at different editing levels. Combine presets into a book-specific prompt; create, duplicate, edit and delete personal presets.
- Encrypted per-user OpenRouter keys; optional server-key development mode.
- Cached model discovery using the server key. Free models are excluded and output prices are filtered by environment limits.
- Queued editing in adjustable word-sized chunks (default 2,500), with paragraph boundaries preserved where possible. The model returns plain prose; `jfcherng/php-diff` computes changes.
- Inline deletions/insertions, accept/reject/undo controls, pending-change navigation and clean preview. Keyboard shortcuts: Alt+Up / Alt+Down.
- A modal progress dialog with explicit cancellation. Completed suggestions remain; untouched chunks retain their source text. Failed or cancelled runs can resume.
- Each accept/reject decision can trigger prompt learning. Updates are serialized per book and versioned; shared presets are never silently changed.
- Reruns materialize accepted suggestions, archive the old review and use the latest prompt with a newly chosen model/chunk size.
- Manual editing with a floating save indicator, 10-second debounced autosave, Save now and optimistic concurrency checks.
- Manuscript snapshots, prompt restoration and read-only historical reviews.
- DOCX exports with genuine `w:ins` / `w:del` revisions. Approved AI changes and manual edits are included by default. Pending suggestions can be included for export only; rejected suggestions are excluded.
- Cost estimates, reported run usage, private file storage, ownership checks and deletion controls.

See [todo.md](todo.md) for implementation and verification status.

## Requirements

- PHP 8.1+ with Composer, PDO MySQL (or SQLite for tests), ZIP, DOM/XML, mbstring, cURL, OpenSSL and fileinfo.
- MySQL/MariaDB and an empty database such as `le_editor`.
- Node.js 22.12+ (Node 24 recommended for this locked toolchain), npm.
- An authenticated browser tab to drive AI work. No queue worker, cron task or Supervisor service is needed.
- An OpenRouter account/key for server-side model discovery. In normal mode each user supplies an inference key.

Laravel 10 is retained to match the project specification. See the security note below before public deployment.

## Ubuntu setup

These instructions target **Ubuntu 24.04 LTS**, using its PHP 8.3 packages. Run application commands as your normal development/deployment user, not root. Use `sudo` only for system administration.

### 1. Install system dependencies

```sh
sudo apt update
sudo apt install -y git curl unzip composer mysql-server \
    php8.3-cli php8.3-fpm php8.3-mysql php8.3-sqlite3 \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath
sudo systemctl enable --now mysql
```

Install Node.js 24 using the Linux/nvm instructions on the [official Node.js download page](https://nodejs.org/en/download). Once nvm is installed and loaded in your shell:

```sh
nvm install 24
nvm alias default 24
nvm use 24
```

Node includes npm. Use the same deployment user for Node installation and asset builds. The PHP package baseline is documented in the [Ubuntu 24.04 package catalog](https://packages.ubuntu.com/noble/php8.3-cli).

Clone your repository if needed, then enter its root directory—the folder containing `artisan` and `composer.json`. Check your tools and install the application's PHP dependencies:

```sh
php -v
php -m
composer --version
node --version
npm --version
composer install
```

Check that the PHP extensions listed under Requirements are enabled. The web server and command line must both use a compatible PHP configuration.

### 2. Prepare the environment file

**If `.env` already exists, keep it and edit its values.** Only create it on a fresh checkout:

```sh
cp .env.example .env
nano .env
```

Set the basic application values:

```dotenv
APP_NAME="LE Editor"
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
APP_LOCALE=tr
QUEUE_CONNECTION=database
CACHE_DRIVER=file
SESSION_DRIVER=file
```

If `APP_KEY` is blank, generate it once:

```sh
php artisan key:generate
```

Keep an existing application key. Replacing it makes previously saved encrypted API keys unreadable. Never commit `.env`.

### 3. Configure the database

Open the MySQL administration shell:

```sh
sudo mysql
```

If the database and application user already exist, keep them and use their credentials. For a fresh installation, replace the example password and run:

```sql
CREATE DATABASE le_editor
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
CREATE USER 'le_editor'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT ALL PRIVILEGES ON le_editor.* TO 'le_editor'@'127.0.0.1';
EXIT;
```

Give your application's database user access to this database, then set `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=le_editor
DB_USERNAME=le_editor
DB_PASSWORD="replace-with-a-strong-password"
```

Replace the example username and password with your actual credentials. Quote values containing spaces or `#`.

### 4. Configure OpenRouter

Set these values in `.env`:

```dotenv
OPENROUTER_API_KEY=your_server_api_key
OPENROUTER_MODEL=openai/gpt-4o-mini
OPENROUTER_DEV_MODE=false
OPENROUTER_MIN_OUTPUT_PRICE=0.01
OPENROUTER_MAX_OUTPUT_PRICE=30
```

The server key is required for model discovery in both modes:

- **Normal mode (`false`):** users register and save their own inference API key in Settings.
- **Development mode (`true`):** inference uses the server key, so you can test without adding a personal key to each account. Calls are charged to the server key's account.

The price limits are USD per million **output** tokens. Free models are always excluded. Choose a default model that appears in the filtered dropdown; availability and pricing can change.

### 5. Create tables and install the presets

```sh
php artisan config:clear
php artisan migrate --seed
```

This creates the application tables (including persistent browser queue state) and installs the ten bilingual presets. No default login account is created.

To check migration status:

```sh
php artisan migrate:status
```

### 6. Install and build frontend assets

```sh
npm ci
npm run build
```

This generates `public/build/manifest.json` and the compiled CSS/JavaScript. Run the build on each deployment server too; generated assets are not committed.

### 7. Start the app

For development on Ubuntu, start the PHP development server with multiple workers so cancellation and progress requests can be served while an LLM request is running:

```sh
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000
```

Keep this terminal running. No `queue:work` process is needed. A single-threaded development server will delay cancellation until the active request finishes. Use PHP-FPM for deployment.

For an Ubuntu server accessed over SSH, you can preview the loopback-only development server through an SSH tunnel from your own computer:

```sh
ssh -L 8000:127.0.0.1:8000 your_user@your_server
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000).

Use Nginx/PHP-FPM for a deployed site; `php artisan serve` is only for development.

### 8. Create your account and edit your first manuscript

1. Register an account. The interface defaults to Turkish; use **EN** to switch to English.
2. Open **Settings / Ayarlar**. In normal mode, save your personal OpenRouter API key.
3. Select an available default model, save, then test the saved connection.
4. Upload a DOCX from **Your manuscripts / Eserlerim**.
5. Wait for the inferred title and language, and adjust them if needed.
6. The editorial setup dialog opens automatically for a book with no editing runs. Select presets to see the prompt update immediately, then customize it if needed.
7. Choose a model and chunk size, then start an editing pass. Starting saves the settings automatically. Reopen the dialog from the page heading for subsequent passes; Escape or Cancel closes it without saving.
8. Review and accept/reject suggestions. Download DOCX to export tracked changes; optionally include pending suggestions in that export.

### Optional: frontend development and email

For automatic frontend updates, run `npm run dev` in a second terminal while editing CSS or JavaScript. Keep the web app running as well. Run `npm run build` when preparing a deployment.

The example environment uses `MAIL_MAILER=log`, which sends no email to an inbox. To inspect password-reset emails locally, also set `LOG_LEVEL=debug` so the mail log entries are recorded in `storage/logs/laravel.log`. Treat those logs as private because they contain reset links. Configure SMTP for real delivery:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=your_smtp_host
MAIL_PORT=587
MAIL_USERNAME=your_smtp_username
MAIL_PASSWORD="your_smtp_password"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="editor@your-domain.com"
MAIL_FROM_NAME="LE Editor"
```

Use your mail provider's actual host, port and encryption settings.

## How the browser queue works

An authenticated, visible app page polls `/queue/state` and sends `/queue/step` AJAX requests. Each step performs **at most one** LLM completion on the PHP server, saves its result and returns. API keys stay on the server. Nothing schedules the next step except the browser.

- Upload inference sends the first 2,000 words to infer title/language.
- Editing sends one pending chunk and saves its text, diff and progress.
- Prompt learning processes one pending review decision and versions the book prompt.
- A shared modal shows a spinner and chunk progress where available, with a cancel button. It cannot be dismissed with Escape.
- Closing the tab, leaving the site or hiding all app tabs stops new steps. Returning, refreshing or logging back in resumes unfinished work automatically.
- **Cancel** pauses the whole account's AI queue persistently, across tabs and logins. Completed results stay saved; unprocessed chunks stay unchanged. Use **Resume AI queue**, a book's retry button, or initiate a new AI action to resume.
- An already-sent provider request can finish and be billed after closing a tab. Explicit cancellation invalidates its result, including if you resume before that response arrives.
- A database lease serializes requests across tabs. If PHP crashes, the lease expires after five minutes and a later browser request can retry. An interrupted call may be billed again if retried.
- Manual autosave, reviewing suggestions and DOCX exports use ordinary web requests.

On upgrading from the old worker version, stop and disable any existing LE Editor Supervisor/systemd worker **before deploying**, then run migrations and rebuild assets. Existing pending metadata, chunks and feedback are discovered from their saved state; legacy Laravel queue payloads are no longer executed as LLM work. Do not start a worker for this app.

### Default interface language

Set `APP_LOCALE=tr` (default) or `APP_LOCALE=en` in `.env`. This controls the initial interface and new account preference. The **EN / TR** switch works on deployed sites and saves a signed-in user's preference. Existing users retain their chosen language. Manuscript language is separate and still inferred on upload.

After changing `.env`, use `php artisan config:clear` locally or `php artisan config:cache` on a cached deployment.

## OpenRouter configuration

```dotenv
OPENROUTER_API_KEY=your-server-key
OPENROUTER_MODEL=openai/gpt-4o-mini
OPENROUTER_DEV_MODE=false
OPENROUTER_MIN_OUTPUT_PRICE=0.01
OPENROUTER_MAX_OUTPUT_PRICE=30
```

| Variable | Behavior |
| --- | --- |
| `OPENROUTER_API_KEY` | Always authenticates model discovery. Never exposed to the browser. |
| `OPENROUTER_DEV_MODE` | When `true`, all inference uses the server key instead of users' keys. Keep `false` on shared production deployments. |
| `OPENROUTER_MODEL` | Initial default model for users who have not selected one. It must pass the configured model filters. |
| `OPENROUTER_MIN_OUTPUT_PRICE` | Inclusive minimum USD per million output tokens. |
| `OPENROUTER_MAX_OUTPUT_PRICE` | Inclusive maximum USD per million output tokens. |

The models API reports completion pricing per token; the application multiplies it by 1,000,000 before filtering. Zero-price and `:free` models are excluded regardless of the minimum. The catalog is cached for one hour with settings-sensitive cache keys. Prices are selection filters, not guaranteed billing caps. Model context/output limits are checked conservatively; actual tokenization and provider pricing vary.

After changing environment configuration on a cached deployment:

```sh
php artisan config:cache
php artisan cache:clear
```

API references: [OpenRouter models](https://openrouter.ai/docs/api/api-reference/models/get-models) and [chat completions](https://openrouter.ai/docs/api/api-reference/chat/send-chat-completion-request).

## Editing and data semantics

The imported text remains immutable. The working manuscript is a separate value. Each editing run snapshots its prompt, context, language, model and chunk size. Review decisions are stored on diff segments; accepted changes become the next working text when rerunning or manually editing.

Manual editing begins with the accepted-text view. Its first changed save archives the active review and discards pending suggestions from that active view. This is explained before entering manual mode. Old reviews remain in history. Manual edits are stored as manuscript snapshots; exports compute cumulative revisions from the original import, including those manual edits. Export revision authors are labelled `LE Editor`; they are not a keystroke-by-keystroke audit trail.

A rerun discards pending/rejected suggestions from the new draft. Exporting pending suggestions never accepts them in the app. The original DOCX is retained privately until the book/account is deleted.

Cancellation is cooperative: already committed chunks remain, and an in-flight response is ignored if cancellation wins the database lock. An already-sent provider request may still incur a charge. Closing a browser tab cannot be prevented absolutely; the app restores the progress dialog when reopened. Unsaved manual edits still trigger a navigation warning. Further steps require an authenticated, visible app page. Explicit cancellation stays paused until resumed.

Prompt-learning failures do not undo review decisions. They are visible with a retry control. Prompt edits use a version check so an AI update cannot silently overwrite a manual prompt edit. Learning affects the next run, never chunks already in progress.

## DOCX scope

Import reads body text, paragraphs and line breaks, treating existing Word revisions as accepted text. Export creates a new OOXML package containing real insert/delete revisions and paragraph revision markers. In Word, select **Review → All Markup** to view them; Word's own display preferences may hide markup by default.

This release is text-focused. Original styles, page layout, images, headers/footers, comments, footnotes, lists and table structure are not round-tripped. Table cell text is flattened into paragraphs. Exports use a basic page layout. Complex manuscripts need a dedicated fidelity-preserving import/export layer before relying on their formatting.

## Ubuntu production deployment

1. Serve only the `public/` directory through PHP-FPM and Nginx/Apache. Keep `.env`, `storage/`, source files and backups outside public access.
2. Install PHP dependencies with `composer install --no-dev --optimize-autoloader`.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, database credentials, a permanent `APP_KEY`, SMTP and OpenRouter configuration. Enable secure session cookies for HTTPS.
4. Run `php artisan migrate --force` and `php artisan db:seed --class=PromptSeeder --force`.
5. Build assets **on the deployment server** using `npm ci` followed by `npm run build`. Build dependencies must be installed for this step. `public/build` is intentionally not committed.
6. Run `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache`.
7. Make `storage/` and `bootstrap/cache/` writable by the application user.
8. Do not run a background queue worker. Ensure PHP-FPM has multiple children so status/cancel requests can run alongside an LLM request.
9. Set PHP/web-server upload limits above 20 MB (`upload_max_filesize=20M`, `post_max_size=25M`) and request timeouts of at least 240 seconds. Each browser step waits for one LLM call (provider timeout: 180 seconds).
10. Back up the database, private manuscripts and **the existing APP_KEY**. Regenerating that key makes saved API keys unreadable. Configure TLS, log rotation and monitoring.

Queue leases use the shared database. Multiple application hosts also need shared private storage, sessions and cache.

### Nginx and PHP-FPM

```sh
sudo apt install -y nginx
sudo systemctl enable --now php8.3-fpm nginx
```

Configure your site to serve `/var/www/le-editor/public` and forward PHP requests to `/run/php/php8.3-fpm.sock` (adjust paths to your installation). Set `client_max_body_size 25m;` in the site and `fastcgi_read_timeout 300s;` in its PHP location. Configure your domain and TLS before exposing the site publicly.

In the PHP-FPM pool, keep `pm.max_children` at least 4 and set `request_terminate_timeout = 300s`. In the FPM PHP configuration, set `max_execution_time = 240`, `upload_max_filesize = 20M` and `post_max_size = 25M`. Reload PHP-FPM and Nginx after validating their configurations:

```sh
sudo php-fpm8.3 -t
sudo nginx -t
sudo systemctl reload php8.3-fpm nginx
```

Any reverse proxy in front of Nginx must also allow long requests; otherwise choose smaller chunks or a faster model. An HTTP timeout does not guarantee that the provider stopped its request. Return to the app to inspect saved progress before retrying.

## Validation

```sh
php artisan test
node --test tests/browser-queue.test.js
npm run build
composer audit
npm audit
```

Tests force an isolated in-memory SQLite database and fake provider responses; they never reset the configured MySQL database or spend OpenRouter credits. Coverage includes authentication, ownership, encrypted keys, catalog filtering, Unicode/whitespace preservation, reruns, manual-save conflicts, cancellation races, metadata inference, prompt learning, output truncation and OOXML exports.

The live model catalog was checked successfully during development. Paid completion behavior is covered with deterministic HTTP fakes; a real manuscript/model trial remains a deployment acceptance check. Native Microsoft Word visual compatibility has not yet been verified.

## Troubleshooting

| Problem | What to check |
| --- | --- |
| `Vite manifest not found` | Run `npm ci` and `npm run build` from the repository root. |
| Database connection refused / access denied | Start MySQL, check `.env` host/port/credentials and confirm the database user has access to `le_editor`. Run `php artisan config:clear` after local configuration changes. |
| `could not find driver` | Enable `pdo_mysql` for the PHP installation running the app. Tests also need `pdo_sqlite`. |
| Title detection or editing stays queued | Keep an authenticated app tab visible. Check the paused-queue banner, browser network errors and `storage/logs/laravel.log`. After a crashed request, lease recovery can take five minutes. |
| Model dropdown is empty or unavailable | Verify the server `OPENROUTER_API_KEY` and output-price limits. Run `php artisan cache:clear` to refresh the catalog. |
| API key required / insufficient credits | Save a valid personal key in Settings, or enable development mode to use the configured server key. Verify credits on the account whose key is being used. |
| Model output is incomplete / chunk too large | Rerun with a smaller chunk size or select a model with larger context and output limits. |
| Upload fails before import | Check PHP `upload_max_filesize` and `post_max_size`, plus the web server's body-size limit. The app accepts DOCX files up to 20 MB. |
| Changes to `.env` do not take effect | Locally, run `php artisan config:clear`. On production, rebuild the config cache as shown above. |
| Permission errors | Ensure the application user can write to `storage/` and `bootstrap/cache/`. Do not make the entire repository world-writable. |
| Reset email does not arrive | `MAIL_MAILER=log` does not deliver email; use `LOG_LEVEL=debug` to inspect it locally. Configure SMTP for real delivery. |
| Word does not show revision marks | In Word, select **Review → All Markup**. The export contains revisions, but Word can hide them in its current view. |

Use the book's **Resume remaining chunks** or **Retry prompt learning** control for failures shown in the app. These controls preserve completed work.

## Security and operational limitations

The dependency audit reports known Laravel 10 advisories. The app rejects control characters in email inputs before trimming and production debug output must stay disabled. These application mitigations do not make an unsupported framework fully patched; upgrading Laravel is required before treating this as a hardened public service.

Tailwind 3's build-only dependency chain also has reported pattern-parser denial-of-service advisories. Builds operate on repository-owned templates; do not feed untrusted templates to the build process. Moving to Tailwind 4 is a separate toolchain upgrade.

Reported run cost covers chunk-editing calls, not metadata or prompt-learning calls. Failed requests may still be billed. Prompt learning uses the book's selected model. No automatic budget enforcement or billing system is included. Manuscripts are capped at 20 MB upload / one million extracted characters; large histories and whole-document polling will need pagination and retention policies at scale.
