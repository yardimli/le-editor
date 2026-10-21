# LE Editor implementation plan

## Agreed behavior

- Turkish by default; English optional. Warm paper light theme and dark theme.
- Laravel 10, Breeze authentication, Blade/HTML, Tailwind and Vite; deployment builds assets with `npm run build`.
- Upload DOCX; infer editable title and manuscript language with an LLM using the first 2,000 words.
- Ten genre/intensity presets (fiction, nonfiction, poetry, general); combine multiple presets and customize the book prompt. Personal presets supported.
- OpenRouter user keys encrypted; `OPENROUTER_DEV_MODE=true` uses the server key. Model discovery always uses the server key.
- Exclude free models; filter output USD per million tokens with environment minimum/maximum.
- Chunk size defaults to 1,000 words and is editable before every run. LLM returns plain edited text; a diff library computes suggestions.
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

## Editorial setup dialog

- [x] Move all sidebar controls into a large responsive dialog; manuscript uses the page width.
- [x] Automatically open setup for books with no editing runs; add a heading button to reopen it.
- [x] Update the editable prompt immediately when presets are selected or deselected.
- [x] Save settings when starting; remove the separate Save and Combine buttons.
- [x] Allow Escape/Cancel dismissal and ignore backdrop clicks; show submission errors inside the dialog.

## Branding

- [x] Generated compact book-and-pen site logo (256 x 256 PNG).
- [x] Added square 16/32px favicons, ICO fallback and 180px Apple touch icon.
- [x] Connected branding to the shared layout and application logo component.

## Uninterrupted review

- [x] Accept/reject decisions never resume a cancelled editorial run or override a paused queue.
- [x] Show prompt-learning progress and cancel control in the header instead of the editing modal.
- [x] Add a narrow 40px sticky status band with notifications on the right.
- [x] Limit the header and page content to 1280px.

## Chunk workspace and optional learning

- [x] Paginate the manuscript by chunk; load only the selected chunk in state requests.
- [x] Add previous/next, clickable chunk number/jump dialog, and processed-book progress.
- [x] Replace the editorial modal with a persistent footer and Pause / Resume / Restart.
- [x] Review processed chunks while later chunks run; refresh newly processed visible chunks.
- [x] Preserve immutable chunk source, author revisions and independent chunk version checks.
- [x] Add Quill plain-text editing alongside interactive diff controls and autosave.
- [x] Count successful AI edits per chunk; carry counts across passes with unchanged boundaries.
- [x] Collect feedback without automatic LLM calls; offer learning after 21 decisions with a dismissible hint.
- [x] Verify chunk concurrency, manual revision mapping, optional learning, UI build and migrations.

Chunk workspace validation: 59 PHP tests passed (723 assertions), 9 JavaScript tests passed, production assets built and migration applied. Browser-verified simultaneous Quill editing/review, saving before review actions, direct chunk navigation, pending labels and live completion updates using a temporary isolated fixture. No paid completion calls. Preview: `docs/chunk-editor-preview.png`.

## Compact editor and library actions
- [x] Move chunk navigation and setup/download/Edit controls into the sticky status band.
- [x] Move notifications into the compact 40px work footer with small queue controls.
- [x] Hide Saved after three seconds; simplify the book heading and remove its back link.
- [x] Switch between full-width Quill and review; compute unsaved diffs immediately without interrupting autosave.
- [x] Preserve original text and unrelated suggestion decisions in local draft previews.
- [x] Link book history from the library; add reviewed DOCX download and confirmed deletion.
- [x] Validate with 60 PHP tests (730 assertions), 12 JavaScript tests, Blade compilation and production build.

## Model provider selection and setup refinements
- [x] Add OPENROUTER_PROVIDERS to .env, example and configuration; include filters in catalog cache identity.
- [x] Group dropdowns by configured provider and sort by output cost, retaining price/free-model filters and excluding batch models.
- [x] Show a footer spinner for active LLM work and use active editing wording instead of Queued/Sırada.
- [x] List presets in one column with their main title only.
- [x] Add coverage for provider ordering/filtering/cache changes and footer spinner lifecycle.

## Genre-aware goals and real italics
- [x] Add all nine requested goals with separate fiction/nonfiction instructions in Turkish and English.
- [x] Persist the book genre; retain shared selections and immediately recombine wording when switching genre.
- [x] Keep general/custom presets and existing specialized presets; separate the library by genre.
- [x] Add real Quill italics, immediate draft preview, autosave and reviewable AI emphasis.
- [x] Preserve accepted italic ranges through reruns, rechunking and version restoration.
- [x] Export real Word italics and tracked formatting; omit pending formatting by default.
- [x] Browser-check genre selection, prompt recombination, italic editing, immediate review and saving with isolated test data.
- [x] Apply migration, validate bilingual presets/formatting/exports with tests, and build production assets.
- [ ] Manually verify italic revision appearance in native Microsoft Word (OOXML validated in tests).

## Imported DOCX formatting
- [x] Extract original run/paragraph properties and resolve inherited heading/character styles.
- [x] Preserve source styles in Quill/review, through text edits, and in tracked Word exports using the original package.
- [x] Treat imported italics as baseline formatting; support rejection of italic removal.
- [x] Recover formatting for older uploads when the retained source text matches.
- [x] Validate headings, bold, inherited italics, colors, table containers and page headers with OOXML tests and browser verification.
- [x] Apply formatting metadata migration and capture docs/imported-formatting-preview.png.

## Efficient browser polling
- [x] Use one combined editor/queue poll every three seconds, without a second editor timer.
- [x] Send only changed book, progress and chunk sections using revision tokens; unchanged response tested below 1 KB.
- [x] Remove duplicate manuscript copies, raw Word properties and ORM relationships from browser chunk responses.
- [x] Remove the library queue notice and all library queue/state polling.
- [x] Verify combined polling interval, partial responses and no library queue bootstrap with automated tests.

- [x] Default new manuscripts to 1,000-word chunks while preserving existing choices.
- [x] Persist full OpenRouter completion request/response logs, failures, usage and cost without API keys.
- [x] Add owner-only per-book API history, newest first, with 50-record pagination and cascade deletion.

- [x] Exclude image-named models from the model catalog and invalidate the previous catalog cache.

- [x] Remove redundant review heading; render compact review paragraphs and merge identical adjacent spans.
- [x] Coalesce identical imported runs and preserve namespace declarations during DOCX export.

- [x] Model-aware Low/Medium/High thinking, dynamic chunk budgets and 10,000-token margin.
- [x] Use filtered env model as dropdown default, otherwise require selection.
- [x] Move book settings to library dialog, compact editorial setup, and enable learning hint after 50 reviews.

- [x] Offer continue unfinished versus redo all from setup; preserve completed chunks and apply new settings to remaining work.

- [x] Restore saved model and run thinking level when reopening editorial setup, falling back to the filtered env model only when necessary.

- [x] Reject and hide AI paragraph-end spacing-only suggestions, including existing saved diffs, while preserving meaningful whitespace changes.

- [x] Preserve uploaded filenames and prefix exports with date and per-book daily A–Z suffix (capped at Z).

- [x] Compact API-history heading, collapsed-row token/cost details, and book-wide totals across pagination.

- [x] Remove queue notice and queue bootstrap from API history, prompts, settings and account pages.

- [x] Show the active book title and waiting status when the account queue is processing a different book.

- [x] Show the other-book waiting message only once in the footer, retaining the activity spinner.

- [x] Warn before starting with a non-default model; recommend the available env default, with unchecked explicit override and cancel back to setup.

- [x] Resume/start only the selected book without reviving other paused books.

- [x] Always export pending suggestions without auto-approval; remove export checkbox.
- [x] Fix DOCX paragraph-anchor mismatch when original paragraphs contain double soft breaks.

- [x] Set DOCX proofing language from manuscript language, including runs/styles, without adding formatting revisions.
