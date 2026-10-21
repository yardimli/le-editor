# LE Editor

A Turkish-first, bilingual AI book editor built with Laravel 10, Breeze, Blade, Tailwind CSS and OpenRouter. Upload a DOCX, combine editing presets, review inline changes and download a Word document with real tracked revisions.

## Features

- Turkish and English interfaces; warm paper and dark themes.
- Breeze registration, authentication, password reset and account management.
- DOCX import; title and language inferred from the first 2,000 words and editable afterward.
- Ten bilingual presets covering fiction, nonfiction, academic writing and poetry at different editing levels. Combine presets into a book-specific prompt; create, duplicate, edit and delete personal presets.
- Encrypted per-user OpenRouter keys; optional server-key development mode.
- Cached model discovery using the server key. Free models are excluded and output prices are filtered by environment limits.
- Queued editing in adjustable word-sized chunks (default 1,000), with paragraph boundaries preserved where possible. The model returns plain prose; `jfcherng/php-diff` computes changes.
- Inline deletions/insertions, accept/reject/undo controls, pending-change navigation. Keyboard shortcuts: Alt+Up / Alt+Down.
- A persistent editorial footer with processed-chunk progress and Pause, Resume and Restart controls. Completed suggestions remain; untouched chunks retain their source text. Metadata inference still uses a modal.
- Optional prompt learning: review decisions are collected without LLM calls. After more than 50 suggestions, a dismissible footer hint offers to update the prompt. Learning runs only when requested, in batches of up to 50 decisions; shared presets remain unchanged.
- Reruns materialize accepted suggestions, archive the old review and use the latest prompt with a newly chosen model/chunk size.
- Chunk navigation with Previous/Next, a clickable 1/N jump control and processed-book progress. Only the selected chunk is fetched.
- Quill text and italic editing with an Edit/Review switch, with 10-second debounced autosave, Save now and per-chunk optimistic concurrency checks. Review completed chunks while later chunks run.
- Immutable original chunk text, author revision snapshots and an AI-edit counter per chunk.
- Manuscript snapshots, prompt restoration and read-only historical reviews.
- DOCX exports with genuine `w:ins` / `w:del` revisions. Approved AI changes, manual edits and pending suggestions are always included for export; rejected suggestions are excluded. Export does not approve pending suggestions in the app.
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
OPENROUTER_PROVIDERS=deepseek,anthropic,openai,moonshotai,google,qwen
OPENROUTER_MIN_OUTPUT_PRICE=0.01
OPENROUTER_MAX_OUTPUT_PRICE=30
```

The server key is required for model discovery in both modes:

- **Normal mode (`false`):** users register and save their own inference API key in Settings.
- **Development mode (`true`):** inference uses the server key, so you can test without adding a personal key to each account. Calls are charged to the server key's account.

The price limits are USD per million **output** tokens. Free models and models containing “batch” or “image” in their name or ID (case-insensitive) are always excluded. Provider groups follow `OPENROUTER_PROVIDERS`, with each group sorted by output price from cheapest to most expensive. Choose a default model that appears in the filtered dropdown; availability and pricing can change.

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
7. Choose a model and chunk size, then start an editing pass. Starting saves the settings automatically. Reopen the dialog from the sticky status band for subsequent passes; Escape or Cancel closes it without saving.
8. Review and accept/reject suggestions. Download DOCX to export tracked changes; pending suggestions are always included without approving them in the app.

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

The visible editor sends one combined `/books/{book}/state` poll every three seconds and uses `/queue/step` for actual LLM work. Book settings, run progress and visible-chunk revision tokens suppress unchanged payload sections. Other queue-enabled pages poll `/queue/state` every three seconds; the `/books` library neither polls nor advances the queue. Each step performs **at most one** LLM completion on the PHP server, saves its result and returns. API keys stay on the server. Nothing schedules the next step except the browser.

- Upload inference sends the first 2,000 words to infer title/language.
- Editing sends one pending chunk and saves its text, diff and progress.
- Prompt learning processes requested batches of review decisions and versions the book prompt. Merely reviewing suggestions never schedules a learning call.
- Metadata inference shows a modal. Editorial runs use the persistent footer, leaving the manuscript accessible. Requested prompt learning uses a header notification and cancel control.
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
OPENROUTER_PROVIDERS=deepseek,anthropic,openai,moonshotai,google,qwen
OPENROUTER_MIN_OUTPUT_PRICE=0.01
OPENROUTER_MAX_OUTPUT_PRICE=30
```

| Variable | Behavior |
| --- | --- |
| `OPENROUTER_API_KEY` | Always authenticates model discovery. Never exposed to the browser. |
| `OPENROUTER_DEV_MODE` | When `true`, all inference uses the server key instead of users' keys. Keep `false` on shared production deployments. |
| `OPENROUTER_MODEL` | Initial default model for users who have not selected one. It must pass the configured model filters. |
| `OPENROUTER_PROVIDERS` | Comma-separated provider IDs allowed in dropdowns, in display order. |
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

The sticky status band provides chunk navigation, Edit/Review, Editorial setup and Download DOCX. Edit opens the proposed text in Quill; Review uses the full workspace for interactive diffs. Pending suggestions appear in the editable text; rejected suggestions use their original wording. Typing does not pause work or archive the active review. Edits save after ten seconds, or immediately when navigating chunks or clicking a review control. Switching back to Review immediately computes a local diff from the unsaved draft, without sending or waiting for a save request. Autosave continues on its existing timer; the server recomputes the persisted revisions when saving completes. Diffs always compare with the immutable original source. The Saved indicator disappears after three seconds. Manually modifying a suggestion replaces that affected span with an accepted author revision. Unrelated pending/accepted/rejected decisions remain intact. A stale save produces a conflict without overwriting the draft.

Each chunk has a `revision` and `ai_edit_count`. Only successfully committed model outputs increment the AI counter; manual edits do not. Restart opens setup and creates a new pass from accepted/manual text. With the same chunk size, boundaries, original text and counts are carried forward. Changing the chunk size creates new chunk identities/counters and maps their baselines back to the original imported text. Existing completed chunks are initialized with a count of one by the migration because older call counts were not recorded.

Processed chunks remain editable while other chunks are running. Unprocessed chunks have a clear label and are read-only until their result arrives. Source snapshots, not rich-text HTML, are stored: Quill supports plain text and real italics. Italic spans are stored as Unicode ranges alongside review segments; arbitrary rich-text HTML is not stored. Export includes accepted and manual edits by default, with pending suggestions included only when explicitly selected. Export revision authors are labelled `LE Editor`; they are not a keystroke-by-keystroke audit trail.

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
node --test tests/*.test.js
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

Reported run cost covers chunk-editing calls, not metadata or prompt-learning calls. Failed requests may still be billed. Prompt learning uses the book's selected model. No automatic budget enforcement or billing system is included. Manuscripts are capped at 20 MB upload / one million extracted characters; large histories and histories will need retention policies at scale; live polling already loads only the selected chunk.

Quill is pinned to 2.0.2; the application reads text and italic attributes from Quill Delta operations and does not use Quill HTML export. This avoids adding the HTML-export advisory reported for 2.0.3.

The library provides History, reviewed DOCX download and confirmed deletion for each book. History contains editorial runs, manuscript snapshots and prompt versions. Downloads from the library include approved and author changes only and never approve pending suggestions. The status band and work footer are both 40 pixels high, with compact controls and footer notifications.

### Fiction / non-fiction presets and italic emphasis

Editorial setup offers Fiction and Nonfiction modes. Nine shared goals (grammar/spelling, vocabulary, sentence flow, clarity, formal tone, casual tone, emotional impact, poetic language and italics) each have separate English and Turkish instructions for both genres. Switching genres carries shared selections across and immediately rebuilds the combined prompt. General presets remain shared; poetry presets appear under Fiction. Existing specialized and custom presets remain available. Formal and casual goals are mutually exclusive.

The italic toolbar button (or Ctrl+I) applies real formatting to selected manuscript text. Formatting-only edits participate in autosave and immediate draft review. AI italic suggestions can be accepted or rejected, and unapproved italics are excluded from the default DOCX download. Word exports use `w:i` and tracked formatting revisions (`w:rPrChange`) for otherwise unchanged text; inserted text retains tracked insertion markup. Accepted italics survive reruns, rechunking and manuscript-version restoration. DOCX imports now retain original paragraph and run formatting, including inherited heading/character styles. The original package is reused during export.

The LLM continues to return the edited excerpt, not a JSON diff. A narrow `<le-italic>...</le-italic>` convention carries italic spans; the server validates and removes these markers before storing text. Browser rendering creates text and emphasis nodes rather than executing model HTML. Native Word rendering of this new formatting has not been manually verified; OOXML structure and range mapping are covered by automated tests.

Deploy this update with `php artisan migrate --force` and `npm ci && npm run build`. The migration adds the new built-in presets without changing book prompts or custom presets. `php artisan db:seed --force` remains idempotent.

### Original DOCX formatting and efficient state updates

Imports preserve heading styles, bold/italic/underline/strike, fonts, font sizes, colors, superscript/subscript and the original paragraph/run properties. Word export reuses the uploaded package, retaining its style definitions, numbering, section settings, page headers/footers and table containers. Untouched paragraphs are copied intact; edited text inherits the styles of its source passage. The browser presents a text-oriented view of these styles, not Word’s full paginated layout. Embedded fields, drawing placement and other complex content inside edited paragraphs are not guaranteed to retain full fidelity.

Older uploads recover formatting lazily from their retained original DOCX when the extracted text matches the stored original. Missing or mismatching originals cannot be recovered automatically. Run `php artisan migrate --force` and rebuild assets for the formatting metadata update.

Editor state responses contain compact queue status and three change tokens. Unchanged polls omit manuscript text, prompts and run details; progress-only updates omit the chunk. Changed chunks exclude duplicate source/output copies, loaded ORM relations and raw Word XML. The library contains no queue notice and makes no queue/state polling requests. Work resumes when a queue-enabled page (such as the editor) is open; an already-running request can still finish after leaving it.

### API request history

New manuscripts default to 1,000 words per chunk; existing book settings are preserved. Run `php artisan migrate --force` and `npm run build` when deploying this update.

Each book’s **API history** link lists completion requests newest first, 50 per page, including metadata inference, editorial passes, and prompt learning. The database stores full request and response bodies (including failed responses), HTTP status, input/output/total tokens and provider-reported USD cost. Missing usage is shown as not reported. Authentication headers are excluded and echoed API keys are redacted. Logs contain manuscript content, are accessible only to the book owner, and are deleted with the book. Catalog lookups are not manuscript requests and are not included.

Review renders paragraph boundaries with compact spacing and coalesces identical adjacent visual styles. Import keeps paragraph boundaries and merges identical neighboring Word runs without changing text. LLM payloads contain manuscript text and optional `<le-italic>` markers, not browser HTML or font CSS. Export retains namespace declarations on detached Word properties, including modern revision attributes.

### Editorial setup and thinking

Book title, manuscript language, context and chunk size are edited from **Book settings** on the library page. Editorial setup contains presets, prompt, model and model-aware Low/Medium/High thinking (default Low). Dropdowns prefer the saved book/user model when it remains in the filtered catalog, then `OPENROUTER_MODEL`, otherwise require selection. Reopening editorial setup also restores the current run’s thinking level.

Thinking-capable models with numeric budgets receive 0.5×/1×/2× the estimated edited-text token allowance (minimum 1,024). Effort-only models receive a supported effort and additional estimated headroom. Total output includes edited-text allowance, thinking allowance and **10,000 extra tokens**. Model limits are checked before sending; oversized chunks require a smaller chunk size. Effort cannot guarantee a precise thinking cap. Requests log the actual reasoning parameters. Run migrations and rebuild assets when deploying.

Starting from editorial setup offers **Continue unfinished chunks** or **Redo all chunks** when completed chunks exist. Continuing retains chunk boundaries, completed suggestions, decisions and manual edits, applying the updated prompt/model/thinking settings only to remaining chunks. Redo all starts a fresh pass with approved edits applied and other suggestions discarded.

AI-only paragraph-end space/tab changes are automatically rejected and omitted from review. Original spacing is retained; paragraph-break changes, internal word spacing and manual edits remain reviewable. Deploy migrations to clean existing cosmetic suggestions.

DOCX exports use `le-editor-YYYY-MM-DD-A-original.docx`. The date uses the application timezone. Each book advances its daily suffix through A–Z and stays at Z for further exports that day; the next day starts at A. New uploads preserve their original filename. Older uploads without a stored original name use `manuscript-ID.docx`.

API history summaries display per-request tokens and USD cost. The compact heading includes totals across all pages for that book, including reported usage from failed requests. Missing usage is flagged rather than treated as confirmed zero cost.

API history, prompt library, settings and account pages do not mount the queue notice or initiate queue polling.

When the account queue is processing another book, the editor footer names that book and indicates that the displayed book is waiting.

Before starting with a different model, setup recommends the configured default when it exists in the filtered catalog. The warning starts with an unchecked override: Start editing uses the default unless the user explicitly keeps the selected model. Cancel/Escape returns to setup without saving or starting the run.

Resuming a book or starting an editorial pass leaves other cancelled books paused. The account lock still permits only one request at a time; pause remains account-wide.

Export uses original Word paragraph boundaries and distinguishes double soft line breaks inside a paragraph, preventing missing paragraph-anchor errors.
