// Plays a stored run back through the same path as live events, on a virtual clock:
// play, pause, speed and scrub. Scrubbing rebuilds the state up to the target time without animation.

export class Player {
    constructor(events, { onReset, onEvent, onTick, onEnd }) {
        this.events = [...events].sort((a, b) => a.seq - b.seq);
        this.duration = this.events.length ? Math.max(...this.events.map((e) => e.ts)) : 0;
        this.handlers = { onReset, onEvent, onTick, onEnd };
        this.speed = 1;
        this.time = 0;
        this.index = 0;
        this.playing = false;
        this.frame = null;
    }

    play() {
        if (this.playing) {
            return;
        }
        if (this.index >= this.events.length) {
            this.seek(0);
        }
        this.playing = true;
        let last = performance.now();
        const loop = (now) => {
            if (!this.playing) {
                return;
            }
            this.time = Math.min(this.duration, this.time + (now - last) * this.speed);
            last = now;
            this.flush(true);
            this.handlers.onTick(this.time);
            if (this.index >= this.events.length) {
                this.playing = false;
                this.handlers.onEnd();
                return;
            }
            this.frame = requestAnimationFrame(loop);
        };
        this.frame = requestAnimationFrame(loop);
    }

    pause() {
        this.playing = false;
        cancelAnimationFrame(this.frame);
    }

    seek(time) {
        const resume = this.playing;
        this.pause();
        this.time = Math.max(0, Math.min(this.duration, time));
        this.index = 0;
        this.handlers.onReset();
        this.flush(false);
        this.handlers.onTick(this.time);
        if (resume) {
            this.play();
        }
    }

    stop() {
        this.pause();
        this.events = [];
    }

    flush(animate) {
        while (this.index < this.events.length && this.events[this.index].ts <= this.time) {
            this.handlers.onEvent(this.events[this.index], animate);
            this.index += 1;
        }
    }
}
