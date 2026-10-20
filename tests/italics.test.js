import {test} from 'node:test';
import assert from 'node:assert/strict';
import {applyItalics,italicOps,italicRanges,readItalics} from '../resources/js/italics.js';
import {draftSegments} from '../resources/js/draft-review.js';
import {chunkText} from '../resources/js/chunk-text.js';
test('Quill italic ranges round trip Unicode and omit terminal newline',()=>{
 const text='İyi 🌿 sözcük';const ranges=[[4,5],[6,12]];
 assert.deepEqual(readItalics(italicOps(text,ranges),Array.from(text).length),ranges);
});
test('format-only draft edits are visible immediately and removable',()=>{
 const original=[{type:'equal',before:'İyi 🌿 sözcük',after:'İyi 🌿 sözcük',status:'pending'}];
 const draft=applyItalics(original,[[4,5]]);
 assert.deepEqual(italicRanges(draft),[[4,5]]);
 assert.equal(draft[1].type,'change'); assert.equal(draft[1].status,'accepted');
 assert.equal(chunkText({segments:draft}),original[0].before);
 assert.deepEqual(italicRanges(applyItalics(draft,[])),[]);
 assert.deepEqual(italicRanges(original),[]);
});
test('draft text editing and italic formatting preserve unrelated pending suggestions',()=>{
 const segments=[{type:'change',before:'Old',after:'New',status:'pending'}, {type:'equal',before:' ending',after:' ending',status:'pending'}];
 const draft=applyItalics(draftSegments(segments,'New conclusion'),[[4,14]]);
 assert.deepEqual(draft[0],segments[0]);
 assert.equal(chunkText({segments:draft}),'New conclusion');
 assert.deepEqual(italicRanges(draft),[[4,14]]);
});
