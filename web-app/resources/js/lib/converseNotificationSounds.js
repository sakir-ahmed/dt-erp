export const converseNotificationCues = {
    ai_inbound: { label: 'converse_notice_ai_inbound', notes: [440], spacing: 0.15, duration: 0.16, volume: 0.07 },
    ai_reply: { label: 'converse_notice_ai_reply', notes: [587, 784], spacing: 0.15, duration: 0.17, volume: 0.08 },
    human_handoff: { label: 'converse_notice_human_handoff', notes: [880, 587, 988], spacing: 0.19, duration: 0.24, volume: 0.15 },
};

export const converseEventCues = {
    ai_inbound: 'ai_inbound',
    ai_reply: 'ai_reply',
    human_handoff: 'human_handoff',
    manual_handoff: 'human_handoff',
};

export function readConverseEvents(previousCursor, response) {
    if (!Number.isSafeInteger(response.cursor) || response.cursor < 0 || !Array.isArray(response.events)) {
        return { cursor: previousCursor, events: [] };
    }

    let cursor = previousCursor;
    const events = [];
    if (cursor !== null) {
        for (const event of response.events) {
            const id = Number(event.id);
            if (Number.isSafeInteger(id) && id > cursor && id <= response.cursor) {
                cursor = id;
                if (Object.hasOwn(converseEventCues, event.type)) {
                    events.push({ id, type: event.type });
                }
            }
        }
    }

    return { cursor: Math.max(cursor ?? 0, response.cursor), events };
}

export function selectConverseCues(events, lastPlayed, soundReady) {
    if (!soundReady) return [];
    return [...new Set(events.filter(({ id }) => id > lastPlayed).map(({ type }) => converseEventCues[type]))];
}

export function scheduleConverseSound(context, type, start) {
    const { notes, spacing, duration, volume } = converseNotificationCues[type];
    for (const [index, frequency] of notes.entries()) {
        const at = start + index * spacing;
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.frequency.setValueAtTime(frequency, at);
        gain.gain.setValueAtTime(0.001, at);
        gain.gain.exponentialRampToValueAtTime(volume, at + 0.025);
        gain.gain.exponentialRampToValueAtTime(0.001, at + duration);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start(at);
        oscillator.stop(at + duration);
    }

    return start + (notes.length - 1) * spacing + duration + 0.16;
}
