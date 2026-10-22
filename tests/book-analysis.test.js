import { test } from 'node:test';
import assert from 'node:assert/strict';
import { startBookAnalysis } from '../resources/js/book-analysis.js';
test('review setup is idle, submission starts a step, completion stops spinner', async () => {
 const elements = new Map();
 function element(id) { if (!elements.has(id)) elements.set(id,{hidden:false,dataset:{},value:'',listeners:{},addEventListener(event,fn){this.listeners[event]=fn;},showModal(){this.open=true;},close(){this.open=false;}});return elements.get(id); }
 const $ = element;
 const dialog=$('#analysis-dialog');dialog.dataset.user='1';
 const form=$('#analysis-form');form.elements={prompt:element('prompt'),model:{value:'test/model'},word_count:{value:300}};
 const opener=element('opener');opener.dataset={analysisBook:'1',analysisKind:'summary'};
 globalThis.document={hidden:false,addEventListener(){}};
 globalThis.sessionStorage={setItem(){},removeItem(){},getItem(){return null;}};
 const calls=[];let finish;
 const request=async(url)=>{calls.push(url);if(url==='/analysis-prompts')return {prompts:{summary:'Summarize'}};if(url==='/books/1/analyses')return {id:1,book_id:1,kind:'summary',status:'queued'};if(url==='/analyses/1/step')return new Promise(resolve=>{finish=resolve;});throw Error(url);};
 startBookAnalysis({$, $$:selector=>selector==='[data-analysis-book]'?[opener]:[],request,t:key=>key});
 await opener.listeners.click();assert.equal($('#analysis-spinner').hidden,true);assert.equal($('#analysis-status-panel').hidden,true);
 await form.listeners.submit({preventDefault(){}});
 assert.ok(calls.includes('/analyses/1/step'));assert.equal($('#analysis-spinner').hidden,false);
 finish({id:1,book_id:1,kind:'summary',status:'completed',result:'Summary'});
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal($('#analysis-spinner').hidden,true);assert.equal($('#analysis-status').textContent,'analysis_completed');assert.equal($('#analysis-result').hidden,false);
});
