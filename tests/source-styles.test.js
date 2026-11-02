import {test} from 'node:test';
import assert from 'node:assert/strict';
import {projectStyles,annotateStyles,chunkStyles} from '../resources/js/source-styles.js';
import {italicOps,italicRanges,applyItalics,readItalics} from '../resources/js/italics.js';
import {draftSegments} from '../resources/js/draft-review.js';
test('replacement words inherit imported bold and heading attributes',()=>{
 const styles=[{start:0,end:7,attributes:{bold:true,header:1}}];
 const projected=projectStyles('Chapter','Section',styles);
 assert.deepEqual(projected,styles);
 const ops=italicOps('Section',[],projected);
 assert.equal(ops[0].attributes.bold,true);
 assert.equal(ops.at(-1).attributes.header,1);
});
test('original italics are baseline formatting and removing them is reviewable',()=>{
 const s=[{type:'equal',before:'İtalik',after:'İtalik',status:'accepted',italics:[[0,6]],before_italics:[[0,6]]}];
 assert.deepEqual(applyItalics(s,[[0,6]]),s);
 const removed=applyItalics(s,[]);
 assert.equal(removed[0].type,'change');
 assert.deepEqual(removed[0].before_italics,[[0,6]]);
 removed[0].status='rejected';assert.deepEqual(italicRanges(removed),[[0,6]]);
});
test('immediate draft review and Quill retain source styles without changing text',()=>{
 const spans=[{start:0,end:4,attributes:{bold:true}},{start:9,end:15,attributes:{italic:true}}];
 const original=[{type:'equal',before:'Bold and italic',after:'Bold and italic',status:'accepted',styles:spans,before_styles:spans,italics:[[9,15]],before_italics:[[9,15]]}];
 const draft=annotateStyles(draftSegments(original,'Strong and italic'),original);
 const styled=applyItalics(draft,[[11,17]]);
 const ops=italicOps('Strong and italic',italicRanges(styled),chunkStyles(styled));
 assert.equal(ops.map(op=>op.insert).join(''),'Strong and italic\n');
 assert.equal(ops[0].attributes.bold,true);
 assert.deepEqual(readItalics(ops,17),[[11,17]]);
});


test('pure insertions inherit the source font in review spans and Quill ops',()=>{
 const attributes={font:'Times New Roman',size:'12pt'};
 const spans=[{start:0,end:3,attributes}];
 const previous=[{type:'equal',before:'İlk',after:'İlk',before_styles:spans,styles:spans}];
 const equal=text=>({type:'equal',before:text,after:text});
 const insert={type:'change',before:'',after:'Yeni🙂',status:'pending'};
 for(const segments of [[insert,equal('İlk')],[equal('İ'),insert,equal('lk')],[equal('İlk'),insert]]){
  const result=annotateStyles(segments,previous);
  const addition=result.find(s=>s.before==='');
  assert.deepEqual(addition.styles,[{start:0,end:5,attributes}]);
  assert.deepEqual(addition.before_styles,[]);
  const text=result.map(s=>s.after).join('');
  const ops=italicOps(text,[],chunkStyles(result));
  assert.equal(ops[0].insert,text);
  assert.deepEqual(ops[0].attributes,attributes);
 }
 assert.deepEqual(projectStyles('İlk','İlk yeni',spans),[{start:0,end:3,attributes},{start:3,end:8,attributes}]);
 assert.deepEqual(annotateStyles([insert],[])[0].styles,[]);
});
