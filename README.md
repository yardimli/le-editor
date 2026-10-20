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
- A queue worker. Upload inference, editing and prompt learning do not run without it.
- An OpenRouter account/key for server-side model discovery. In normal mode each user supplies an inference key.

Laravel 10 is retained to match the project specification. See the security note below before public deployment.

## Local setup

```sh
composer install
cp .env.example .env
php artisan key:generate
# Configure database and OpenRouter values in .env.
php artisan migrate --seed
npm ci
npm run build
php artisan serve
```

If `.env` already exists, preserve it rather than copying over it. The repository never contains real API keys or database passwords.

Run the worker in a second terminal:

```sh
php artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=240
```

Open `http://127.0.0.1:8000`, register and visit Settings to save a key and select a default model. Test the saved connection before uploading. Configure a real mail transport for password-reset delivery; local defaults log emails.

For frontend development, use `npm run dev` instead of building after each change.

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
php artisan queue:restart
```

API references: [OpenRouter models](https://openrouter.ai/docs/api/api-reference/models/get-models) and [chat completions](https://openrouter.ai/docs/api/api-reference/chat/send-chat-completion-request).

## Editing and data semantics

The imported text remains immutable. The working manuscript is a separate value. Each editing run snapshots its prompt, context, language, model and chunk size. Review decisions are stored on diff segments; accepted changes become the next working text when rerunning or manually editing.

Manual editing begins with the accepted-text view. Its first changed save archives the active review and discards pending suggestions from that active view. This is explained before entering manual mode. Old reviews remain in history. Manual edits are stored as manuscript snapshots; exports compute cumulative revisions from the original import, including those manual edits. Export revision authors are labelled `LE Editor`; they are not a keystroke-by-keystroke audit trail.

A rerun discards pending/rejected suggestions from the new draft. Exporting pending suggestions never accepts them in the app. The original DOCX is retained privately until the book/account is deleted.

Cancellation is cooperative: already committed chunks remain, and an in-flight response is ignored if cancellation wins the database lock. An already-sent provider request may still incur a charge. Closing a browser tab cannot be prevented absolutely; the app warns on navigation and restores the progress dialog when reopened. The job continues until completed or explicitly cancelled.

Prompt-learning failures do not undo review decisions. They are visible with a retry control. Prompt edits use a version check so a background update cannot silently overwrite a manual prompt edit. Learning affects the next run, never chunks already in progress.

## DOCX scope

Import reads body text, paragraphs and line breaks, treating existing Word revisions as accepted text. Export creates a new OOXML package containing real insert/delete revisions and paragraph revision markers. In Word, select **Review → All Markup** to view them; Word's own display preferences may hide markup by default.

This release is text-focused. Original styles, page layout, images, headers/footers, comments, footnotes, lists and table structure are not round-tripped. Table cell text is flattened into paragraphs. Exports use a basic page layout. Complex manuscripts need a dedicated fidelity-preserving import/export layer before relying on their formatting.

## Production deployment

1. Serve only the `public/` directory through PHP-FPM and Nginx/Apache. Keep `.env`, `storage/`, source files and backups outside public access.
2. Install PHP dependencies with `composer install --no-dev --optimize-autoloader`.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, database credentials, a permanent `APP_KEY`, SMTP and OpenRouter configuration. Enable secure session cookies for HTTPS.
4. Run `php artisan migrate --force` and `php artisan db:seed --class=PromptSeeder --force`.
5. Build assets **on the deployment server** using `npm ci` followed by `npm run build`. Build dependencies must be installed for this step. `public/build` is intentionally not committed.
6. Run `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache`.
7. Make `storage/` and `bootstrap/cache/` writable by the application user.
8. Supervise the queue worker using Supervisor/systemd. Queue `retry_after` is 300 seconds, greater than the 240-second worker timeout. Restart workers after deployments.
9. Set PHP/web-server upload limits above 20 MB (`upload_max_filesize=20M`, `post_max_size=25M`) and suitable request timeouts. Editing itself runs in the queue.
10. Back up the database, private manuscripts and **the existing APP_KEY**. Regenerating that key makes saved API keys unreadable. Configure TLS, log rotation and monitoring.

File cache locks assume one application host. For multiple worker hosts, use a shared lock-capable cache (such as Redis), shared private storage and shared sessions. Do not run multiple independent file-cache hosts.

## Validation

```sh
php artisan test
npm run build
composer audit
npm audit
```

Tests force an isolated in-memory SQLite database and fake provider responses; they never reset the configured MySQL database or spend OpenRouter credits. Coverage includes authentication, ownership, encrypted keys, catalog filtering, Unicode/whitespace preservation, reruns, manual-save conflicts, cancellation races, metadata inference, prompt learning, output truncation and OOXML exports.

The live model catalog was checked successfully during development. Paid completion behavior is covered with deterministic HTTP fakes; a real manuscript/model trial remains a deployment acceptance check. Native Microsoft Word visual compatibility has not yet been verified.

## Security and operational limitations

The dependency audit reports known Laravel 10 advisories. The app rejects control characters in email inputs before trimming and production debug output must stay disabled. These application mitigations do not make an unsupported framework fully patched; upgrading Laravel is required before treating this as a hardened public service.

Tailwind 3's build-only dependency chain also has reported pattern-parser denial-of-service advisories. Builds operate on repository-owned templates; do not feed untrusted templates to the build process. Moving to Tailwind 4 is a separate toolchain upgrade.

Reported run cost covers chunk-editing calls, not metadata or prompt-learning calls. Failed requests may still be billed. Prompt learning uses the book's selected model. No automatic budget enforcement or billing system is included. Manuscripts are capped at 20 MB upload / one million extracted characters; large histories and whole-document polling will need pagination and retention policies at scale.
