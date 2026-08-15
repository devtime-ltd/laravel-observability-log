export type Kind = "error" | "vital" | "event" | "pageview";

export interface Config {
    /** Path the entries are POSTed to. */
    endpoint: string;
    /** Kinds the server accepts. Anything else is dropped before it is sent. */
    collect?: Kind[];
    /** Fraction of vitals and pageviews to send, 0 to 1. */
    sample?: number;
    /** Trace id of the request that rendered the page, so entries join to it. */
    trace_id?: string | null;
}

type Entry = Record<string, unknown> & { kind: Kind };

const VITAL_THRESHOLDS: Record<string, [number, number]> = {
    LCP: [2500, 4000],
    CLS: [0.1, 0.25],
    INP: [200, 500],
    TTFB: [800, 1800],
    FCP: [1800, 3000],
};

let config: Config | null = null;
let queue: Entry[] = [];
let reported: Record<string, boolean> = {};
let pageId = "";
let lastUrl = "";

const collects = (kind: Kind): boolean =>
    !!config && (!config.collect || config.collect.indexOf(kind) !== -1);

const sampled = (): boolean => Math.random() < (config?.sample ?? 1);

const id = (): string =>
    Math.random().toString(36).slice(2, 10) + Date.now().toString(36);

function enqueue(entry: Entry, immediate = false): void {
    if (!config || !collects(entry.kind)) return;

    queue.push({
        page_id: pageId,
        trace_id: config.trace_id ?? null,
        url: location.href,
        referrer: document.referrer || null,
        viewport: { w: innerWidth, h: innerHeight },
        ...entry,
    });

    if (immediate || queue.length >= 10) flush();
}

export function flush(): void {
    if (!config || !queue.length) return;

    const body = JSON.stringify({ entries: queue });
    queue = [];

    try {
        if (navigator.sendBeacon) {
            const blob = new Blob([body], { type: "application/json" });
            if (navigator.sendBeacon(config.endpoint, blob)) return;
        }

        void fetch(config.endpoint, {
            method: "POST",
            body,
            headers: { "Content-Type": "application/json" },
            keepalive: true,
            credentials: "omit",
        }).catch(() => {});
    } catch {
        // Telemetry must never break the page it is measuring.
    }
}

/** A named event from the allowlist the server was configured with. */
export function track(name: string, props?: Record<string, unknown>): void {
    enqueue({ kind: "event", name, props });
}

/** Call from an SPA router. The first one is reported as a full load. */
export function pageview(url?: string): void {
    const to = url ?? location.href;
    const from = lastUrl;

    if (to === from) return;

    lastUrl = to;

    if (sampled()) {
        enqueue({ kind: "pageview", from: from || document.referrer || null, to, nav_type: from ? "spa" : "load" });
    }
}

function reportError(fields: Record<string, unknown>): void {
    enqueue({ kind: "error", ...fields }, true);
}

/** Report an error you caught yourself, from a boundary or a try/catch. */
export function captureError(error: unknown, handled = true): void {
    const e = error as { message?: string; name?: string; stack?: string } | null;

    reportError({
        message: String(e?.message ?? error ?? "Error"),
        type: e?.name,
        stack: e?.stack,
        handled,
    });
}

/** Point subsequent entries at a new trace id, after an SPA visit. */
export function setTraceId(traceId: string | null): void {
    if (config) config.trace_id = traceId;
}

function watchErrors(): void {
    addEventListener("error", (e: ErrorEvent) => {
        reportError({
            message: e.message,
            type: e.error?.name,
            source: e.filename,
            line: e.lineno,
            col: e.colno,
            stack: e.error?.stack,
            handled: false,
        });
    });

    addEventListener("unhandledrejection", (e: PromiseRejectionEvent) => {
        const reason: any = e.reason;

        reportError({
            message: String(reason?.message ?? reason ?? "Unhandled rejection"),
            type: reason?.name ?? "UnhandledRejection",
            stack: reason?.stack,
            handled: false,
        });
    });
}

function vital(name: string, value: number): void {
    // A buffered observer replays an entry it already delivered, so without
    // this TTFB and FCP report twice on most loads.
    if (reported[name]) return;
    reported[name] = true;

    const [good, poor] = VITAL_THRESHOLDS[name];

    enqueue({
        kind: "vital",
        name,
        value,
        rating: value <= good ? "good" : value > poor ? "poor" : "needs-improvement",
    });
}

function observe(type: string, cb: (entries: any[]) => void, extra?: Record<string, unknown>): void {
    try {
        new PerformanceObserver((list) => cb(list.getEntries())).observe({
            type,
            buffered: true,
            ...extra,
        });
    } catch {
        // Unsupported entry type. The others still report.
    }
}

function watchVitals(): void {
    let lcp = 0;
    let cls = 0;
    let inp = 0;

    observe("largest-contentful-paint", (entries) => {
        lcp = entries[entries.length - 1]?.startTime ?? lcp;
    });

    observe("layout-shift", (entries) => {
        for (const entry of entries) if (!entry.hadRecentInput) cls += entry.value;
    });

    observe("event", (entries) => {
        for (const entry of entries) inp = Math.max(inp, entry.duration);
    }, { durationThreshold: 40 });

    observe("paint", (entries) => {
        for (const entry of entries) {
            if (entry.name === "first-contentful-paint") vital("FCP", entry.startTime);
        }
    });

    observe("navigation", (entries) => {
        const ttfb = entries[0]?.responseStart;
        if (ttfb > 0) vital("TTFB", ttfb);
    });

    // The web vitals that only settle at the end of the page's life.
    onHidden(() => {
        if (lcp) vital("LCP", lcp);
        if (cls) vital("CLS", cls);
        if (inp) vital("INP", inp);
    });
}

let hiddenHandlers: (() => void)[] = [];

function onHidden(fn: () => void): void {
    hiddenHandlers.push(fn);
}

function runHidden(): void {
    const handlers = hiddenHandlers;
    hiddenHandlers = [];
    for (const fn of handlers) fn();
    flush();
}

export function init(options: Config | null | undefined): void {
    if (config || !options || !options.endpoint || typeof window === "undefined") return;

    config = options;
    pageId = id();
    lastUrl = "";
    reported = {};

    if (collects("error")) watchErrors();
    if (collects("vital") && sampled()) watchVitals();
    if (collects("pageview")) pageview();

    addEventListener("visibilitychange", () => {
        if (document.visibilityState === "hidden") runHidden();
    });
    addEventListener("pagehide", runHidden);
}

/** Test seam: drop all state so a suite can init again. */
export function reset(): void {
    config = null;
    queue = [];
    reported = {};
    hiddenHandlers = [];
    lastUrl = "";
}
