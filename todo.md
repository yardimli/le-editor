# LE Editor implementation plan

## Agreed behavior

- Turkish by default; English optional. Warm paper light theme and dark theme.
- Laravel 10, Breeze authentication, Blade/HTML, Tailwind and Vite; deployment builds assets with `npm run build`.
- Upload DOCX; infer editable title and manuscript language with an LLM using the first 2,000 words.
- Ten genre/intensity presets (fiction, nonfiction, poetry, general); combine multiple presets and customize the book prompt. Personal presets supported.
- OpenRouter user keys encrypted; `OPENROUTER_DEV_MODE=true` uses the server key. Model discovery always uses the server key.
- Exclude free models; filter output USD per million tokens with environment minimum/maximum.
- Chunk size defaults to 2,500 words and is editable before every run. LLM returns plain edited text; a diff library computes suggestions.
- Browser-driven AJAX editing with a non-dismissible progress dialog. Explicit cancellation retains completed suggestions and untouched remaining text.
- Accept/reject individual changes; feedback refines the book prompt asynchronously with revision history.
- Rerun applies accepted changes, discards other active suggestions, and preserves run history.
- Manual editing with tracked revisions, optimistic concurrency, 10-second debounced autosave and Save now.
- DOCX download contains real Word tracked changes. Approved edits included by default; optional pending edits affect export only. Rejected edits excluded.

## Build checklist

- [x] Laravel 10/Breeze scaffold and environment configuration
- [x] Database schema, ownership protection, encrypted keys
- [x] Bilingual presets, multi-selection and custom prompts
- [x] OpenRouter catalog filtering, metadata inference and completion client
- [x] DOCX text import and tracked-change export
- [x] Chunking, word diff, run snapshots and review decisions
- [x] Persistent browser queue, progress, cancellation and retry
- [x] Prompt learning and version restoration
- [x] Manual manuscript revisions and autosave
- [x] Bilingual Blade screens, paper/dark themes and responsive review interface
- [x] Settings, account deletion, book deletion and history
- [x] Validation, output sanity checks, cost/usage visibility
- [x] Automated workflow/security/export tests
- [x] Production asset build and database migration
- [x] README setup/deployment instructions and documented limits

## Validation / follow-up

- [x] Live OpenRouter connectivity and model discovery (419 filtered models; configured default available)
- [x] Browser smoke check: Turkish login/library, model dropdown, paper/dark themes, inline acceptance
- [ ] Complete browser acceptance pass for autosave/progress/export dialogs (initial native confirmation stalled the in-app browser; replaced with HTML dialogs)
- [ ] Live paid editing trial with a representative manuscript/model (automated tests use HTTP fakes)
- [ ] Microsoft Word tracked-change visual compatibility check (requires Word)
- [ ] Production hosting, mail delivery, TLS, backup and PHP-FPM configuration (deployment environment)

## Scope notes

The initial document editor is text-focused. Paragraphs and line breaks are preserved;
advanced DOCX layout, images, tables, footnotes and original styling are not round-tripped.
Imported existing revisions are read as their accepted text. Exports show the cumulative
difference from imported text, including manual changes and approved AI changes.
Book-local learned prompts never silently overwrite shared presets.

## Validation results

- Automated tests passed (see current validation below); isolated SQLite tests leave the configured MySQL database intact.
- `npm run build` succeeded. Production routes and Blade views compile/cache successfully.
- MySQL migrations and the ten-preset seeder completed in the configured database.
- DOCX tests verify OOXML revision elements, accepted/pending/rejected export selection, unchanged documents and metadata sampling.
- Cancellation races, stale manuscript saves, encrypted key selection and ownership are covered.
- `.env` credentials preserved. Added development flag (false by default), output-price limits, application key and database queue configuration. Debug output disabled.
- README documents Ubuntu setup, browser queue operation, deployment builds, document limitations and dependency audit findings.

## Before public deployment

- [ ] Resolve Laravel 10 framework advisories through a supported framework upgrade or maintained security patches.
- [ ] Upgrade the Tailwind 3 build dependency chain to address reported parser denial-of-service advisories.
- [ ] Confirm DOCX formatting expectations for real manuscripts; this version intentionally exports text with revisions, not original layout.
## Browser-driven queue update

- [x] Replace all editor job dispatch/chaining with authenticated AJAX steps.
- [x] Persist pending work and account-wide cancellation in the database.
- [x] Resume on return/refresh; require explicit resume/new AI action after cancellation.
- [x] Fence late responses and serialize competing tabs with expiring database leases.
- [x] Global non-dismissible busy dialog with spinner, chunk progress and cancellation.
- [x] Retain completed chunks when cancelled and leave remaining text unchanged.
- [x] Configure default interface language using APP_LOCALE=tr; preserve user EN/TR preferences.
- [x] Remove Ubuntu worker/Supervisor requirements; document concurrent PHP requests and timeouts.
- [x] Verify new queue regressions, production asset build and migration.
- [ ] Browser acceptance on deployed Ubuntu/PHP-FPM: close/return, refresh, multiple tabs and cancel during a real provider request.

Current validation: 49 PHP tests passed (218 assertions), plus 4 JavaScript scheduler tests. Production assets built, routes/Blade compiled successfully, and the browser-queue migration was applied to the configured database. Live Ubuntu/PHP-FPM and paid-provider browser acceptance remains pending.
