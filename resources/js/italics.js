import { sliceStyles, styleNode } from './source-styles.js';
import { chunkText } from './chunk-text.js';
export const sliceRanges = (ranges, start, length) => ranges.filter(([a,b]) => Math.max(a,start) < Math.min(b,start+length)).map(([a,b]) => [Math.max(a,start)-start, Math.min(b,start+length)-start]);
export function italicRanges(segments = []) {
    let offset = 0;
    const result = segments.flatMap(s => {
        const after = s.type === 'equal' || s.status !== 'rejected';
        const ranges = (after ? s.italics || [] : s.before_italics || []).map(([a,b]) => [a+offset,b+offset]);
        offset += Array.from(after ? s.after : s.before).length;
        return ranges;
    });
    return result.reduce((merged, range) => { if (merged.at(-1)?.[1] === range[0]) merged.at(-1)[1] = range[1]; else merged.push([...range]); return merged; }, []);
}
export function applyItalics(segments, ranges) {
    let offset = 0;
    return segments.flatMap(s => {
        const display = chunkText({segments:[s]}), chars = Array.from(display);
        const local = sliceRanges(ranges, offset, chars.length); offset += chars.length;
        if (JSON.stringify(italicRanges([s])) === JSON.stringify(local)) return [s];
        if (s.type !== 'equal') return [{...s, type: s.before === display && JSON.stringify(local) === JSON.stringify(s.before_italics || []) ? 'equal' : 'change', after: display, italics: local, origin: 'manual', status: 'accepted'}];
        const old = italicRanges([s]);
        const bounds=[...new Set([0,chars.length,...local.flat(),...old.flat()])].sort((a,b)=>a-b),result=[];
        for(let i=0;i+1<bounds.length;i++){
            const a=bounds[i],b=bounds[i+1];if(a===b)continue;
            const text=chars.slice(a,b).join(''),before=sliceRanges(s.before_italics||old,a,b-a),after=sliceRanges(local,a,b-a);
            result.push({type:JSON.stringify(before)===JSON.stringify(after)?'equal':'change',before:text,after:text,status:'accepted',origin:'manual',italics:after,before_italics:before,styles:sliceStyles(s.styles||[],a,b-a),before_styles:sliceStyles(s.before_styles||[],a,b-a)});
        }
        return result;
    });
}
export function italicOps(text, ranges, styles = []) {
    const chars=Array.from(text), ops=[]; let lineHeader=false;
    function add(insert,attributes){const last=ops.at(-1);if(last && JSON.stringify(last.attributes)===JSON.stringify(attributes))last.insert+=insert;else ops.push({insert,attributes});}
    chars.forEach((char,index)=>{
        const source=styles.find(s=>s.start<=index&&s.end>index)?.attributes||{};
        if(char==='\n'){add(char,lineHeader?{header:lineHeader}:{});lineHeader=false;return;}
        const attrs={...source};delete attrs.header;delete attrs.italic;
        if(source.header)lineHeader=source.header;
        if(ranges.some(([a,b])=>a<=index&&b>index))attrs.italic=true;
        add(char,attrs);
    });
    add('\n',lineHeader?{header:lineHeader}:{});return ops;
}
export function readItalics(ops, length) {
    let offset=0;const ranges=[];
    for(const op of ops){const end=Math.min(length,offset+Array.from(op.insert||'').length);if(op.attributes?.italic && end>offset){if(ranges.at(-1)?.[1]===offset)ranges.at(-1)[1]=end;else ranges.push([offset,end]);}offset=end;}
    return ranges;
}
export function appendFormatted(parent, text, ranges = [], styles = []) {
    const chars=Array.from(text), bounds=[...new Set([0,chars.length,...ranges.flat(),...styles.flatMap(s=>[s.start,s.end])])].sort((a,b)=>a-b);
    for(let i=0;i+1<bounds.length;i++){
        const a=bounds[i],b=bounds[i+1];if(a===b)continue;
        const attrs=styles.find(s=>s.start<=a&&s.end>a)?.attributes||{};
        const node=document.createElement(ranges.some(([start,end])=>start<=a&&end>a)?'em':'span');
        styleNode(node,attrs);node.textContent=chars.slice(a,b).join('');
        const previous = parent.lastElementChild;
        if (previous && previous.tagName === node.tagName && previous.getAttribute('style') === node.getAttribute('style') && !previous.className) previous.textContent += node.textContent;
        else parent.append(node);
    }
}
