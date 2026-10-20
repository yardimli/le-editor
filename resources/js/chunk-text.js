export function chunkText(chunk, includePending = true) {
    if (!chunk?.segments) return chunk?.input_text ?? chunk?.source ?? '';
    return chunk.segments.map(s => s.type === 'equal' || s.status === 'accepted'
        || (includePending && s.status === 'pending') ? s.after : s.before).join('');
}

export function changeKey(segments, index) {
    return JSON.stringify([segments.slice(0, index).reduce((n, s) => n + s.before.length, 0),
        segments[index].before, segments[index].after]);
}

export function boundedChunk(value, count) {
    return Math.max(1, Math.min(Math.trunc(Number(value)) || 1, Math.max(1, count)));
}
