import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { captureError, flush, init, pageview, reset, setTraceId, track } from "../src/core";

let sent: any[];
let beaconOk: boolean;

function payloads(): any[] {
    return sent.flatMap((body) => JSON.parse(body).entries);
}

beforeEach(() => {
    sent = [];
    beaconOk = true;

    // jsdom ships neither, and Blob.text() is async, so capture the raw body.
    (navigator as any).sendBeacon = vi.fn((_url: string, blob: Blob) => {
        if (!beaconOk) return false;
        sent.push((blob as any)[Symbol.for("body")] ?? (blob as any).__body);
        return true;
    });

    const RealBlob = globalThis.Blob;
    vi.stubGlobal(
        "Blob",
        class extends RealBlob {
            __body: string;
            constructor(parts: any[], options?: any) {
                super(parts, options);
                this.__body = String(parts[0]);
            }
        }
    );

    vi.stubGlobal("fetch", vi.fn((_url: string, options: any) => {
        sent.push(options.body);
        return Promise.resolve({ ok: true });
    }));
});

afterEach(() => {
    reset();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

// No "pageview": init() emits one straight away when it is collected, which
// would sit in front of whatever a test is asserting on.
const config = { endpoint: "/_observability", collect: ["error", "vital", "event"] as const };
const withPageviews = { endpoint: "/_observability", collect: ["pageview"] as const };

describe("init", () => {
    it("does nothing without a config", () => {
        expect(() => init(undefined)).not.toThrow();

        track("copy");
        flush();

        expect(sent).toHaveLength(0);
    });

    it("ignores a second call", () => {
        init({ ...config, trace_id: "first" });
        init({ ...config, trace_id: "second" });

        track("copy");
        flush();

        expect(payloads()[0].trace_id).toBe("first");
    });
});

describe("track", () => {
    it("does not throw before init", () => {
        expect(() => track("copy")).not.toThrow();
        expect(sent).toHaveLength(0);
    });

    it("sends the event with the page context", () => {
        init({ ...config, trace_id: "trace-1" });

        track("copy", { field: "countryCode" });
        flush();

        const entry = payloads()[0];

        expect(entry.kind).toBe("event");
        expect(entry.name).toBe("copy");
        expect(entry.props).toEqual({ field: "countryCode" });
        expect(entry.trace_id).toBe("trace-1");
        expect(entry.url).toBe(location.href);
        expect(entry.page_id).toEqual(expect.any(String));
    });

    it("drops a kind the server does not collect", () => {
        init({ endpoint: "/_observability", collect: ["error"] });

        track("copy");
        flush();

        expect(sent).toHaveLength(0);
    });

    it("batches until flushed", () => {
        init(config);

        track("copy");
        track("copy");

        expect(sent).toHaveLength(0);

        flush();

        expect(sent).toHaveLength(1);
        expect(payloads()).toHaveLength(2);
    });
});

describe("transport", () => {
    it("falls back to fetch when the beacon is refused", () => {
        beaconOk = false;
        init(config);

        track("copy");
        flush();

        expect(fetch).toHaveBeenCalledOnce();
        expect(payloads()[0].name).toBe("copy");
    });

    it("survives a transport that throws", () => {
        (navigator as any).sendBeacon = () => {
            throw new Error("nope");
        };
        init(config);

        track("copy");

        expect(() => flush()).not.toThrow();
    });

    it("flushes on pagehide", () => {
        init(config);

        track("copy");
        dispatchEvent(new Event("pagehide"));

        expect(payloads()).toHaveLength(1);
    });
});

describe("errors", () => {
    it("reports an unhandled error immediately", () => {
        init(config);

        dispatchEvent(
            Object.assign(new Event("error"), {
                message: "boom",
                filename: "app.js",
                lineno: 4,
                colno: 2,
                error: Object.assign(new TypeError("boom"), { stack: "at foo" }),
            })
        );

        const entry = payloads()[0];

        expect(entry.kind).toBe("error");
        expect(entry.message).toBe("boom");
        expect(entry.type).toBe("TypeError");
        expect(entry.line).toBe(4);
        expect(entry.handled).toBe(false);
    });

    it("reports one you caught yourself as handled", () => {
        init(config);

        captureError(new RangeError("out of range"));

        const entry = payloads()[0];

        expect(entry.type).toBe("RangeError");
        expect(entry.handled).toBe(true);
    });
});

describe("pageviews", () => {
    it("reports the first as a load and the next as spa", () => {
        init(withPageviews);

        pageview("/second");
        flush();

        const entries = payloads();

        expect(entries[0].nav_type).toBe("load");
        expect(entries[1].nav_type).toBe("spa");
        expect(entries[1].to).toBe("/second");
    });

    it("ignores a repeat of the current url", () => {
        init(withPageviews);

        pageview("/same");
        pageview("/same");
        flush();

        expect(payloads()).toHaveLength(2);
    });

    it("is not collected unless the server asks for it", () => {
        init(config);

        pageview("/second");
        flush();

        expect(sent).toHaveLength(0);
    });

    it("skips everything when sampled out", () => {
        vi.spyOn(Math, "random").mockReturnValue(0.99);
        init({ ...withPageviews, sample: 0.5 });

        pageview("/second");
        flush();

        expect(sent).toHaveLength(0);
    });
});

describe("setTraceId", () => {
    it("retags subsequent entries", () => {
        init({ ...config, trace_id: "first" });

        setTraceId("second");
        track("copy");
        flush();

        expect(payloads()[0].trace_id).toBe("second");
    });
});

describe("inlined build", () => {
    it("exposes the api globally and inits from the injected config", async () => {
        (window as any).__observability = { endpoint: "/_observability", collect: ["event"] };

        await import("../src/auto");

        (window as any).observability.track("copy");
        (window as any).observability.flush();

        expect(payloads()[0].name).toBe("copy");
    });
});

describe("vitals", () => {
    it("reports a metric once, however often the observer delivers it", async () => {
        const callbacks: ((list: any) => void)[] = [];

        vi.stubGlobal(
            "PerformanceObserver",
            class {
                constructor(cb: (list: any) => void) {
                    callbacks.push(cb);
                }
                observe() {}
            }
        );

        const { init: freshInit, flush: freshFlush } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["vital"] });

        // A buffered observer replays what it already delivered.
        const entry = { name: "first-contentful-paint", startTime: 1200 };
        for (const cb of callbacks) cb({ getEntries: () => [entry] });
        for (const cb of callbacks) cb({ getEntries: () => [entry] });

        freshFlush();

        expect(payloads().filter((e) => e.name === "FCP")).toHaveLength(1);
    });
});

/** Captures each PerformanceObserver callback by the entry type it observes. */
const queues: Record<string, any> = {};

function observeVitals(): Record<string, (entries: any[]) => void> {
    const byType: Record<string, (entries: any[]) => void> = {};

    vi.stubGlobal(
        "PerformanceObserver",
        class {
            cb: (list: any) => void;
            queued: any[] = [];
            constructor(cb: (list: any) => void) {
                this.cb = cb;
            }
            observe(options: { type: string }) {
                byType[options.type] = (entries) => this.cb({ getEntries: () => entries });
                queues[options.type] = this;
            }
            takeRecords() {
                const held = this.queued;
                this.queued = [];
                return held;
            }
        }
    );

    return byType;
}

describe("CLS", () => {
    it("reports the worst session window rather than the sum", async () => {
        const observers = observeVitals();
        const { init: freshInit } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["vital"] });

        observers["layout-shift"]([
            // One window: three shifts inside a second of each other.
            { startTime: 0, value: 0.05, hadRecentInput: false },
            { startTime: 500, value: 0.05, hadRecentInput: false },
            { startTime: 900, value: 0.05, hadRecentInput: false },
            // A new window, more than 1s later, and smaller.
            { startTime: 5000, value: 0.02, hadRecentInput: false },
            // Ignored: the user caused it.
            { startTime: 5200, value: 0.9, hadRecentInput: true },
        ]);

        dispatchEvent(new Event("pagehide"));

        const cls = payloads().find((e) => e.name === "CLS");

        expect(cls.value).toBeCloseTo(0.15, 5);
        expect(cls.rating).toBe("needs-improvement");
    });
});

describe("INP", () => {
    it("takes the slowest interaction below 50 of them", async () => {
        const observers = observeVitals();
        const { init: freshInit } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["vital"] });

        observers.event([
            { interactionId: 1, duration: 90 },
            { interactionId: 1, duration: 120 },
            { interactionId: 2, duration: 60 },
            { interactionId: 0, duration: 5000 },
        ]);

        dispatchEvent(new Event("pagehide"));

        expect(payloads().find((e) => e.name === "INP").value).toBe(120);
    });

    it("discounts one candidate per fifty interactions", async () => {
        const observers = observeVitals();
        const { init: freshInit } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["vital"] });

        observers.event(
            Array.from({ length: 60 }, (_, i) => ({ interactionId: i + 1, duration: 100 + i }))
        );

        dispatchEvent(new Event("pagehide"));

        // 60 interactions, so the worst is discarded and the second worst wins.
        expect(payloads().find((e) => e.name === "INP").value).toBe(158);
    });
});

describe("batching", () => {
    it("splits a batch too big for the server rather than losing it", async () => {
        const { init: freshInit, track: freshTrack, flush: freshFlush } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["event"], max_bytes: 400 });

        freshTrack("copy", { note: "x".repeat(150) });
        freshTrack("copy", { note: "y".repeat(150) });
        freshFlush();

        expect(sent.length).toBeGreaterThan(1);
        expect(payloads()).toHaveLength(2);
    });

    it("drops a single entry that cannot fit rather than looping", async () => {
        const { init: freshInit, track: freshTrack, flush: freshFlush } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["event"], max_bytes: 100 });

        freshTrack("copy", { note: "z".repeat(500) });
        freshFlush();

        expect(sent).toHaveLength(0);
    });

    it("does not throw when a prop cannot be serialised", async () => {
        const { init: freshInit, track: freshTrack, flush: freshFlush } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["event"] });

        const circular: any = {};
        circular.self = circular;

        freshTrack("copy", { circular });

        expect(() => freshFlush()).not.toThrow();
        expect(sent).toHaveLength(0);
    });
});


describe("observer draining", () => {
    it("collects an entry still queued when the page hides", async () => {
        const observers = observeVitals();
        const { init: freshInit } = await import("../src/core");
        freshInit({ endpoint: "/_observability", collect: ["vital"] });

        observers["largest-contentful-paint"]([{ startTime: 1000 }]);
        // A later paint the observer has not delivered yet.
        queues["largest-contentful-paint"].queued = [{ startTime: 2400 }];

        dispatchEvent(new Event("pagehide"));

        expect(payloads().find((e) => e.name === "LCP").value).toBe(2400);
    });
});

describe("payload sizing", () => {
    it("measures utf-8 bytes, not utf-16 code units", async () => {
        const { init: freshInit, track: freshTrack, flush: freshFlush } = await import("../src/core");
        // Each emoji is 2 code units but 4 bytes, so a length-based check
        // would think this batch fits.
        freshInit({ endpoint: "/_observability", collect: ["event"], max_bytes: 220 });

        freshTrack("copy", { note: "🎈".repeat(40) });
        freshFlush();

        expect(sent).toHaveLength(0);
    });
});
