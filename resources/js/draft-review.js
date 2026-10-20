import { sliceRanges } from './italics.js';
import { diffArrays } from 'diff';
import { chunkText } from './chunk-text.js';

function diff(before, after) {
    const tokens = text => text.split(/(\s+|[\p{P}\p{S}])/u).filter(Boolean);
    const result = [];
    for (const part of diffArrays(tokens(before), tokens(after))) {
        const value = part.value.join(''), type = part.added || part.removed ? 'change' : 'equal';
        const last = result.at(-1);
        if (type === 'change' && last?.type === 'change') {
            last.before += part.removed ? value : ''; last.after += part.added ? value : '';
        } else result.push({ type, before: part.added ? '' : value, after: part.removed ? '' : value, status: 'accepted', origin: 'manual' });
    }
    for (let i = 0; i + 2 < result.length; i++) {
        if (result[i].type === 'change' && result[i + 1].type === 'equal' && /^[ \t]*$/.test(result[i + 1].before) && result[i + 2].type === 'change') {
            result[i].before += result[i + 1].before + result[i + 2].before;
            result[i].after += result[i + 1].after + result[i + 2].after;
            result.splice(i + 1, 2); i--;
        }
    }
    return result;
}

// Local preview only. The server independently rebases and persists author revisions.
export function draftSegments(segments, text) {
    const old = chunkText({ segments });
    if (old === text) return segments;
    let offset = 0;
    const edits = diff(old, text).flatMap(s => {
        const start = offset; offset += s.before.length;
        return s.type === 'change' ? [{ start, end: offset, after: s.after }] : [];
    });
    offset = 0;
    let units = segments.map(segment => {
        const start = offset; offset += chunkText({ segments: [segment] }).length;
        return { start, end: offset, segment };
    });
    const ranges = [];
    for (const edit of edits) {
        const range = { start: edit.start, end: edit.end, edits: [edit] };
        for (const unit of units) {
            if (unit.segment.type === 'change' && range.start < unit.end && range.end > unit.start) {
                range.start = Math.min(range.start, unit.start); range.end = Math.max(range.end, unit.end);
            }
        }
        const last = ranges.at(-1);
        if (last && range.start <= last.end) { last.end = Math.max(last.end, range.end); last.edits.push(...range.edits); }
        else ranges.push(range);
    }
    const result = [];
    function unchanged(start, end) {
        for (const unit of units) {
            if (unit.start === unit.end) { if (unit.start >= start && unit.start < end) result.push(unit.segment); continue; }
            if (unit.end <= start || unit.start >= end) continue;
            let s = unit.segment;
            if (s.type === 'equal') {
                const value = s.before.slice(Math.max(0, start - unit.start), Math.min(unit.end, end) - unit.start);
                const from = Array.from(s.before.slice(0, Math.max(0, start - unit.start))).length;
                s = { ...s, before: value, after: value };
                for (const key of ['italics','before_italics']) if(s[key]) s[key] = sliceRanges(s[key], from, Array.from(value).length);
            }
            result.push(s);
        }
    }
    let cursor = 0;
    for (const range of ranges) {
        unchanged(cursor, range.start);
        let before = '';
        for (const unit of units) {
            if (unit.start === unit.end && unit.start >= range.start && (unit.start < range.end || (range.start === range.end && unit.start === range.start))) { before += unit.segment.before; continue; }
            if (unit.end <= range.start || unit.start >= range.end) continue;
            before += unit.segment.type === 'change' ? unit.segment.before : unit.segment.before.slice(Math.max(0, range.start - unit.start), Math.min(unit.end, range.end) - unit.start);
        }
        let after = old.slice(range.start, range.end);
        for (const edit of [...range.edits].reverse()) after = after.slice(0, edit.start - range.start) + edit.after + after.slice(edit.end - range.start);
        result.push(...diff(before, after));
        if (range.start === range.end) units = units.filter(u => u.start !== range.start || u.end !== range.end);
        cursor = range.end;
    }
    unchanged(cursor, old.length + 1);
    if (result.map(s => s.before).join('') !== segments.map(s => s.before).join('') || chunkText({ segments: result }) !== text) throw new Error('save_conflict');
    return result;
}
