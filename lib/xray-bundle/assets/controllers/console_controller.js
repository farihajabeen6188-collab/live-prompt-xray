import { Controller } from '../vendor/stimulus.js';
import { Trace } from '../lib/trace.js';
import { Graph } from '../lib/graph.js';
import { InspectorView, MetersView, TokenView, WaterfallView, h, ms } from '../lib/views.js';
import { Player } from '../lib/player.js';
import { renderDiff } from '../lib/diff.js';

export default class extends Controller {
    static values = { config: Object };
    static targets = [
        'prompt', 'runButton', 'scenarioButton', 'scenarioMenu', 'modeBadge', 'connection', 'runLabel',
        'playButton', 'scrub', 'time', 'speed', 'exportLink', 'graph', 'empty', 'scenarioGrid', 'banner', 'bannerText',
        'tooltip', 'tokens', 'inspector', 'tab', 'waterfall', 'meters', 'drawer', 'runsList', 'compareButton', 'drop', 'modal', 'modalBody',
    ];

    connect() {
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.graph = new Graph(this.graphTarget, this.tooltipTarget, { onSelect: (s) => this.select(s), reducedMotion: this.reducedMotion });
        this.tokens = new TokenView(this.tokensTarget);
        this.waterfall = new WaterfallView(this.waterfallTarget, (ref) => this.select(ref));
        this.meters = new MetersView(this.metersTarget);
        this.inspector = new InspectorView(this.inspectorTarget, this.tabTargets, (callId) => this.focusFrames(callId));
        this.trace = null;
        this.player = null;
        this.mode = 'idle';
        this.compare = [];
        this.lastEventAt = 0;
        this.renderQueued = false;
        this.runningRequest = false;
        this.reset(null);
        this.loadScenarios();
        this.subscribe();
        this.refreshRuns();
        this.clock = setInterval(() => this.trace?.running && 'live' === this.mode && this.queueRender(), 100);
        this.boundDrag = (e) => this.onDrag(e);
        for (const type of ['dragenter', 'dragover', 'dragleave', 'drop']) {
            window.addEventListener(type, this.boundDrag);
        }
    }

    disconnect() {
        clearInterval(this.clock);
        this.source?.close();
        this.runSource?.close();
        this.player?.stop();
        for (const type of ['dragenter', 'dragover', 'dragleave', 'drop']) {
            window.removeEventListener(type, this.boundDrag);
        }
    }

    // ---------- transport ----------

    subscribe() {
        const config = this.configValue;
        if ('mercure' !== config.transport || !config.hubUrl) {
            this.setConnection('sse', 'stream per run');
            return;
        }
        const url = new URL(config.hubUrl, window.location.origin);
        url.searchParams.append('topic', `${config.topicPrefix}{runId}`);
        this.source = new EventSource(url);
        this.source.onopen = () => {
            this.setConnection('on', 'live');
            this.hideBanner();
        };
        this.source.onerror = () => {
            this.setConnection('err', 'reconnecting');
            this.showBanner('The live connection dropped. Reconnecting automatically…');
        };
        this.source.onmessage = (message) => this.onLiveEvent(JSON.parse(message.data));
    }

    onLiveEvent(event) {
        if ('live' === this.mode && this.trace?.runId === event.runId) {
            this.feed(event);
            return;
        }
        // Follow runs started elsewhere (another tab, your own app) while idle.
        if ('run.started' === event.type && 'idle' === this.mode && !this.runningRequest) {
            this.startLive(event.runId);
            this.feed(event);
        }
        if ('run.finished' === event.type) {
            this.refreshRuns();
        }
    }

    streamRun(runId) {
        this.runSource?.close();
        this.runSource = new EventSource(`/_xray/runs/${encodeURIComponent(runId)}/stream`);
        this.runSource.onmessage = (message) => this.feed(JSON.parse(message.data));
        this.runSource.onerror = () => this.trace?.finished && this.runSource.close();
    }

    // ---------- live runs ----------

    async run(event) {
        event?.preventDefault();
        const prompt = this.promptTarget.value.trim();
        const runUrl = this.configValue.runUrl;
        if (!prompt || !runUrl || this.runningRequest) {
            return;
        }
        const runId = `run_${[...crypto.getRandomValues(new Uint8Array(10))].map((b) => b.toString(16).padStart(2, '0')).join('')}`;
        this.startLive(runId);
        if ('mercure' !== this.configValue.transport) {
            this.streamRun(runId);
        } else if (this.source && EventSource.OPEN !== this.source.readyState) {
            await new Promise((resolve) => setTimeout(resolve, 300));
        }
        this.setBusy(true);
        try {
            const response = await fetch(runUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ prompt, runId }) });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.showBanner(`The run failed: ${body.error || response.statusText}`);
            }
        } catch (error) {
            this.showBanner(`Could not start the run: ${error.message}`);
        } finally {
            this.setBusy(false);
            setTimeout(() => this.settle(runId), 1200);
        }
    }

    // After the request ended, fill any gap from the store (e.g. events missed during a reconnect).
    async settle(runId) {
        if (this.trace?.runId !== runId || this.trace.finished) {
            return;
        }
        const response = await fetch(`/_xray/runs/${encodeURIComponent(runId)}`);
        if (response.ok) {
            const { events } = await response.json();
            events.forEach((e) => this.feed(e));
        }
    }

    startLive(runId) {
        this.player?.stop();
        this.player = null;
        this.mode = 'live';
        this.reset(runId);
        this.runLabelTarget.textContent = `live · ${runId}`;
    }

    feed(event) {
        if (!this.trace) {
            return;
        }
        for (const applied of this.trace.push(event)) {
            this.lastEventAt = performance.now();
            this.graph.sync(this.trace);
            this.graph.animate(applied, this.trace);
            if ('run.finished' === applied.type) {
                this.finishLive();
            }
        }
        this.queueRender();
    }

    finishLive() {
        const runId = this.trace.runId;
        this.mode = 'idle';
        this.runSource?.close();
        this.refreshRuns();
        this.exportLinkTarget.href = `/_xray/runs/${encodeURIComponent(runId)}/export`;
        this.exportLinkTarget.removeAttribute('aria-disabled');
        this.prepareReplay(runId);
    }

    // ---------- replay ----------

    async replay(runId) {
        const response = await fetch(`/_xray/runs/${encodeURIComponent(runId)}`);
        if (!response.ok) {
            this.showBanner(`Run ${runId} could not be loaded.`);
            return;
        }
        const { run, events } = await response.json();
        this.closeDrawer();
        this.mode = 'replay';
        this.loadPlayer(run.id, events, true);
        this.runLabelTarget.textContent = `replay · ${run.prompt}`;
        this.promptTarget.value = run.prompt;
    }

    async prepareReplay(runId) {
        const response = await fetch(`/_xray/runs/${encodeURIComponent(runId)}`);
        if (response.ok && 'idle' === this.mode && this.trace?.runId === runId) {
            const { events } = await response.json();
            this.loadPlayer(runId, events, false);
            this.player.time = this.player.duration;
            this.player.index = this.player.events.length;
            this.onTick(this.player.duration);
        }
    }

    loadPlayer(runId, events, autoplay) {
        this.player?.stop();
        this.player = new Player(events, {
            onReset: () => this.reset(runId),
            onEvent: (event, animate) => {
                for (const applied of this.trace.push(event)) {
                    this.graph.sync(this.trace);
                    if (animate) {
                        this.graph.animate(applied, this.trace);
                    } else {
                        this.graph.animate({ ...applied, type: `${applied.type}#silent` }, this.trace);
                        this.settleNodes(applied);
                    }
                }
                this.queueRender(animate);
            },
            onTick: (time) => this.onTick(time),
            onEnd: () => {
                this.playButtonTarget.textContent = '▶';
                this.mode = 'idle';
            },
        });
        this.scrubTarget.max = String(this.player.duration);
        this.scrubTarget.disabled = false;
        this.playButtonTarget.disabled = false;
        this.exportLinkTarget.href = `/_xray/runs/${encodeURIComponent(runId)}/export`;
        this.exportLinkTarget.removeAttribute('aria-disabled');
        if (autoplay) {
            this.player.speed = this.currentSpeed();
            this.player.seek(0);
            this.player.play();
            this.playButtonTarget.textContent = '❚❚';
        }
    }

    settleNodes(event) {
        if ('tool.call.finished' === event.type && false === event.data.ok) {
            const call = this.trace.calls.get(event.data.callId);
            call && this.graph.fail(`tool:${call.name}`);
        }
    }

    togglePlay() {
        if (!this.player) {
            return;
        }
        if (this.player.playing) {
            this.player.pause();
            this.playButtonTarget.textContent = '▶';
            this.mode = 'idle';
        } else {
            this.mode = 'replay';
            this.player.speed = this.currentSpeed();
            this.player.play();
            this.playButtonTarget.textContent = '❚❚';
        }
    }

    restart() {
        if (this.player) {
            this.mode = 'replay';
            this.player.seek(0);
            this.player.speed = this.currentSpeed();
            this.player.play();
            this.playButtonTarget.textContent = '❚❚';
        }
    }

    toEnd() {
        if (this.player) {
            this.player.seek(this.player.duration);
            this.playButtonTarget.textContent = '▶';
        }
    }

    scrub() {
        if (this.player) {
            this.player.seek(Number(this.scrubTarget.value));
        }
    }

    setSpeed(event) {
        for (const button of this.speedTargets) {
            button.setAttribute('aria-pressed', String(button === event.currentTarget));
        }
        if (this.player) {
            this.player.speed = this.currentSpeed();
        }
    }

    currentSpeed() {
        return Number(this.speedTargets.find((b) => 'true' === b.getAttribute('aria-pressed'))?.dataset.speed ?? 1);
    }

    onTick(time) {
        this.scrubTarget.value = String(time);
        this.timeTarget.textContent = `${ms(time)} / ${ms(this.player?.duration ?? 0)}`;
    }

    // ---------- rendering ----------

    reset(runId) {
        this.trace = runId ? new Trace(runId) : null;
        this.graph.reset();
        this.inspector.callId = null;
        this.inspector.turn = null;
        this.inspector.frameFilter.callId = '';
        this.emptyTarget.hidden = null !== runId;
        this.render(false);
    }

    queueRender(animate = true) {
        if (this.renderQueued) {
            return;
        }
        this.renderQueued = true;
        requestAnimationFrame(() => {
            this.renderQueued = false;
            this.render(animate);
        });
    }

    render(animate) {
        const trace = this.trace ?? new Trace('');
        const now = 'live' === this.mode && trace.running ? trace.lastTs + (performance.now() - this.lastEventAt) : trace.lastTs;
        this.tokens.render(trace);
        this.waterfall.render(trace, now);
        this.meters.render(trace, now, animate && !this.reducedMotion);
        this.inspector.render(trace);
    }

    select(ref) {
        if (!this.trace) {
            return;
        }
        if ('node' === ref.type) {
            this.graph.select(ref.id);
            if (ref.id.startsWith('tool:')) {
                const name = ref.id.slice(5);
                const call = [...this.trace.calls.values()].filter((c) => c.name === name).at(-1);
                return this.inspectCall(call?.callId);
            }
            if (ref.id.startsWith('mcp:')) {
                this.inspector.frameFilter = { server: ref.id.slice(4), method: '', callId: '' };
                return this.showTab('mcp');
            }
            return this.showTab('prompt');
        }
        if ('edge' === ref.type) {
            const tool = [ref.from, ref.to].find((id) => id.startsWith('tool:'));
            if (tool) {
                return this.select({ type: 'node', id: tool });
            }
            const server = [ref.from, ref.to].find((id) => id.startsWith('mcp:'));
            return server ? this.select({ type: 'node', id: server }) : this.showTab('prompt');
        }
        if ('call' === ref.type) {
            return this.inspectCall(ref.callId);
        }
        if ('frame' === ref.type) {
            return ref.callId ? this.focusFrames(ref.callId) : this.showTab('mcp');
        }
        if ('turn' === ref.type) {
            this.inspector.turn = ref.turn;
        }
        return this.showTab('prompt');
    }

    inspectCall(callId) {
        this.inspector.callId = callId ?? null;
        this.showTab('tool');
    }

    focusFrames(callId) {
        this.inspector.frameFilter = { server: '', method: '', callId };
        this.showTab('mcp');
    }

    tab(event) {
        this.showTab(event.currentTarget.dataset.tab);
    }

    showTab(tab) {
        this.inspector.show(tab);
        this.render(false);
    }

    // ---------- scenarios and prompt ----------

    async loadScenarios() {
        const runUrl = this.configValue.runUrl;
        if (!runUrl) {
            this.promptTarget.disabled = true;
            this.promptTarget.placeholder = 'Viewer mode: runs from your app appear here live';
            this.runButtonTarget.hidden = true;
            this.scenarioButtonTarget.hidden = true;
            this.modeBadgeTarget.textContent = 'viewer';
            this.scenarioGridTarget.replaceChildren(h('p', { class: 'muted' }, 'Run your agent: X-Ray follows every run live. Or open a recorded run from Runs.'));
            return;
        }
        try {
            const response = await fetch(runUrl, { headers: { Accept: 'application/json' } });
            const { mode, model, scenarios } = await response.json();
            this.modeBadgeTarget.textContent = 'demo' === mode ? 'Demo mode: scripted model' : `Live model: ${model}`;
            this.modeBadgeTarget.classList.toggle('badge-demo', 'demo' === mode);
            const item = (s, cls) => h('button', { class: cls, type: 'button', onclick: () => this.pick(s) }, h('strong', {}, s.title), h('span', {}, s.description || s.prompt));
            this.scenarioMenuTarget.replaceChildren(...scenarios.map((s) => item(s, 'menu-item')));
            this.scenarioGridTarget.replaceChildren(...scenarios.map((s) => item(s, 'scenario')));
            if (!this.promptTarget.value && scenarios[0]) {
                this.promptTarget.value = scenarios[0].prompt;
            }
        } catch (error) {
            this.showBanner(`Could not load the scenarios: ${error.message}`);
        }
    }

    pick(scenario) {
        this.promptTarget.value = scenario.prompt;
        this.scenarioMenuTarget.hidden = true;
        this.scenarioButtonTarget.setAttribute('aria-expanded', 'false');
        this.run();
    }

    toggleScenarios() {
        const open = this.scenarioMenuTarget.hidden;
        this.scenarioMenuTarget.hidden = !open;
        this.scenarioButtonTarget.setAttribute('aria-expanded', String(open));
    }

    closeMenus(event) {
        if (!this.scenarioMenuTarget.hidden && !event.target.closest('.menu')) {
            this.scenarioMenuTarget.hidden = true;
            this.scenarioButtonTarget.setAttribute('aria-expanded', 'false');
        }
        if ('Escape' === event.key) {
            this.scenarioMenuTarget.hidden = true;
            this.closeDrawer();
            this.closeModal();
        }
    }

    setBusy(busy) {
        this.runningRequest = busy;
        this.runButtonTarget.disabled = busy;
        this.runButtonTarget.textContent = busy ? 'Running…' : 'Run';
    }

    // ---------- runs drawer, export, import, diff ----------

    openDrawer() {
        this.drawerTarget.classList.add('open');
        this.drawerTarget.removeAttribute('inert');
        this.refreshRuns();
    }

    closeDrawer() {
        this.drawerTarget.classList.remove('open');
        this.drawerTarget.setAttribute('inert', '');
    }

    async refreshRuns() {
        try {
            const response = await fetch('/_xray/runs?limit=60');
            const { runs } = await response.json();
            this.runsListTarget.replaceChildren(...(runs.length ? runs.map((run) => this.runItem(run)) : [h('p', { class: 'insp-empty' }, 'No runs recorded yet.')]));
        } catch {
            this.runsListTarget.replaceChildren(h('p', { class: 'insp-empty' }, 'Runs could not be loaded.'));
        }
    }

    runItem(run) {
        const s = run.summary || {};
        const checked = this.compare.includes(run.id);
        return h('div', { class: `run-item${this.trace?.runId === run.id ? ' current' : ''}` },
            h('input', { type: 'checkbox', 'aria-label': 'Select for comparison', checked, onchange: (e) => this.toggleCompare(run.id, e.target.checked) }),
            h('span', { class: 'prompt', title: run.prompt }, run.prompt || '(no prompt)'),
            h('span', { class: 'actions' },
                h('button', { class: 'btn btn-sm', type: 'button', onclick: () => this.replay(run.id) }, 'Replay'),
                h('a', { class: 'btn btn-sm', href: `/_xray/runs/${run.id}/export`, download: '' }, 'Export')),
            h('span', { class: 'meta' }, [
                'running' === run.status ? 'running' : 'failed' === run.status ? 'failed' : ms(s.durationMs ?? null),
                `${s.toolCalls ?? 0} tools`, run.mode, run.imported ? 'imported' : null, new Date(run.startedAt).toLocaleTimeString(),
            ].filter(Boolean).join(' · ')));
    }

    toggleCompare(runId, on) {
        this.compare = on ? [...this.compare.filter((id) => id !== runId), runId].slice(-2) : this.compare.filter((id) => id !== runId);
        this.compareButtonTarget.disabled = 2 !== this.compare.length;
        if (on) {
            this.refreshRuns();
        }
    }

    async compareRuns() {
        const [a, b] = this.compare;
        const response = await fetch(`/_xray/diff?a=${encodeURIComponent(a)}&b=${encodeURIComponent(b)}`);
        if (!response.ok) {
            this.showBanner('The runs could not be compared.');
            return;
        }
        renderDiff(this.modalBodyTarget, await response.json());
        this.modalTarget.hidden = false;
        this.modalTarget.querySelector('button')?.focus();
    }

    closeModal() {
        this.modalTarget.hidden = true;
    }

    importClick() {
        const input = h('input', { type: 'file', accept: 'application/json,.json' });
        input.addEventListener('change', () => input.files[0] && this.importFile(input.files[0]));
        input.click();
    }

    onDrag(event) {
        if (!event.dataTransfer?.types?.includes('Files')) {
            return;
        }
        event.preventDefault();
        if ('dragenter' === event.type || 'dragover' === event.type) {
            this.dropTarget.hidden = false;
        } else if ('dragleave' === event.type && null === event.relatedTarget) {
            this.dropTarget.hidden = true;
        } else if ('drop' === event.type) {
            this.dropTarget.hidden = true;
            const file = event.dataTransfer.files[0];
            file && this.importFile(file);
        }
    }

    async importFile(file) {
        const response = await fetch('/_xray/import', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: await file.text() });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            this.showBanner(`Import failed: ${body.error || response.statusText}`);
            return;
        }
        this.hideBanner();
        this.refreshRuns();
        this.replay(body.run.id);
    }

    // ---------- small helpers ----------

    setConnection(state, label) {
        const [dot, text] = this.connectionTarget.children;
        dot.className = `dot${'on' === state ? ' on' : 'err' === state ? ' err' : ''}`;
        text.textContent = label;
    }

    showBanner(message) {
        this.bannerTextTarget.textContent = message;
        this.bannerTarget.hidden = false;
    }

    hideBanner() {
        this.bannerTarget.hidden = true;
    }
}
