import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boundedChunk, changeKey, chunkText } from '../resources/js/chunk-text.js';

test('editable draft includes pending suggestions, clean preview does not', () => {
    const chunk = { segments: [
        { type: 'change', before: 'Old', after: 'New', status: 'pending' },
        { type: 'equal', before: '\n\n', after: '\n\n', status: 'pending' },
        { type: 'change', before: 'wrong', after: 'right', status: 'accepted' },
        { type: 'change', before: ' end', after: ' ending', status: 'rejected' },
    ] };
    assert.equal(chunkText(chunk), 'New\n\nright end');
    assert.equal(chunkText(chunk, false), 'Old\n\nright end');
    assert.equal(chunkText({ source: 'Original', input_text: '' }), '');
});

test('change identity distinguishes repeated suggestions by original position', () => {
    const segments = [
        { before: 'old', after: 'new' },
        { before: ' ', after: ' ' },
        { before: 'old', after: 'new' },
    ];
    assert.notEqual(changeKey(segments, 0), changeKey(segments, 2));
});

test('chunk navigation clamps invalid and out of range positions', () => {
    assert.equal(boundedChunk(999, 202), 202);
    assert.equal(boundedChunk(-1, 202), 1);
    assert.equal(boundedChunk('7', 202), 7);
    assert.equal(boundedChunk('bad', 0), 1);
});
