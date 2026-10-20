import { test } from 'node:test';
import assert from 'node:assert/strict';
import { draftSegments } from '../resources/js/draft-review.js';
import { chunkText } from '../resources/js/chunk-text.js';
const equal = value => ({ type: 'equal', before: value, after: value, status: 'pending' });
const change = (before, after, status = 'pending') => ({ type: 'change', before, after, status, origin: 'ai' });
test('unsaved preview preserves unrelated pending and rejected decisions', () => {
    const segments = [change('Old', 'New'), equal(' sentence. '), change('bad', 'good', 'rejected'), equal(' ending')];
    const result = draftSegments(segments, 'New sentence. bad conclusion');
    assert.deepEqual(result[0], segments[0]);
    assert.ok(result.some(s => s.status === 'rejected' && s.before === 'bad'));
    assert.ok(result.some(s => s.origin === 'manual' && s.after === 'conclusion'));
    assert.equal(chunkText({ segments: result }), 'New sentence. bad conclusion');
    assert.equal(segments.at(-1).after, ' ending');
});
test('manual replacement of pending deletion keeps original text as baseline', () => {
    const result = draftSegments([change('Eski', ''), equal(' son')], 'Yeni son');
    assert.equal(result.map(s => s.before).join(''), 'Eski son');
    assert.equal(chunkText({ segments: result }), 'Yeni son');
});
test('draft mapping preserves source and edited text for unicode and repeated spans', () => {
    const segments = [equal('İstanbul 🌿 '), change('eski', 'yeni'), equal(' tekrar tekrar\n'), change('sil', ''), equal(' son')];
    const old = chunkText({ segments });
    const points = Array.from(old);
    for (let start = 0; start <= points.length; start++) {
        for (let end = start; end <= points.length; end++) {
            const draft = points.slice(0, start).join('') + 'ş✨' + points.slice(end).join('');
            const result = draftSegments(segments, draft);
            assert.equal(chunkText({ segments: result }), draft);
            assert.equal(result.map(s => s.before).join(''), segments.map(s => s.before).join(''));
        }
    }
});
