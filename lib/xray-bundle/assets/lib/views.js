// Token stream, waterfall, inspector and meters. All data is written with textContent (never innerHTML).

import { displayName } from './trace.js';

export function h(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (null === v || undefined === v || false === v) {
            continue;
        }
        if (k.startsWith('on')) {
            node.addEventListener(k.slice(2), v);
        } else if ('dataset' === k) {
            Object.assign(node.dataset, v);
        } else {
            node.setAttribute(k, true === v ? '' : v);
        }
    }
    node.append(...children.flat().filter((c) => null !== c && undefined !== c && false !== c));
    return node;
}

export function pretty(value) {
    if (undefined === value) {
        return '';
    }
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

export const ms = (n) => (null === n || undefined === n ? '—' : n >= 10000 ? `${(n / 1000).toFixed(1)} s` : `${Math.round(n)} ms`);

export class TokenView {
    constructor(root) {
        this.root = root;
    }

    render(trace) {
        const parts = [];
        for (const turn of trace.turns.values()) {
            parts.push(h('span', { class: 'turn' }, `— turn ${turn.turn}${turn.usage ? ` · ${ms(turn.usage.durationMs)}` : ''}`));
            if (turn.text) {
                parts.push(document.createTextNode(turn.text));
            }
            for (const callId of turn.calls) {
                const call = trace.calls.get(callId);
                if (call) {
                    const server = trace.serverOf(call);
                    parts.push(h('span', { class: `call${server ? ' mcp' : ''}` }, `→ calling ${displayName(call.name, server)}(${compact(call.args)})${server ? ` via MCP "${server}"` : ''}`));
                }
            }
        }
        if (trace.running) {
            parts.push(h('span', { class: 'caret', 'aria-hidden': 'true' }));
        }
        if (0 === parts.length) {
            parts.push(h('span', { class: 'muted' }, 'The answer streams here.'));
        }
        const stick = this.root.scrollHeight - this.root.scrollTop - this.root.clientHeight < 40;
        this.root.replaceChildren(...parts);
        if (stick) {
            this.root.scrollTop = this.root.scrollHeight;
        }
    }
}

export class WaterfallView {
    constructor(root, onSelect) {
        this.root = root;
        this.onSelect = onSelect;
        this.selected = null;
    }

    render(trace, now) {
        const spans = trace.spans(now);
        const total = Math.max(1, trace.finished?.durationMs ?? 0, now, ...spans.map((s) => s.end));
        const ticks = niceTicks(total);
        const axis = h('div', { class: 'wf-axis' }, ticks.map((t) => h('span', { style: `left:${(t / total) * 100}%` }, ms(t))));
        const rows = spans.map((s) => {
            const left = (s.start / total) * 100;
            const width = Math.max(0.3, ((s.end - s.start) / total) * 100);
            const dur = h('span', { class: 'wf-dur', style: `left:calc(${Math.min(left + width, 92)}% + 4px)` }, ms(s.end - s.start));
            return h('div', {
                class: `wf-row${this.selected === s.id ? ' selected' : ''}`, role: 'button', tabindex: '0', title: `${s.label}: ${ms(s.end - s.start)}`,
                onclick: () => this.pick(s), onkeydown: (e) => ('Enter' === e.key ? this.pick(s) : null),
            },
            h('div', { class: `wf-label${s.nested ? ' nested' : ''}` }, s.label),
            h('div', { class: 'wf-track' }, h('div', { class: `wf-bar ${s.kind}${s.running ? ' running' : ''}`, style: `left:${left}%;width:${width}%` }), dur));
        });
        this.root.replaceChildren(axis, ...(rows.length ? rows : [h('div', { class: 'insp-empty' }, 'One bar per step (model turns, retrievals, tool calls, MCP round trips) appears here.')]));
    }

    pick(span) {
        this.selected = span.id;
        this.onSelect(span.ref);
    }
}

export class MetersView {
    constructor(root) {
        this.root = root;
        this.values = new Map();
        this.items = {
            latency: this.meter('Total latency'),
            ttft: this.meter('Time to first token'),
            tokens: this.meter('Tokens in / out'),
            tools: this.meter('Tool calls'),
            cost: this.meter('Estimated cost'),
            errors: this.meter('Errors'),
        };
        root.replaceChildren(...Object.values(this.items).map((m) => m.el));
    }

    meter(label) {
        const value = h('div', { class: 'value' }, '—');
        const note = h('div', { class: 'note' }, '');
        return { el: h('div', { class: 'meter' }, h('div', { class: 'label' }, label), value, note), value, note };
    }

    render(trace, now, animate) {
        const latency = trace.finished?.durationMs ?? (trace.applied ? now : null);
        this.set('latency', latency, ms, animate);
        this.items.latency.note.textContent = trace.running ? 'running' : trace.finished ? (trace.finished.ok ? 'finished' : 'failed') : '';
        const ttft = null !== trace.firstTokenTs && null !== trace.firstPromptTs ? trace.firstTokenTs - trace.firstPromptTs : null;
        this.set('ttft', ttft, ms, animate);
        this.items.tokens.value.textContent = trace.applied ? `${trace.usage.input} / ${trace.usage.output}` : '—';
        this.items.tokens.note.textContent = trace.usage.estimated ? 'estimated (~4 chars per token)' : trace.applied ? 'reported by the provider' : '';
        this.set('tools', trace.applied ? trace.calls.size : null, (n) => String(Math.round(n)), animate);
        const via = [...trace.calls.values()].filter((c) => 'mcp' === c.via).length;
        this.items.tools.note.textContent = trace.calls.size ? `${via} via MCP · ${trace.calls.size - via} local` : '';
        this.items.cost.value.textContent = !trace.applied ? '—' : trace.usage.costKnown && null !== trace.usage.cost ? `$${trace.usage.cost.toFixed(5)}` : 'n/a';
        this.items.cost.note.textContent = trace.applied && !trace.usage.costKnown ? `no price configured for ${trace.meta.model || 'this model'}` : '';
        this.set('errors', trace.applied ? trace.errors.length : null, (n) => String(Math.round(n)), animate);
        this.items.errors.el.classList.toggle('bad', trace.errors.length > 0);
        this.items.errors.note.textContent = trace.errors.length ? trace.errors[trace.errors.length - 1].where : '';
    }

    set(key, target, format, animate) {
        const item = this.items[key];
        if (null === target || undefined === target) {
            item.value.textContent = '—';
            this.values.delete(key);
            return;
        }
        const from = this.values.get(key) ?? target;
        this.values.set(key, target);
        if (!animate || from === target) {
            item.value.textContent = format(target);
            return;
        }
        const start = performance.now();
        const step = (t) => {
            const p = Math.min(1, (t - start) / 250);
            item.value.textContent = format(from + (target - from) * p);
            if (p < 1 && this.values.get(key) === target) {
                requestAnimationFrame(step);
            }
        };
        requestAnimationFrame(step);
    }
}

export class InspectorView {
    constructor(root, tabs, onFocusFrames) {
        this.root = root;
        this.tabs = tabs;
        this.onFocusFrames = onFocusFrames;
        this.tab = 'prompt';
        this.turn = null;
        this.callId = null;
        this.frameFilter = { server: '', method: '', callId: '' };
        this.openFrames = new Set();
    }

    show(tab) {
        this.tab = tab;
        for (const button of this.tabs) {
            button.setAttribute('aria-selected', String(button.dataset.tab === tab));
        }
    }

    render(trace) {
        const body = 'prompt' === this.tab ? this.prompt(trace) : 'tool' === this.tab ? this.tool(trace) : this.mcp(trace);
        const keep = this.root.scrollTop;
        this.root.replaceChildren(...body);
        this.root.scrollTop = keep;
    }

    prompt(trace) {
        const turns = [...trace.turns.values()];
        if (!turns.length) {
            return [h('div', { class: 'insp-empty' }, 'The exact messages and tool schemas sent to the model, per turn.')];
        }
        const current = trace.turns.get(this.turn) ?? turns[turns.length - 1];
        const snippets = trace.retrievals.flatMap((r) => r.hits.map((hit) => hit.id));
        const picker = h('div', { class: 'turns', role: 'tablist', 'aria-label': 'Turns' }, turns.map((t) => h('button', {
            class: 'btn btn-sm', 'aria-pressed': String(t === current), onclick: () => { this.turn = t.turn; this.render(trace); },
        }, `turn ${t.turn}`)));
        const messages = current.messages.map((m) => h('div', { class: 'msg' },
            h('div', { class: 'msg-head' }, h('span', { class: 'role' }, m.role), h('span', {}, `~${Math.ceil((m.content || '').length / 4)} tokens`), m.truncated ? h('span', { class: 'flag' }, 'truncated') : null, m.content.includes('[redacted]') ? h('span', { class: 'flag' }, 'redacted') : null, m.toolCallId ? h('span', {}, m.toolCallId) : null),
            h('pre', {}, highlight(m.content, snippets))));
        const tools = h('div', { class: 'msg' }, h('div', { class: 'msg-head' }, h('span', { class: 'role' }, 'tools'), h('span', {}, `${current.tools.length} offered`)),
            h('pre', {}, current.tools.map((t) => `${t.name}: ${t.description}`).join('\n') || '(none)'));
        return [picker, ...messages, tools];
    }

    tool(trace) {
        const call = trace.calls.get(this.callId) ?? [...trace.calls.values()].at(-1);
        if (!call) {
            return [h('div', { class: 'insp-empty' }, 'Click a tool node, an edge or a waterfall bar to inspect a tool call.')];
        }
        this.callId = call.callId;
        const status = null === call.ok ? h('span', { class: 'muted' }, 'running') : call.ok ? h('span', { class: 'ok' }, 'ok') : h('span', { class: 'bad' }, 'error');
        return [
            h('dl', { class: 'kv' },
                h('dt', {}, 'tool'), h('dd', {}, displayName(call.name, call.server)),
                h('dt', {}, 'call id'), h('dd', {}, call.callId),
                h('dt', {}, 'via'), h('dd', { class: 'mcp' === call.via ? 'via-mcp' : '' }, 'mcp' === call.via ? `MCP · server "${call.server}"` : 'local'),
                h('dt', {}, 'turn'), h('dd', {}, String(call.turn ?? '—')),
                h('dt', {}, 'duration'), h('dd', {}, ms(call.durationMs ?? null)),
                h('dt', {}, 'status'), h('dd', {}, status)),
            call.error ? h('div', { class: 'msg' }, h('div', { class: 'msg-head' }, h('span', { class: 'role bad' }, 'error')), h('pre', {}, call.error)) : null,
            h('div', { class: 'msg' }, h('div', { class: 'msg-head' }, h('span', { class: 'role' }, 'arguments')), h('pre', {}, pretty(call.args))),
            h('div', { class: 'msg' }, h('div', { class: 'msg-head' }, h('span', { class: 'role' }, 'result')), h('pre', {}, null === call.result ? '—' : pretty(call.result))),
            call.frames.length ? h('button', { class: 'btn btn-sm', onclick: () => this.onFocusFrames(call.callId) }, `Show its ${call.frames.length} MCP frames →`) : null,
        ].filter(Boolean);
    }

    mcp(trace) {
        if (!trace.frames.length) {
            return [h('div', { class: 'insp-empty' }, 'Real JSON-RPC frames between the agent\'s MCP client and the server appear here.')];
        }
        const servers = [...new Set(trace.frames.map((f) => f.server))];
        const methods = [...new Set(trace.frames.map((f) => f.method).filter(Boolean))];
        const f = this.frameFilter;
        const select = (key, values, label) => h('select', { 'aria-label': label, onchange: (e) => { f[key] = e.target.value; this.render(trace); } },
            h('option', { value: '' }, `all ${label}s`), values.map((v) => h('option', { value: v, selected: f[key] === v }, v)));
        const filters = h('div', { class: 'frames-filter' }, select('server', servers, 'server'), select('method', methods, 'method'),
            f.callId ? h('button', { class: 'btn btn-sm', onclick: () => { f.callId = ''; this.render(trace); } }, `call ${f.callId} ✕`) : null);
        const frames = trace.frames
            .filter((x) => (!f.server || x.server === f.server) && (!f.method || x.method === f.method) && (!f.callId || x.correlatesTo === f.callId))
            .slice(-300)
            .map((x) => {
                const json = JSON.stringify(x.payload);
                const details = h('details', { class: `frame ${x.direction} ${x.kind}${f.callId && x.correlatesTo === f.callId ? ' focus' : ''}`, open: this.openFrames.has(x.seq) },
                    h('summary', {}, h('span', { class: 'dir' }, 'out' === x.direction ? '→' : '←'), h('span', { class: 'method' }, `${x.method ?? x.kind}${null === x.id ? '' : ` #${x.id}`}${'error' === x.kind ? ' · error' : ''}`),
                        x.correlatesTo ? h('span', { class: 'muted' }, x.correlatesTo) : null, h('span', { class: 'lat', title: null === x.latencyMs || undefined === x.latencyMs ? 'time since the run started' : 'round trip' }, null === x.latencyMs || undefined === x.latencyMs ? `@ ${x.ts} ms` : `${x.latencyMs} ms`),
                        h('button', { class: 'btn btn-sm', title: 'Copy as JSON', onclick: (e) => { e.preventDefault(); navigator.clipboard?.writeText(json); e.target.textContent = 'copied'; } }, 'copy')),
                    h('pre', { class: 'json' }, pretty(x.payload)));
                details.addEventListener('toggle', () => (details.open ? this.openFrames.add(x.seq) : this.openFrames.delete(x.seq)));
                return details;
            });
        return [filters, ...frames];
    }
}

function highlight(content, ids) {
    if (!ids.length) {
        return content;
    }
    const nodes = [];
    for (const line of content.split('\n')) {
        const match = /^\[([\w.-]+)\]/.exec(line);
        nodes.push(match && ids.includes(match[1]) ? h('mark', { class: 'rag', title: 'Injected from the store' }, line) : document.createTextNode(line), document.createTextNode('\n'));
    }
    return nodes;
}

function compact(args) {
    const text = JSON.stringify(args ?? {});
    return text.length > 80 ? `${text.slice(0, 79)}…` : text;
}

function niceTicks(total) {
    const steps = [50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000, 20000, 30000, 60000];
    const step = steps.find((s) => total / s <= 6) ?? 60000;
    const ticks = [];
    for (let t = 0; t <= total; t += step) {
        ticks.push(t);
    }
    return ticks;
}
