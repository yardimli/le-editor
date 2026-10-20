import { diffArrays } from 'diff';
export function sliceStyles(spans, start, length) {
 return spans.filter(s=>Math.max(start,s.start)<Math.min(start+length,s.end)).map(s=>({...s,start:Math.max(start,s.start)-start,end:Math.min(start+length,s.end)-start}));
}
export function projectStyles(before, after, spans) {
 if(before===after)return spans;
 const tokens=t=>t.split(/(\s+|[\p{P}\p{S}])/u).filter(Boolean);
 const parts=[];
 for(const p of diffArrays(tokens(before),tokens(after))){const a=p.added?'':p.value.join(''),b=p.removed?'':p.value.join('');const last=parts.at(-1);if((p.added||p.removed)&&last?.change){last.before+=a;last.after+=b;}else parts.push({before:a,after:b,change:!!(p.added||p.removed)});}
 let old=0,next=0; const result=[];
 for(const p of parts){const a=Array.from(p.before).length,b=Array.from(p.after).length;
  if(a&&b)for(const s of sliceStyles(spans,old,a)){const start=next+Math.floor(s.start*b/a),end=next+Math.floor(s.end*b/a);if(start<end)result.push({...s,start,end});}
  else if(b)for(const s of sliceStyles(spans,Math.min(old,Math.max(0,Array.from(before).length-1)),1))result.push({...s,start:next,end:next+b});
  old+=a;next+=b;
 }
 return result;
}
export function annotateStyles(segments, previous) {
 let offset=0;const spans=previous.flatMap(s=>{const value=(s.before_styles||[]).map(x=>({...x,start:x.start+offset,end:x.end+offset}));offset+=Array.from(s.before).length;return value;});
 offset=0;return segments.map(s=>{const before=sliceStyles(spans,offset,Array.from(s.before).length);offset+=Array.from(s.before).length;return {...s,before_styles:before,styles:projectStyles(s.before,s.after,before),before_italics:before.filter(x=>x.attributes?.italic).map(x=>[x.start,x.end])};});
}
export function chunkStyles(segments=[]) {
 let offset=0;return segments.flatMap(s=>{const after=s.type==='equal'||s.status!=='rejected';const result=(s[after?'styles':'before_styles']||[]).map(x=>({...x,start:x.start+offset,end:x.end+offset}));offset+=Array.from(s[after?'after':'before']).length;return result;});
}
export function styleNode(node, attrs={}) {
 if(attrs.bold)node.style.fontWeight='700';
 if(attrs.underline)node.style.textDecoration='underline';
 if(attrs.strike)node.style.textDecoration=(attrs.underline?'underline ':'')+'line-through';
 if(/^#[a-f0-9]{6}$/i.test(attrs.color||''))node.style.color=attrs.color;
 if(/^[\d.]+pt$/.test(attrs.size||''))node.style.fontSize=attrs.size;
 if(attrs.font)node.style.fontFamily=attrs.font;
 if(attrs.header){node.style.fontWeight='700';if(!attrs.size)node.style.fontSize=({1:'2em',2:'1.5em',3:'1.2em'})[attrs.header]||'1.1em';}
 if(attrs.script) {node.style.verticalAlign=attrs.script==='super'?'super':'sub';node.style.fontSize='.75em';}
}
