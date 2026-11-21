import assert from 'node:assert/strict';
import test from 'node:test';
import { converseEventCues, converseNotificationCues, readConverseEvents, scheduleConverseSound, selectConverseCues } from '../../resources/js/lib/converseNotificationSounds.js';

test('the initial cursor and repeated polls do not replay events', () => {
    const payload = { cursor: 8, events: [{ id: 8, type: 'human_handoff' }] };
    assert.deepEqual(readConverseEvents(null, payload), { cursor: 8, events: [] });
    assert.deepEqual(readConverseEvents(8, payload), { cursor: 8, events: [] });

    const reply = { id: 9, type: 'ai_reply' };
    const batch = { cursor: 10, events: [reply, reply, { id: 10, type: 'unknown' }] };
    assert.deepEqual(readConverseEvents(8, batch), { cursor: 10, events: [reply] });
    assert.deepEqual(readConverseEvents(10, batch), { cursor: 10, events: [] });
});

test('new messages, accepted replies and human handoff have distinct cues', () => {
    const cues = ['ai_inbound', 'ai_reply', 'human_handoff'];
    assert.deepEqual(cues.map((type) => converseNotificationCues[type].notes.length), [1, 2, 3]);
    const frequencies = [];
    const context = {
        createOscillator: () => ({
            frequency: { setValueAtTime: (frequency) => frequencies.push(frequency) },
            connect: (gain) => gain,
            start() {},
            stop() {},
        }),
        createGain: () => ({ gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} }),
    };
    const durations = cues.map((type) => scheduleConverseSound(context, type, 1) - 1);
    assert.ok(durations[2] > durations[1] && durations[1] > durations[0]);
    assert.ok(converseNotificationCues.human_handoff.volume > converseNotificationCues.ai_reply.volume);
    assert.deepEqual(frequencies, [440, 587, 784, 880, 587, 988]);
});

test('automatic and manual handoffs share exactly one attention cue per event type', () => {
    assert.equal(converseEventCues.human_handoff, 'human_handoff');
    assert.equal(converseEventCues.manual_handoff, 'human_handoff');
    const response = { cursor: 2, events: [{ id: 2, type: 'human_handoff' }, { id: 2, type: 'human_handoff' }] };
    const { events } = readConverseEvents(1, response);
    assert.deepEqual(selectConverseCues(events, 1, true), ['human_handoff']);
    assert.deepEqual(selectConverseCues(events, 1, false), []);
    assert.deepEqual(selectConverseCues(events, 2, true), []);
});
