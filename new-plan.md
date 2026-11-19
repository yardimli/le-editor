# Conversational manuscript editorial workflow

Checkboxes are marked complete as implementation and verification finish.

- [x] Read-only manuscript: remove manual editing, per-block reruns, accept/reject/undo and decision counts; preserve visible edits and tracked Word exports.
- [x] Full-height right chat panel under the header, scrollable bubbles, composer fixed at bottom; responsive layout.
- [x] One Send button; Enter sends, Shift+Enter inserts a newline; support IME composition.
- [x] Assistant opening invitation; wait for empty-message agreement or author instructions before reading the first 5,000 words.
- [x] Infer title, evidenced author, language and fiction/nonfiction; provide checkbox tasks with manuscript-specific reasons.
- [x] Persist checkbox selections immediately across reloads; submit with message and lock submitted sets read-only.
- [x] Allow fresh suggestion sets and update the editorial prompt from submitted selections and author instructions.
- [x] LLM interprets start/yes/pause/stop/continue/restart; retain pause button and record pauses in chat.
- [x] Restart asks whether to retain edits or use original, updates plan, and confirms before restarting.
- [x] Send last five completed user/assistant pairs plus current message, selections, plan and run state; use UI language.
- [x] Report block observations; pause before committing a materially deviating block and ask a follow-up; update prompt before continuing.
- [x] Remove learning statistics, model/thinking shortcut, separate Start button and Continue/redo selector.
- [x] Preserve finished manuscripts and authorial voice; final human review stays in Word; keep configurable revision author.
- [x] Verify transitions, ownership, persisted selections, restart baselines, deviations, exports, keyboard behavior and production build.

## Implementation notes

- Started: inspecting current chat, browser queue, chunk editing and export paths.

- Implemented persistent suggestion selections with optimistic version checks and immutable submitted sets.
- Replaced the editable manuscript UI with read-only tracked differences and a full-height chat/composer.
- Added conversation actions, explicit restart source/confirmation, five completed conversation pairs, and account-lease fencing before interpreting messages.
- Added deviation pauses before applying a block, plus localized opening, pause and completion messages. The opening is a stored assistant greeting; no paid request or manuscript analysis occurs before consent.
- Verification in progress; checklist implementation items will be revisited if tests expose gaps.

## Verification completed

- PHP: 122 tests passed (1,642 assertions), including selection ownership/version conflicts, immutable submitted sets, consent, start, restart source and confirmation, five-pair context, deviation pauses, response fencing, and Word export regression coverage.
- JavaScript: 29 tests passed, including read-only changes, Enter/Shift+Enter/IME, empty consent, and saving checkbox selections before submission.
- Production Vite build succeeded; the Quill editor is no longer loaded by the manuscript view.
- Visually checked the actual Blade/JavaScript layout using a local synthetic-data preview at desktop and 390px mobile widths. Fixed header alignment, document padding and export-footer sizing during that check.
- Applied the conversation migration locally and refreshed cached Blade views.
- LLM responses were deterministic test doubles. Real-provider intent accuracy and editorial judgment still require a real manuscript trial; no paid completion was made for verification.

## Deployment

Run `php artisan migrate --force`, `npm ci && npm run build`, and `php artisan view:cache` on the server after deploying the source changes. The migration preserves existing manuscripts and exports.
