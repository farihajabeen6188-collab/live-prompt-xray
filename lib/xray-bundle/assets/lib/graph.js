// The live graph: a stable layout (every node keeps its slot once placed), glowing active nodes and
// packets travelling along the edges in the direction data flows.

import { displayName } from './trace.js';

const SVG = 'http://www.w3.org/2000/svg';
const W = 860;
const H = 480;
const SIZE = { user: [96, 40], agent: [128, 46], model: [132, 46], store: [132, 40], mcp: [132, 46], tool: [138, 36] };

export class Graph {
    constructor(container, tooltip, { onSelect, reducedMotion }) {
        this.container = container;
        this.tooltip = tooltip;
        this.onSelect = onSelect;
        this.reducedMotion = reducedMotion;
        this.svg = el('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': 'Live graph of the agent run' });
        this.edgeLayer = el('g');
        this.packetLayer = el('g');
        this.nodeLayer = el('g');
        this.svg.append(this.edgeLayer, this.packetLayer, this.nodeLayer);
        container.replaceChildren(this.svg);
        this.reset();
    }

    reset() {
        this.edgeLayer.replaceChildren();
        this.packetLayer.replaceChildren();
        this.nodeLayer.replaceChildren();
        this.nodeEls = new Map();
        this.edgeEls = new Map();
        this.positions = new Map();
        this.slots = { tool: 0, mcpTool: 0, store: 0, mcp: 0 };
        this.active = new Map();
        this.selected = null;
        clearInterval(this.ghostLoop);
        this.svg.classList.remove('ghost');
    }

    // The pre-flight graph: every tool and store the agent has, in outline; the planned path highlighted
    // and pulsed in a loop ("this is what will happen when you press Run").
    ghost(preview) {
        this.reset();
        this.svg.classList.add('ghost');
        const node = (id, kind, label, sub, parent = null) => ({ id, kind, label, sub, parent, calls: 0, lastMs: null, error: false });
        const add = (n, from) => {
            if (!this.nodeEls.has(n.id)) {
                this.addNode(n);
                this.updateNode(n);
            }
            if (from && !this.edgeEls.has(`${from}>${n.id}`)) {
                this.addEdge(`${from}>${n.id}`);
            }
        };
        add(node('user', 'user', 'User', ''));
        add(node('agent', 'agent', preview.agent, preview.model), 'user');
        add(node('model', 'model', 'Model', preview.model), 'agent');
        for (const r of preview.retrievals) {
            add(node(`store:${r.store}`, 'store', r.store, 'store'), 'agent');
        }
        const planned = new Set((preview.plan?.calls ?? []).map((c) => c.tool));
        const likely = new Set(preview.plan ? [] : preview.tools.filter((t) => t.score >= 0.5).map((t) => t.name));
        for (const tool of preview.tools) {
            if (tool.server) {
                add(node(`mcp:${tool.server}`, 'mcp', tool.server, 'MCP server'), 'agent');
                add(node(`tool:${tool.name}`, 'tool', displayName(tool.name, tool.server), 'mcp tool', `mcp:${tool.server}`), `mcp:${tool.server}`);
            } else {
                add(node(`tool:${tool.name}`, 'tool', tool.name, 'local tool'), 'agent');
            }
            if (planned.has(tool.name) || likely.has(tool.name)) {
                this.nodeEls.get(`tool:${tool.name}`)?.classList.add('planned');
                if (tool.server) {
                    this.nodeEls.get(`mcp:${tool.server}`)?.classList.add('planned');
                }
            }
        }
        for (const r of preview.retrievals) {
            const system = preview.messages.find((m) => 'system' === m.role)?.content ?? '';
            if (r.hits.some((hit) => system.includes(`[${hit.id}]`))) {
                this.nodeEls.get(`store:${r.store}`)?.classList.add('planned');
            }
        }
        this.nodeEls.get('model')?.classList.add('planned');

        const path = () => {
            this.pulse('user', 'agent');
            for (const r of preview.retrievals) {
                setTimeout(() => this.pulse('agent', `store:${r.store}`), 200);
            }
            setTimeout(() => this.pulse('agent', 'model'), 420);
            const targets = [...planned, ...likely];
            targets.forEach((name, i) => {
                const tool = preview.tools.find((t) => t.name === name);
                setTimeout(() => {
                    if (tool?.server) {
                        this.pulse('agent', `mcp:${tool.server}`, 'mcp');
                        setTimeout(() => this.pulse(`mcp:${tool.server}`, `tool:${name}`, 'mcp'), 380);
                    } else {
                        this.pulse('agent', `tool:${name}`);
                    }
                }, 900 + i * 160);
            });
        };
        if (!this.reducedMotion) {
            path();
            this.ghostLoop = setInterval(path, 2600);
        }
    }

    // Draws nodes and edges that appeared in the trace since the last call.
    sync(trace) {
        for (const node of trace.nodes.values()) {
            if (!this.nodeEls.has(node.id)) {
                this.addNode(node);
            }
            this.updateNode(node);
        }
        for (const key of trace.edges) {
            if (!this.edgeEls.has(key)) {
                this.addEdge(key);
            }
        }
    }

    // Reacts to one event: packets, glow, failure shake.
    animate(event, trace) {
        const d = event.data || {};
        const toolOf = (callId) => trace.calls.get(callId);
        switch (event.type) {
            case 'run.started':
                this.pulse('user', 'agent');
                this.hold('agent', true);
                break;
            case 'retrieval.hit': {
                const store = `store:${d.store}`;
                this.pulse('agent', store);
                this.flash(store);
                setTimeout(() => this.pulse(store, 'agent'), this.reducedMotion ? 0 : 260);
                break;
            }
            case 'prompt.sent':
                this.pulse('agent', 'model');
                this.hold('model', true);
                break;
            case 'token.chunk':
                if (0 === event.seq % 3) {
                    this.pulse('model', 'agent', 'small');
                }
                break;
            case 'tool.call.requested':
                this.pulse('model', 'agent');
                break;
            case 'usage.reported':
                this.hold('model', false);
                break;
            case 'tool.call.started': {
                const call = toolOf(d.callId);
                if ('mcp' === d.via && d.server) {
                    this.pulse('agent', `mcp:${d.server}`, 'mcp');
                    this.hold(`mcp:${d.server}`, true);
                } else {
                    this.pulse('agent', `tool:${call?.name}`);
                }
                this.hold(`tool:${call?.name}`, true);
                break;
            }
            case 'mcp.frame': {
                const server = `mcp:${d.server}`;
                const call = d.correlatesTo ? toolOf(d.correlatesTo) : null;
                const error = 'error' === d.kind;
                if (call) {
                    const tool = `tool:${call.name}`;
                    'out' === d.direction ? this.pulse(server, tool, 'mcp') : this.pulse(tool, server, error ? 'err' : 'mcp');
                } else {
                    'out' === d.direction ? this.pulse('agent', server, 'mcp') : this.pulse(server, 'agent', error ? 'err' : 'mcp');
                    this.flash(server);
                }
                break;
            }
            case 'tool.call.finished': {
                const call = toolOf(d.callId);
                if (!call) {
                    break;
                }
                const tool = `tool:${call.name}`;
                const from = 'mcp' === call.via && call.server ? `mcp:${call.server}` : tool;
                this.pulse(from, 'agent', d.ok ? ('mcp' === call.via ? 'mcp' : '') : 'err');
                this.hold(tool, false);
                if ('mcp' === call.via && call.server) {
                    this.hold(`mcp:${call.server}`, false);
                }
                if (!d.ok) {
                    this.fail(tool);
                    this.failEdge('mcp' === call.via ? `mcp:${call.server}>${tool}` : `agent>${tool}`);
                }
                break;
            }
            case 'run.error':
                if ('platform' === d.where) {
                    this.fail('model');
                }
                break;
            case 'run.finished':
                this.pulse('agent', 'user');
                for (const id of this.active.keys()) {
                    this.hold(id, false);
                }
                this.flash('user');
                break;
            default:
                break;
        }
    }

    select(id) {
        this.selected = id;
        for (const [nodeId, g] of this.nodeEls) {
            g.classList.toggle('selected', nodeId === id);
        }
    }

    addNode(node) {
        const [w, h] = SIZE[node.kind] || SIZE.tool;
        const [x, y] = this.place(node);
        this.positions.set(node.id, { x, y, w, h });
        const g = el('g', { class: `g-node ${node.kind}${'mcp' === node.kind || node.parent ? ' mcp' : ''}${this.reducedMotion ? '' : ' enter'}`, tabindex: '0', role: 'button', 'data-id': node.id });
        g.append(el('rect', { x: x - w / 2, y: y - h / 2, width: w, height: h, rx: 'agent' === node.kind || 'model' === node.kind ? 10 : 7 }));
        const kind = el('text', { class: 'kind', x: x - w / 2 + 8, y: y - h / 2 - 5 });
        kind.textContent = node.sub && 'tool' !== node.kind ? node.sub : ('tool' === node.kind ? node.sub : node.kind);
        const label = el('text', { x: x - w / 2 + 10, y: y + ('tool' === node.kind ? 4 : 0) });
        label.textContent = truncate(node.label, 'tool' === node.kind ? 18 : 16);
        g.append(kind, label);
        if ('tool' !== node.kind) {
            const sub = el('text', { class: 'sub', x: x - w / 2 + 10, y: y + 14 });
            sub.dataset.role = 'stats';
            g.append(sub);
        }
        g.addEventListener('click', () => this.onSelect({ type: 'node', id: node.id }));
        g.addEventListener('keydown', (e) => ('Enter' === e.key || ' ' === e.key) && (e.preventDefault(), this.onSelect({ type: 'node', id: node.id })));
        g.addEventListener('mouseenter', () => this.showTooltip(node.id));
        g.addEventListener('mouseleave', () => (this.tooltip.hidden = true));
        this.nodeLayer.append(g);
        this.nodeEls.set(node.id, g);
    }

    updateNode(node) {
        const g = this.nodeEls.get(node.id);
        g.classList.toggle('err', node.error);
        g._node = node;
        const stats = g.querySelector('[data-role="stats"]');
        if (stats) {
            stats.textContent = 'agent' === node.kind ? truncate(node.sub, 20) : node.calls ? `${node.calls}× ${null === node.lastMs ? '' : `· ${node.lastMs} ms`}` : '';
        }
    }

    addEdge(key) {
        const [from, to] = key.split('>');
        const a = this.positions.get(from);
        const b = this.positions.get(to);
        if (!a || !b) {
            return;
        }
        const mcp = from.startsWith('mcp:') || to.startsWith('mcp:');
        const path = el('path', { d: curve(a, b), class: `g-edge${mcp ? ' mcp' : ''}` });
        const hit = el('path', { d: curve(a, b), class: 'g-edge-hit' });
        hit.addEventListener('click', () => this.onSelect({ type: 'edge', from, to }));
        this.edgeLayer.append(path, hit);
        this.edgeEls.set(key, path);
    }

    place(node) {
        switch (node.kind) {
            case 'user': return [70, 240];
            case 'agent': return [230, 240];
            case 'model': return [430, 92];
            case 'store': return [430, 392 - 56 * this.slots.store++];
            case 'mcp': return [560, 268 + 70 * this.slots.mcp++];
            default:
                if (node.parent) {
                    return [770, 180 + 50 * this.slots.mcpTool++];
                }
                return [680 + 0 * this.slots.tool, 60 + 46 * this.slots.tool++];
        }
    }

    pulse(from, to, variant = '') {
        const key = this.edgeEls.has(`${from}>${to}`) ? `${from}>${to}` : `${to}>${from}`;
        const edge = this.edgeEls.get(key);
        if (!edge) {
            return;
        }
        edge.classList.add('hot');
        clearTimeout(edge._cool);
        edge._cool = setTimeout(() => edge.classList.remove('hot'), 450);
        if (this.reducedMotion) {
            return;
        }
        const reverse = key !== `${from}>${to}`;
        const length = edge.getTotalLength();
        const dot = el('circle', { r: 'small' === variant ? 2.5 : 4, class: `packet ${'small' === variant ? '' : variant}` });
        this.packetLayer.append(dot);
        const duration = 'small' === variant ? 260 : 480;
        const start = performance.now();
        const step = (now) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = t < .5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2;
            const p = edge.getPointAtLength((reverse ? 1 - eased : eased) * length);
            dot.setAttribute('cx', p.x);
            dot.setAttribute('cy', p.y);
            t < 1 ? requestAnimationFrame(step) : dot.remove();
        };
        requestAnimationFrame(step);
    }

    hold(id, on) {
        const g = this.nodeEls.get(id);
        if (!g) {
            return;
        }
        on ? this.active.set(id, true) : this.active.delete(id);
        g.classList.toggle('active', on);
        g.classList.toggle('dim', !on && !['user', 'agent'].includes(id));
    }

    flash(id) {
        const g = this.nodeEls.get(id);
        if (!g) {
            return;
        }
        g.classList.add('active');
        g.classList.remove('dim');
        setTimeout(() => this.active.has(id) || g.classList.remove('active'), 500);
    }

    fail(id) {
        const g = this.nodeEls.get(id);
        if (!g) {
            return;
        }
        g.classList.add('err');
        if (!this.reducedMotion) {
            g.classList.remove('shake');
            void g.getBBox();
            g.classList.add('shake');
        }
    }

    failEdge(key) {
        this.edgeEls.get(key)?.classList.add('err');
    }

    showTooltip(id) {
        const node = this.nodeEls.get(id)?._node;
        const pos = this.positions.get(id);
        if (!node || !pos) {
            return;
        }
        const box = this.svg.getBoundingClientRect();
        const scale = Math.min(box.width / W, box.height / H);
        const offsetX = (box.width - W * scale) / 2;
        const offsetY = (box.height - H * scale) / 2;
        this.tooltip.textContent = `${'tool' === node.kind ? displayName(node.label, null) : node.label} · ${node.calls} call${1 === node.calls ? '' : 's'}${null === node.lastMs ? '' : ` · last ${node.lastMs} ms`}${node.error ? ' · failed' : ''}`;
        this.tooltip.style.left = `${offsetX + (pos.x - pos.w / 2) * scale}px`;
        this.tooltip.style.top = `${34 + offsetY + (pos.y + pos.h / 2 + 6) * scale}px`;
        this.tooltip.hidden = false;
    }
}

function curve(a, b) {
    const forward = b.x >= a.x;
    const x1 = a.x + (forward ? a.w / 2 : -a.w / 2);
    const x2 = b.x + (forward ? -b.w / 2 : b.w / 2);
    if (Math.abs(a.x - b.x) < 10) {
        const y1 = a.y + (b.y > a.y ? a.h / 2 : -a.h / 2);
        const y2 = b.y + (b.y > a.y ? -b.h / 2 : b.h / 2);
        return `M${a.x},${y1} L${b.x},${y2}`;
    }
    const mid = (x1 + x2) / 2;
    return `M${x1},${a.y} C${mid},${a.y} ${mid},${b.y} ${x2},${b.y}`;
}

function el(name, attrs = {}) {
    const node = document.createElementNS(SVG, name);
    for (const [k, v] of Object.entries(attrs)) {
        node.setAttribute(k, v);
    }
    return node;
}

function truncate(text, max) {
    text = String(text ?? '');
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}
