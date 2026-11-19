// Renders the result of /_xray/diff: prompt and answer word diffs, deltas, and the aligned steps
// with the first divergence highlighted.

import { h, ms } from './views.js';

export function renderDiff(root, { runs, diff }) {
    const [a, b] = runs;
    const words = (ops, side) => ops
        .filter((op) => 'same' === op.op || ('a' === side ? 'del' === op.op : 'add' === op.op))
        .map((op) => ('same' === op.op ? document.createTextNode(op.text) : h('a' === side ? 'del' : 'ins', {}, op.text)));

    const delta = (label, value, format = (v) => String(v)) => h('div', { class: 'delta' },
        h('span', { class: 'muted' }, label),
        h('b', { class: null === value ? '' : value > 0 ? 'bad' : value < 0 ? 'ok' : '' }, null === value ? 'n/a' : `${value > 0 ? '+' : ''}${format(value)}`));

    const divergence = diff.divergence;
    const firstDifference = diff.steps.findIndex((step) => 'same' !== step.op);
    const steps = diff.steps.flatMap((step, i) => {
        const cls = `${step.op}${i === firstDifference ? ' diverge' : ''}`;
        return [
            h('div', { class: cls, title: i === firstDifference ? 'The runs diverge here' : null }, 'same' === step.op ? '=' : 'del' === step.op ? '−' : '+'),
            h('div', { class: cls }, step.a ? `${turnLabel(step.a.turn)} ${step.a.label}` : ''),
            h('div', { class: cls }, step.b ? `${turnLabel(step.b.turn)} ${step.b.label}` : ''),
        ];
    });

    root.replaceChildren(h('div', { class: 'diff' },
        h('div', {}, h('h3', {}, 'Prompt'), h('div', { class: 'diff-cols' },
            h('div', { class: 'diff-box' }, h('div', { class: 'muted' }, `A · ${a.id}`), ...words(diff.prompt, 'a')),
            h('div', { class: 'diff-box' }, h('div', { class: 'muted' }, `B · ${b.id}`), ...words(diff.prompt, 'b')))),
        h('div', {}, h('h3', {}, 'B compared with A'), h('div', { class: 'deltas' },
            delta('latency', diff.deltas.durationMs, ms),
            delta('turns', diff.deltas.turns),
            delta('tool calls', diff.deltas.toolCalls),
            delta('input tokens', diff.deltas.inputTokens),
            delta('output tokens', diff.deltas.outputTokens),
            delta('cost (USD)', diff.deltas.estimatedCostUsd, (v) => v.toFixed(5)))),
        h('div', {}, h('h3', {}, divergence ? `Steps · diverges at step ${divergence.index + 1}` : 'Steps · identical'),
            h('div', { class: 'steps' }, h('div', { class: 'muted' }, ''), h('div', { class: 'muted' }, 'A'), h('div', { class: 'muted' }, 'B'), ...steps)),
        h('div', {}, h('h3', {}, 'Answer'), h('div', { class: 'diff-cols' },
            h('div', { class: 'diff-box' }, ...words(diff.answer, 'a')),
            h('div', { class: 'diff-box' }, ...words(diff.answer, 'b'))))));
}

function turnLabel(turn) {
    return turn ? `t${turn}` : 'input';
}
