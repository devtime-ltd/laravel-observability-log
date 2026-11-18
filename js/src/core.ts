export type Kind = "error" | "vital" | "event" | "pageview";

export type IdName = "client_id" | "client_instance_id" | "visit_id";

export type Ids = Record<IdName, string | null>;

type TraceIdOf = (page: any) => string | null | undefined;

export interface Config {
    /** Path the entries are POSTed to. */
    endpoint: string;
    /** Kinds the server accepts. Anything else is dropped before it is sent. */
    collect?: Kind[];
    /** Fraction of vitals and pageviews to send, 0 to 1. */
    sample?: number;
    /** Trace id of the request that rendered the page, so entries join to it. */
    trace_id?: string | null;
    /** Server's body cap. A batch over it is split rather than rejected whole. */
    max_bytes?: number;
    /** This browser's id, kept in localStorage. false keeps none; a string is used as given. */
    client_id?: string | false | null;
    /** Id for this running copy of the page. One is generated when it is absent. */
    client_instance_id?: string | null;
    /** Id for the visit the page opens with. One is generated when it is absent. */
    visit_id?: string | null;
    /** Header each id travels in on same-origin fetch and XHR requests. */
    headers?: Partial<Record<IdName, string | null>> | null;
    /** Follow Inertia visits. trace_id names the page prop holding each visit's trace id. */
    inertia?: boolean | { trace_id?: string | TraceIdOf | null } | null;
    /** Build the page was served from. */
    release?: string | null;
    /** What is on screen, such as a route name or an Inertia component. */
    view?: string | null;
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
let ids: Ids = noIds();
let lastUrl = "";

const collects = (kind: Kind): boolean =>
    !!config && (!config.collect || config.collect.indexOf(kind) !== -1);

const sampled = (): boolean => Math.random() < (config?.sample ?? 1);

const CLIENT_ID_KEY = "observability:client_id";

function noIds(): Ids {
    return { client_id: null, client_instance_id: null, visit_id: null };
}

function uuid(): string {
    const source = typeof crypto === "undefined" ? undefined : crypto;

    if (source?.randomUUID) return source.randomUUID();

    // randomUUID needs a secure context. Same version 4 layout without it.
    const byte = (): number =>
        source?.getRandomValues ? source.getRandomValues(new Uint8Array(1))[0] : Math.floor(Math.random() * 256);

    return "10000000-1000-4000-8000-100000000000".replace(/[018]/g, (c) =>
        (+c ^ (byte() & (15 >> (+c / 4)))).toString(16)
    );
}

const plainToken = (value: unknown): value is string => typeof value === "string" && /^[\w-]{1,64}$/.test(value);

/** Null where storage is blocked: an id that changed on every load would not be this browser's. */
function storedClientId(): string | null {
    try {
        const stored = localStorage.getItem(CLIENT_ID_KEY);

        if (plainToken(stored)) return stored;

        const minted = uuid();

        localStorage.setItem(CLIENT_ID_KEY, minted);

        return minted;
    } catch {
        return null;
    }
}

function forgetClientId(): void {
    try {
        localStorage.removeItem(CLIENT_ID_KEY);
    } catch {
        // Nothing was stored where storage is blocked.
    }
}

// Two tabs opening at once each mint one. Both keep whichever is stored
// now: the value on the event may itself have been overwritten since.
function onClientIdStored(event: StorageEvent): void {
    if (event.key !== CLIENT_ID_KEY || !ids.client_id) return;

    try {
        const stored = localStorage.getItem(CLIENT_ID_KEY);

        if (plainToken(stored)) ids.client_id = stored;
    } catch {
        // Storage was blocked after the id was read.
    }
}

// A page brought back from the back/forward cache is the same running copy, resumed.
function onPageShow(event: PageTransitionEvent): void {
    if (event.persisted) startVisit();
}

/** A page restored by back or forward may be a cached copy, still holding what another load was given. */
function restoredFromHistory(): boolean {
    try {
        return (performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming | undefined)?.type === "back_forward";
    } catch {
        return false;
    }
}

function enqueue(entry: Entry, immediate = false): void {
    if (!config || !collects(entry.kind)) return;

    queue.push({
        ...ids,
        trace_id: config.trace_id ?? null,
        release: config.release ?? null,
        view: config.view ?? null,
        url: location.href,
        referrer: document.referrer || null,
        viewport: { w: innerWidth, h: innerHeight },
        ...entry,
    });

    if (immediate || queue.length >= 10) flush();
}

export function flush(): void {
    if (!config || !queue.length) return;

    const batch = queue;
    queue = [];
    send(batch);
}

/** Halves a batch too big for the server rather than losing all of it. */
function send(entries: Entry[]): void {
    let body: string;

    try {
        body = JSON.stringify({ entries });
    } catch {
        // A prop the caller passed is circular or a BigInt. Dropping it beats
        // throwing out of the click handler that produced it.
        return;
    }

    // The server caps UTF-8 bytes; String.length counts UTF-16 code units, so
    // one emoji would slip past here and be rejected there.
    const blob = typeof Blob === "function" ? new Blob([body], { type: "application/json" }) : null;
    const size = blob ? blob.size : body.length;
    const cap = config?.max_bytes ?? 0;

    if (cap && size > cap) {
        if (entries.length < 2) return;

        const half = Math.ceil(entries.length / 2);
        send(entries.slice(0, half));
        send(entries.slice(half));

        return;
    }

    transmit(body, blob);
}

function transmit(body: string, blob: Blob | null): void {
    try {
        if (navigator.sendBeacon && blob) {
            if (navigator.sendBeacon(config!.endpoint, blob)) return;
        }

        void fetch(config!.endpoint, {
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

/** Name what is on screen, after an SPA visit. */
export function setView(view: string | null): void {
    if (config) config.view = view;
}

/** The ids every entry carries: this browser, this running copy of the page, and the current visit. */
export function currentIds(): Ids {
    return { ...ids };
}

/** Call from an SPA router when a navigation starts. Returns the new visit's id. */
export function startVisit(id?: string | null): string {
    return (ids.visit_id = id || uuid());
}

function sameOrigin(url: string): boolean {
    try {
        return new URL(url, document.baseURI).origin === location.origin;
    } catch {
        return false;
    }
}

type TaggedXhr = XMLHttpRequest & { __observabilitySameOrigin?: boolean; __observabilitySet?: Record<string, boolean> };

let restorePropagation: (() => void) | null = null;

/**
 * Sends the ids with the page's own requests, so the server can put them
 * on the log entries they produce. Same-origin only: a custom header on a
 * cross-origin request forces a preflight the other side may refuse. A
 * header the caller set itself is left alone.
 */
function propagateIds(headers: Partial<Record<IdName, string | null>>): void {
    const xhr = typeof XMLHttpRequest === "function" ? XMLHttpRequest.prototype : null;
    const open = xhr?.open;
    const send = xhr?.send;
    const setHeader = xhr?.setRequestHeader;
    const nativeFetch = typeof fetch === "function" ? fetch : null;

    const each = (apply: (header: string, value: string) => void): void => {
        for (const name of Object.keys(headers) as IdName[]) {
            const header = headers[name];
            const value = ids[name];

            if (header && value) apply(header, value);
        }
    };

    if (xhr && open && send && setHeader) {
        xhr.open = function (this: TaggedXhr, ...args: any[]) {
            this.__observabilitySameOrigin = sameOrigin(String(args[1]));
            this.__observabilitySet = {};

            return (open as any).apply(this, args);
        };

        xhr.setRequestHeader = function (this: TaggedXhr, name: string, value: string) {
            if (this.__observabilitySet) this.__observabilitySet[String(name).toLowerCase()] = true;

            return setHeader.call(this, name, value);
        };

        xhr.send = function (this: TaggedXhr, ...args: any[]) {
            if (this.__observabilitySameOrigin) {
                each((header, value) => {
                    if (this.__observabilitySet?.[header.toLowerCase()]) return;

                    try {
                        setHeader.call(this, header, value);
                    } catch {
                        // Not opened, or already sent. The request still goes.
                    }
                });
            }

            return (send as any).apply(this, args);
        };
    }

    if (nativeFetch) {
        window.fetch = function (input: RequestInfo | URL, init?: RequestInit) {
            let headers: Headers | null = null;

            try {
                const request = typeof input === "object" && !(input instanceof URL) ? (input as Request) : null;

                if (sameOrigin(request ? request.url : String(input))) {
                    // Headers in init replace a Request's own, so start from whichever fetch would have used.
                    headers = new Headers(init?.headers ?? request?.headers);

                    each((header, value) => {
                        if (!headers!.has(header)) headers!.set(header, value);
                    });
                }
            } catch {
                // An input the page should hear about from fetch itself.
                headers = null;
            }

            return headers ? nativeFetch.call(window, input, { ...init, headers }) : nativeFetch.call(window, input, init);
        };
    }

    restorePropagation = () => {
        if (xhr && open && send && setHeader) {
            xhr.open = open;
            xhr.send = send;
            xhr.setRequestHeader = setHeader;
        }

        if (nativeFetch) window.fetch = nativeFetch;
    };
}

let stopInertia: (() => void) | null = null;
let navigated = false;

/**
 * Inertia announces its visits as events on the document, so this needs no
 * import and can run before the app's bundle has loaded.
 *
 * A visit that asks the server for a whole page and adds a history entry is
 * a new visit: a link, a form submission, a redirect. Its request carries
 * the new id, and the page takes it on when the response arrives. Prefetches,
 * async visits (reloads, polls, deferred props), partial reloads and visits
 * that replace the history entry stay in the visit they were made from.
 */
export function followInertia(traceIdOf: string | TraceIdOf | null = null): () => void {
    let pending: string | null = null;
    let adopted: string | null = null;
    let previous: string | null = null;
    let full: any = null;
    let early: unknown = null;
    let popped = false;
    let background: any[] = [];

    stopInertia?.();

    const follow = (page: any, traceId = true): void => {
        if (!page) return;

        if (traceIdOf && traceId) {
            const traceId = typeof traceIdOf === "function" ? traceIdOf(page) : page.props?.[traceIdOf];

            setTraceId(typeof traceId === "string" ? traceId : null);
        }

        setView(typeof page.component === "string" ? page.component : null);
    };

    // Inertia 1 finishes a visit with a copy of the object it started, sharing its URL object.
    const same = (a: any, b: any): boolean => !!a && !!b && (a === b || (typeof a.url === "object" && a.url === b.url));

    const elsewhere = (page: any): boolean => {
        if (typeof page?.url !== "string") return false;

        try {
            const to = new URL(page.url, location.href);

            return to.pathname + to.search !== location.pathname + location.search;
        } catch {
            return false;
        }
    };

    const adopt = (): void => {
        if (!pending) return;

        previous = ids.visit_id;
        adopted = pending;
        pending = null;
        startVisit(adopted);
    };

    const handlers: Record<string, (detail: any) => void> = {
        start({ visit }) {
            // A prefetch only fills a cache. Using what it fetched is a navigation of its own.
            if (!visit || visit.prefetch) return;

            popped = false;

            if (visit.async || visit.replace || visit.only?.length || visit.except?.length || visit.reset?.length) {
                if (background.length > 50) background = [];
                background.push(visit);

                return;
            }

            pending = visit.id || uuid();
            adopted = previous = null;
            full = visit;

            const header = config?.headers?.visit_id;

            // A copy, because the app may hand every visit the same headers object.
            if (header) visit.headers = { ...visit.headers, [header]: pending };
        },

        // Before the page renders, so an error thrown by that render is
        // logged against the visit and the view it belongs to.
        beforeUpdate({ page }) {
            if (pending) {
                adopt();
            } else if (!full && !background.length && elsewhere(page)) {
                // A response nothing in flight asked for, leading elsewhere: a page prefetched earlier.
                early = page.url;
                startVisit();
            }

            follow(page);
        },

        // Inertia before 2.2 has no beforeUpdate, and fires no navigate for a
        // visit that ends on the address it began on.
        success({ page }) {
            if (!pending) return;

            adopt();
            follow(page);
        },

        // By identity: a redirect Inertia follows itself starts a second
        // visit under the first one's id before the first has finished.
        finish({ visit }) {
            if (same(visit, full)) {
                // A background response arrived first and took the id on, and then the visit was abandoned.
                if (adopted && previous && ids.visit_id === adopted && (visit.cancelled || visit.interrupted)) {
                    startVisit(previous);
                }

                pending = adopted = previous = full = null;
            }

            background = background.filter((other) => !same(visit, other));
        },

        navigate({ page, type, visitId }) {
            const first = !navigated;
            navigated = true;

            // The page the document opened with, not a navigation within it.
            // Back and forward follow a popstate, so the first navigation seen
            // is one of those when the agent began listening late.
            const opening = type === "initial" || ((!type || type === "history") && first && !popped);

            // Navigations no request announced: back and forward, a page
            // prefetched earlier, a client-side push, an instant swap.
            if (type) {
                const unannounced =
                    type === "history"
                        ? !opening
                        : type === "visit" &&
                          !!visitId &&
                          visitId !== ids.visit_id &&
                          !background.some((visit) => visit.id === visitId);

                if (unannounced && early !== page?.url) startVisit(visitId);
            } else if (pending) {
                adopt();
            } else if (early !== page?.url && (popped || (!opening && !full && !background.length))) {
                // Inertia before 3.9 does not say which kind this is, so it is
                // unannounced when no visit that could have caused it is in flight.
                startVisit();
            }

            early = null;
            popped = false;

            // The page a document opens with was given its trace id by the
            // server. Its props may be a cached copy's.
            follow(page, !opening);
            pageview();
        },
    };

    const onPopState = (): void => {
        popped = true;
    };

    addEventListener("popstate", onPopState);

    const listeners = Object.keys(handlers).map((name) => {
        const listener = (event: Event): void => handlers[name]((event as CustomEvent).detail ?? {});

        document.addEventListener("inertia:" + name, listener);

        return (): void => document.removeEventListener("inertia:" + name, listener);
    });

    return (stopInertia = () => {
        listeners.forEach((stop) => stop());
        removeEventListener("popstate", onPopState);
    });
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

let pending: { observer: PerformanceObserver; cb: (entries: any[]) => void }[] = [];

function observe(type: string, cb: (entries: any[]) => void, extra?: Record<string, unknown>): void {
    try {
        const observer = new PerformanceObserver((list) => cb(list.getEntries()));

        observer.observe({ type, buffered: true, ...extra });
        pending.push({ observer, cb });
    } catch {
        // Unsupported entry type. The others still report.
    }
}

/**
 * An entry recorded just before the page hides can still be sitting in its
 * observer's queue, and that callback would arrive after the metrics were
 * final. Drain each one first.
 */
function drainObservers(): void {
    for (const { observer, cb } of pending) {
        try {
            if (typeof observer.takeRecords === "function") cb(observer.takeRecords());
        } catch {
            // Nothing to drain.
        }
    }
}

function watchVitals(): void {
    let lcp = 0;
    let cls = 0;
    let session = 0;
    let sessionStart = 0;
    let sessionLast = 0;
    const interactions = new Map<number, number>();

    observe("largest-contentful-paint", (entries) => {
        lcp = entries[entries.length - 1]?.startTime ?? lcp;
    });

    // CLS is the worst 5s session window, not the sum over the page: shifts
    // more than 1s apart, or beyond 5s from the window's start, open a new one.
    observe("layout-shift", (entries) => {
        for (const entry of entries) {
            if (entry.hadRecentInput) continue;

            if (session && entry.startTime - sessionLast < 1000 && entry.startTime - sessionStart < 5000) {
                session += entry.value;
            } else {
                session = entry.value;
                sessionStart = entry.startTime;
            }

            sessionLast = entry.startTime;
            cls = Math.max(cls, session);
        }
    });

    // INP is the slowest interaction, discounted by one candidate per 50
    // interactions, rather than the slowest single event.
    observe("event", (entries) => {
        for (const entry of entries) {
            if (!entry.interactionId) continue;

            interactions.set(
                entry.interactionId,
                Math.max(entry.duration, interactions.get(entry.interactionId) ?? 0)
            );
        }
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

        const candidates = [...interactions.values()].sort((a, b) => b - a);
        const inp = candidates[Math.min(candidates.length - 1, Math.floor(interactions.size / 50))];

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

    drainObservers();

    for (const fn of handlers) fn();
    flush();
}

export function init(options: Config | null | undefined): void {
    if (config || !options || !options.endpoint || typeof window === "undefined") return;

    const restored = restoredFromHistory();

    config = { ...options, trace_id: restored ? null : options.trace_id };
    if (options.client_id === false) forgetClientId();

    ids = {
        client_id: options.client_id === false ? null : options.client_id || storedClientId(),
        client_instance_id: (!restored && options.client_instance_id) || uuid(),
        visit_id: (!restored && options.visit_id) || uuid(),
    };

    if (options.client_id == null) addEventListener("storage", onClientIdStored);
    addEventListener("pageshow", onPageShow);
    lastUrl = "";
    reported = {};

    if (options.headers) propagateIds(options.headers);

    if (options.inertia) followInertia((options.inertia === true ? null : options.inertia.trace_id) ?? null);

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
    pending = [];
    hiddenHandlers = [];
    lastUrl = "";
    ids = noIds();
    restorePropagation?.();
    restorePropagation = null;
    stopInertia?.();
    stopInertia = null;
    navigated = false;

    if (typeof removeEventListener === "function") {
        removeEventListener("storage", onClientIdStored);
        removeEventListener("pageshow", onPageShow);
    }
}
