// The client-side model of one run. Events may arrive out of order or twice (reconnects, two transports):
// they are buffered by `seq`, de-duplicated, and applied strictly in order. Live and replay feed the same model.

export class Trace {
    constructor(runId) {
        this.runId = runId;
        this.applied = 0;
        this.pending = new Map();
        this.events = [];
        this.meta = { prompt: '', agent: '', model: '', mode: '', scenario: null };
        this.finished = null;
        this.lastTs = 0;
        this.turns = new Map();
        this.calls = new Map();
        this.frames = [];
        this.pendingFrames = new Map();
        this.retrievals = [];
        this.mcpTools = new Map();
        this.errors = [];
        this.nodes = new Map();
        this.edges = new Set();
        this.usage = { input: 0, output: 0, estimated: false, cost: null, costKnown: true };
        this.firstPromptTs = null;
        this.firstTokenTs = null;
    }

    get running() {
        return this.applied > 0 && null === this.finished;
    }

    // Returns the events that became applicable, in order.
    push(event) {
        if (!event || event.runId !== this.runId || !Number.isInteger(event.seq)) {
            return [];
        }
        if (event.seq <= this.applied || this.pending.has(event.seq)) {
            return [];
        }
        this.pending.set(event.seq, event);
        const ready = [];
        while (this.pending.has(this.applied + 1)) {
            const next = this.pending.get(this.applied + 1);
            this.pending.delete(this.applied + 1);
            this.applied += 1;
            this.apply(next);
            ready.push(next);
        }
        return ready;
    }

    apply(event) {
        this.events.push(event);
        this.lastTs = Math.max(this.lastTs, event.ts);
        const data = event.data || {};
        switch (event.type) {
            case 'run.started':
                Object.assign(this.meta, data);
                this.node('user', 'user', 'User', '');
                this.node('agent', 'agent', data.agent || 'Agent', data.model || '');
                this.edge('user', 'agent');
                break;
            case 'retrieval.hit': {
                const id = `store:${data.store}`;
                this.node(id, 'store', data.store, 'store');
                this.edge('agent', id);
                const n = this.nodes.get(id);
                n.calls += 1;
                n.lastMs = data.durationMs;
                this.retrievals.push({ seq: event.seq, ts: event.ts, start: Math.max(0, event.ts - (data.durationMs || 0)), ...data });
                break;
            }
            case 'prompt.sent': {
                this.node('model', 'model', 'Model', data.model || this.meta.model);
                this.edge('agent', 'model');
                this.nodes.get('model').calls += 1;
                this.firstPromptTs ??= event.ts;
                this.turns.set(data.turn, { turn: data.turn, start: event.ts, end: null, messages: data.messages || [], tools: data.tools || [], text: '', calls: [], usage: null });
                break;
            }
            case 'token.chunk': {
                this.firstTokenTs ??= event.ts;
                const turn = this.turns.get(data.turn);
                if (turn) {
                    turn.text += data.text;
                }
                break;
            }
            case 'tool.call.requested': {
                const call = this.call(data.callId);
                Object.assign(call, { name: data.name, args: data.arguments, turn: data.turn, requestedTs: event.ts });
                this.turns.get(data.turn)?.calls.push(data.callId);
                break;
            }
            case 'tool.call.started': {
                const call = this.call(data.callId);
                Object.assign(call, { name: data.name ?? call.name, via: data.via, server: data.server, start: event.ts });
                const toolId = `tool:${call.name}`;
                if ('mcp' === data.via && data.server) {
                    const serverId = `mcp:${data.server}`;
                    this.node(serverId, 'mcp', data.server, 'MCP server');
                    this.node(toolId, 'tool', displayName(call.name, data.server), 'mcp tool', serverId);
                    this.edge('agent', serverId);
                    this.edge(serverId, toolId);
                } else {
                    this.node(toolId, 'tool', call.name, 'local tool');
                    this.edge('agent', toolId);
                }
                this.nodes.get(toolId).calls += 1;
                break;
            }
            case 'tool.call.finished': {
                const call = this.call(data.callId);
                Object.assign(call, { end: event.ts, ok: data.ok, result: data.result, error: data.error, durationMs: data.durationMs });
                call.start ??= event.ts - (data.durationMs || 0);
                const node = this.nodes.get(`tool:${call.name}`);
                if (node) {
                    node.lastMs = data.durationMs;
                    node.error = !data.ok;
                }
                break;
            }
            case 'mcp.frame': {
                const frame = { seq: event.seq, ts: event.ts, ...data };
                this.frames.push(frame);
                const serverId = `mcp:${data.server}`;
                this.node(serverId, 'mcp', data.server, 'MCP server');
                this.edge('agent', serverId);
                if ('out' === data.direction && 'request' === data.kind) {
                    this.pendingFrames.set(`${data.server}#${data.id}`, frame);
                    this.nodes.get(serverId).calls += 1;
                } else if ('in' === data.direction && null !== data.id) {
                    const request = this.pendingFrames.get(`${data.server}#${data.id}`);
                    if (request) {
                        request.response = frame;
                        this.pendingFrames.delete(`${data.server}#${data.id}`);
                        if ('tools/list' === request.method) {
                            for (const tool of data.payload?.result?.tools ?? []) {
                                this.mcpTools.set(tool.name, data.server);
                            }
                        }
                    }
                }
                if (data.correlatesTo) {
                    this.call(data.correlatesTo).frames.push(frame);
                }
                break;
            }
            case 'usage.reported': {
                const turn = this.turns.get(data.turn);
                if (turn) {
                    turn.end = event.ts;
                    turn.usage = data;
                }
                this.usage.input += data.inputTokens || 0;
                this.usage.output += data.outputTokens || 0;
                this.usage.estimated ||= Boolean(data.estimated);
                if (null === data.estimatedCostUsd || undefined === data.estimatedCostUsd) {
                    this.usage.costKnown = false;
                } else {
                    this.usage.cost = (this.usage.cost || 0) + data.estimatedCostUsd;
                }
                break;
            }
            case 'run.error':
                this.errors.push({ seq: event.seq, ts: event.ts, ...data });
                if ('platform' === data.where) {
                    this.nodes.get('model') && (this.nodes.get('model').error = true);
                }
                break;
            case 'run.finished':
                this.finished = data;
                for (const turn of this.turns.values()) {
                    turn.end ??= event.ts;
                }
                break;
            default:
                // Unknown types are ignored (forward compatibility, see TRACE_SCHEMA.md).
                break;
        }
    }

    node(id, kind, label, sub, parent = null) {
        if (!this.nodes.has(id)) {
            this.nodes.set(id, { id, kind, label, sub, parent, calls: 0, lastMs: null, error: false, order: this.nodes.size });
        }
        return this.nodes.get(id);
    }

    edge(from, to) {
        this.edges.add(`${from}>${to}`);
    }

    // The MCP server behind a (possibly prefixed) tool name, learned from tools/list responses.
    serverOf(call) {
        if (call.server) {
            return call.server;
        }
        for (const [name, server] of this.mcpTools) {
            if (call.name === name || call.name?.endsWith(`_${name}`)) {
                return server;
            }
        }
        return null;
    }

    call(callId) {
        if (!this.calls.has(callId)) {
            this.calls.set(callId, { callId, name: '', args: {}, turn: null, via: null, server: null, start: null, end: null, ok: null, result: null, error: null, frames: [] });
        }
        return this.calls.get(callId);
    }

    // Waterfall spans, ordered by start. `now` closes spans that are still running.
    spans(now) {
        const spans = [];
        for (const turn of this.turns.values()) {
            spans.push({ id: `turn:${turn.turn}`, kind: 'model', label: `model · turn ${turn.turn}`, start: turn.start, end: turn.end ?? now, running: null === turn.end, ref: { type: 'turn', turn: turn.turn } });
        }
        for (const r of this.retrievals) {
            spans.push({ id: `ret:${r.seq}`, kind: 'retrieval', label: `retrieve · ${r.store}`, start: r.start, end: r.ts, running: false, ref: { type: 'retrieval', seq: r.seq } });
        }
        const correlated = new Set();
        for (const call of this.calls.values()) {
            if (null === call.start) {
                continue;
            }
            const err = false === call.ok;
            spans.push({ id: `call:${call.callId}`, kind: err ? 'err' : 'tool', label: `${'mcp' === call.via ? 'mcp' : 'tool'} · ${displayName(call.name, call.server)}`, start: call.start, end: call.end ?? now, running: null === call.end, ref: { type: 'call', callId: call.callId } });
            for (const frame of call.frames) {
                if ('out' === frame.direction && 'request' === frame.kind) {
                    correlated.add(frame.seq);
                    spans.push({ id: `rt:${frame.seq}`, kind: frame.response && 'error' === frame.response.kind ? 'err' : 'mcp', label: `↳ ${frame.method} #${frame.id}`, nested: true, parentStart: call.start, start: frame.ts, end: frame.response ? frame.response.ts : now, running: !frame.response, ref: { type: 'frame', seq: frame.seq, callId: call.callId } });
                }
            }
        }
        for (const frame of this.frames) {
            if ('out' === frame.direction && 'request' === frame.kind && !correlated.has(frame.seq)) {
                spans.push({ id: `rt:${frame.seq}`, kind: 'mcp', label: `mcp · ${frame.method}`, start: frame.ts, end: frame.response ? frame.response.ts : now, running: !frame.response, ref: { type: 'frame', seq: frame.seq } });
            }
        }
        spans.sort((a, b) => (a.nested ? a.parentStart : a.start) - (b.nested ? b.parentStart : b.start) || (a.nested ? 1 : 0) - (b.nested ? 1 : 0) || a.start - b.start);
        return spans;
    }
}

// "demo_get_weather" served by MCP server "demo" reads better as "get_weather".
export function displayName(name, server) {
    if (server && name && name.startsWith(`${server}_`)) {
        return name.slice(server.length + 1);
    }
    return name || '?';
}
