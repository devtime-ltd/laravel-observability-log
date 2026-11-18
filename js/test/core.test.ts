import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
    captureError,
    currentIds,
    flush,
    followInertia,
    init,
    pageview,
    reset,
    setTraceId,
    setView,
    startVisit,
    track,
} from "../src/core";

let sent: any[];
let beaconOk: boolean;

// Node's own localStorage global, which is undefined without a backing file, hides jsdom's.
function memoryStorage(): Storage {
    const items = new Map<string, string>();

    return {
        getItem: (key: string) => items.get(key) ?? null,
        setItem: (key: string, value: string) => void items.set(key, String(value)),
        removeItem: (key: string) => void items.delete(key),
        clear: () => items.clear(),
        key: (index: number) => [...items.keys()][index] ?? null,
        get length() {
            return items.size;
        },
    };
}

function payloads(): any[] {
    return sent.flatMap((body) => JSON.parse(body).entries);
}

beforeEach(() => {
    sent = [];
    beaconOk = true;

    vi.stubGlobal("localStorage", memoryStorage());

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
        expect(entry.client_instance_id).toEqual(expect.any(String));
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

describe("release and view", () => {
    it("tags every entry with the build and what is on screen", () => {
        init({ ...config, release: "a1b2c3d", view: "Users/Index" });

        track("copy");
        flush();

        expect(payloads()[0].release).toBe("a1b2c3d");
        expect(payloads()[0].view).toBe("Users/Index");
    });

    it("retags subsequent entries with a new view", () => {
        init({ ...config, view: "Users/Index" });

        setView("Users/Show");
        track("copy");
        flush();

        expect(payloads()[0].view).toBe("Users/Show");
    });
});

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const HEADERS = {
    client_id: "X-Client-Id",
    client_instance_id: "X-Client-Instance-Id",
    visit_id: "X-Visit-Id",
};

describe("ids", () => {
    it("are unknown before init", () => {
        expect(currentIds()).toEqual({ client_id: null, client_instance_id: null, visit_id: null });
    });

    it("are version 4 uuids the entries carry", () => {
        init(config);

        track("copy");
        flush();

        const { client_id, client_instance_id, visit_id } = currentIds();

        expect(client_id).toMatch(UUID_V4);
        expect(client_instance_id).toMatch(UUID_V4);
        expect(visit_id).toMatch(UUID_V4);
        expect(new Set([client_id, client_instance_id, visit_id]).size).toBe(3);
        expect(payloads()[0]).toMatchObject({ client_id, client_instance_id, visit_id });
    });

    it("are still version 4 uuids where randomUUID is missing", () => {
        vi.stubGlobal("crypto", { getRandomValues: (bytes: Uint8Array) => bytes.fill(171) });

        init({ ...config, client_id: false });

        expect(currentIds().visit_id).toBe("abbbbbbb-abbb-4bbb-bbbb-abbbbbbbbbbb");
        expect(currentIds().visit_id).toMatch(UUID_V4);
    });

    it("can be supplied by the app", () => {
        init({ ...config, client_id: "browser", client_instance_id: "instance", visit_id: "visit" });

        expect(currentIds()).toEqual({ client_id: "browser", client_instance_id: "instance", visit_id: "visit" });
        expect(localStorage.length).toBe(0);
    });

    it("keep the client id across page loads, and nothing else", () => {
        init(config);
        const first = currentIds();

        reset();
        init(config);
        const second = currentIds();

        expect(second.client_id).toBe(first.client_id);
        expect(second.client_instance_id).not.toBe(first.client_instance_id);
        expect(second.visit_id).not.toBe(first.visit_id);
        expect(localStorage.getItem("observability:client_id")).toBe(first.client_id);
    });

    it("replace a stored client id that is not a plain token", () => {
        localStorage.setItem("observability:client_id", "<b>not an id</b>");

        init(config);

        expect(currentIds().client_id).toMatch(UUID_V4);
        expect(localStorage.getItem("observability:client_id")).toBe(currentIds().client_id);
    });

    it("take on the client id another tab stored in the same moment", () => {
        init(config);
        const minted = currentIds().client_id;

        dispatchEvent(new StorageEvent("storage", { key: "unrelated", newValue: "ignored" }));

        expect(currentIds().client_id).toBe(minted);

        localStorage.setItem("observability:client_id", "the-other-tabs");
        dispatchEvent(new StorageEvent("storage", { key: "observability:client_id", newValue: "the-other-tabs" }));

        expect(currentIds().client_id).toBe("the-other-tabs");

        localStorage.setItem("observability:client_id", "<b>ignored</b>");
        dispatchEvent(new StorageEvent("storage", { key: "observability:client_id", newValue: "<b>ignored</b>" }));

        expect(currentIds().client_id).toBe("the-other-tabs");

        reset();
        init({ ...config, client_id: false });
        localStorage.setItem("observability:client_id", "the-other-tabs");
        dispatchEvent(new StorageEvent("storage", { key: "observability:client_id", newValue: "the-other-tabs" }));

        expect(currentIds().client_id).toBeNull();
    });

    it("settle on the id stored last when two tabs each minted one, not on the one an event names", () => {
        init(config);

        // This tab wrote last, and only then hears of the other tab's earlier write.
        dispatchEvent(new StorageEvent("storage", { key: "observability:client_id", newValue: "written-first" }));

        expect(currentIds().client_id).toBe(localStorage.getItem("observability:client_id"));
        expect(currentIds().client_id).not.toBe("written-first");
    });

    it("start a visit, in the same instance, when the page comes back from the back/forward cache", () => {
        init({ ...config, trace_id: "trace-load" });
        const { client_instance_id, visit_id } = currentIds();

        dispatchEvent(new PageTransitionEvent("pageshow", { persisted: false }));

        expect(currentIds().visit_id).toBe(visit_id);

        dispatchEvent(new PageTransitionEvent("pageshow", { persisted: true }));
        track("copy");
        flush();

        expect(currentIds().visit_id).toMatch(UUID_V4);
        expect(currentIds().visit_id).not.toBe(visit_id);
        expect(payloads()[0]).toMatchObject({ client_instance_id, trace_id: "trace-load" });
    });

    it("remove the stored client id once it is switched off", () => {
        init(config);
        reset();
        init({ ...config, client_id: false });

        expect(localStorage.length).toBe(0);
    });

    it("do not trust the ids of a page restored by back or forward, which may be a cached copy", () => {
        vi.spyOn(performance, "getEntriesByType").mockReturnValue([{ type: "back_forward" } as any]);

        init({ ...config, client_instance_id: "the-first-loads", visit_id: "the-first-loads", trace_id: "the-first-loads" });
        track("copy");
        flush();

        expect(payloads()[0].client_instance_id).toMatch(UUID_V4);
        expect(payloads()[0].visit_id).toMatch(UUID_V4);
        expect(payloads()[0].trace_id).toBeNull();
    });

    it("store nothing when the client id is switched off", () => {
        init({ ...config, client_id: false });

        track("copy");
        flush();

        expect(localStorage.length).toBe(0);
        expect(payloads()[0].client_id).toBeNull();
        expect(payloads()[0].client_instance_id).toMatch(UUID_V4);
    });

    it("go without a client id where storage is blocked", () => {
        vi.stubGlobal("localStorage", {
            getItem: () => {
                throw new DOMException("denied", "SecurityError");
            },
        });

        init(config);

        expect(currentIds().client_id).toBeNull();
        expect(currentIds().client_instance_id).toMatch(UUID_V4);
    });

    it("start a new visit on request", () => {
        init(config);
        const { client_instance_id, visit_id } = currentIds();

        const started = startVisit();

        expect(started).toMatch(UUID_V4);
        expect(started).not.toBe(visit_id);
        expect(currentIds().visit_id).toBe(started);
        expect(currentIds().client_instance_id).toBe(client_instance_id);
        expect(startVisit("from-the-router")).toBe("from-the-router");
    });
});

describe("id headers", () => {
    let headers: [string, string][];
    let network: any;

    beforeEach(() => {
        headers = [];
        network = vi.fn(() => Promise.resolve({ ok: true }));
        vi.stubGlobal("fetch", network);

        vi.spyOn(XMLHttpRequest.prototype, "open").mockImplementation(() => {});
        vi.spyOn(XMLHttpRequest.prototype, "send").mockImplementation(() => {});
        vi.spyOn(XMLHttpRequest.prototype, "setRequestHeader").mockImplementation((name, value) => {
            headers.push([name, value]);
        });
    });

    const fetched = (call = 0): Request => network.mock.calls[call][0];

    const expected = (): [string, string][] => [
        ["X-Client-Id", currentIds().client_id!],
        ["X-Client-Instance-Id", currentIds().client_instance_id!],
        ["X-Visit-Id", currentIds().visit_id!],
    ];

    it("go out on a same-origin xhr", () => {
        init({ ...config, headers: HEADERS });

        const xhr = new XMLHttpRequest();
        xhr.open("GET", "/users");
        xhr.send();

        expect(headers).toEqual(expected());
    });

    it("leave out an id that has no header, or no value", () => {
        init({ ...config, client_id: false, headers: { ...HEADERS, visit_id: null } });

        const xhr = new XMLHttpRequest();
        xhr.open("GET", "/users");
        xhr.send();

        expect(headers).toEqual([["X-Client-Instance-Id", currentIds().client_instance_id]]);
    });

    it("carry the visit the request was sent from", () => {
        init({ ...config, headers: HEADERS });

        const started = startVisit();

        const xhr = new XMLHttpRequest();
        xhr.open("GET", "/users");
        xhr.send();

        expect(headers).toContainEqual(["X-Visit-Id", started]);
    });

    it("leave a header the caller set on an xhr alone", () => {
        init({ ...config, headers: HEADERS });

        const xhr = new XMLHttpRequest();
        xhr.open("GET", "/users");
        xhr.setRequestHeader("x-visit-id", "the-callers");
        xhr.send();

        expect(headers).toEqual([["x-visit-id", "the-callers"], ...expected().slice(0, 2)]);

        headers = [];
        xhr.open("GET", "/users");
        xhr.send();

        expect(headers).toEqual(expected());
    });

    it("stay off a cross-origin xhr", () => {
        init({ ...config, headers: HEADERS });

        const xhr = new XMLHttpRequest();
        xhr.open("PUT", "https://uploads.example.com/file");
        xhr.send();

        expect(headers).toEqual([]);
    });

    const sentHeaders = (call = 0): [string, string][] =>
        [...(network.mock.calls[call][1].headers as Headers)].filter(([name]) => name.startsWith("x-"));

    it("go out on a same-origin fetch, beside what the caller asked for", () => {
        init({ ...config, headers: HEADERS });

        const options = { method: "POST", headers: { Accept: "application/json" }, body: "name=ada" };
        const url = new URL("/users", location.href);

        void fetch("/users", options);
        void fetch(url);

        expect(network.mock.calls[0][0]).toBe("/users");
        expect(network.mock.calls[0][1]).toMatchObject({ method: "POST", body: "name=ada" });
        expect(sentHeaders(0)).toEqual(expected().map(([name, value]) => [name.toLowerCase(), value]));
        expect(network.mock.calls[0][1].headers.get("Accept")).toBe("application/json");
        expect(options.headers).toEqual({ Accept: "application/json" });
        expect(network.mock.calls[1][0]).toBe(url);
        expect(sentHeaders(1)).toHaveLength(3);
    });

    it("send a Request the caller built untouched, with its headers and theirs beside it", () => {
        init({ ...config, headers: HEADERS });

        const request = new Request(new URL("/users", location.href), {
            method: "POST",
            body: "name=ada",
            headers: { Accept: "text/html", "X-Visit-Id": "the-callers" },
        });

        void fetch(request);
        void fetch(request, { headers: { Accept: "application/json" } });

        expect(network.mock.calls[0][0]).toBe(request);
        expect(request.bodyUsed).toBe(false);
        expect(request.headers.has("X-Client-Instance-Id")).toBe(false);
        expect(network.mock.calls[0][1].headers.get("Accept")).toBe("text/html");
        expect(sentHeaders(0)).toEqual([...expected().slice(0, 2), ["X-Visit-Id", "the-callers"]].map(([name, value]) => [name.toLowerCase(), value]));
        expect(network.mock.calls[1][1].headers.get("Accept")).toBe("application/json");
        expect(sentHeaders(1)).toEqual(expected().map(([name, value]) => [name.toLowerCase(), value]));
    });

    it("stay off a cross-origin fetch, and pass it through untouched", () => {
        init({ ...config, headers: HEADERS });

        const options = { headers: { Accept: "application/json" } };
        const request = new Request("https://api.example.com/users", { method: "POST", body: "name=ada" });

        void fetch("https://api.example.com/users", options);
        void fetch(request, options);

        expect(network.mock.calls[0]).toEqual(["https://api.example.com/users", options]);
        expect(network.mock.calls[1][0]).toBe(request);
        expect(network.mock.calls[1][1]).toBe(options);
        expect(request.bodyUsed).toBe(false);
    });

    it("read a relative address against the document's base, as fetch and xhr do", () => {
        init({ ...config, headers: HEADERS });

        const base = document.head.appendChild(document.createElement("base"));
        base.href = "https://cdn.example.com/app/";

        try {
            void fetch("api/users");

            const xhr = new XMLHttpRequest();
            xhr.open("GET", "api/users");
            xhr.send();
        } finally {
            base.remove();
        }

        expect(network.mock.calls[0]).toEqual(["api/users", undefined]);
        expect(headers).toEqual([]);
    });

    it("leave an input they cannot read to fetch itself", () => {
        init({ ...config, headers: HEADERS });

        const input = { url: "/users" } as unknown as Request;

        void fetch(input);

        expect(network.mock.calls[0][0]).toBe(input);
    });

    it("leave fetch and xhr alone without a header to send", () => {
        const before = { fetch: window.fetch, open: XMLHttpRequest.prototype.open };

        init(config);

        expect(window.fetch).toBe(before.fetch);
        expect(XMLHttpRequest.prototype.open).toBe(before.open);
    });

    it("hand fetch and xhr back on reset", () => {
        const before = {
            fetch: window.fetch,
            open: XMLHttpRequest.prototype.open,
            setRequestHeader: XMLHttpRequest.prototype.setRequestHeader,
        };

        init({ ...config, headers: HEADERS });
        reset();

        expect(window.fetch).toBe(before.fetch);
        expect(XMLHttpRequest.prototype.open).toBe(before.open);
        expect(XMLHttpRequest.prototype.setRequestHeader).toBe(before.setRequestHeader);
    });
});

describe("inertia visits", () => {
    const inertia = (name: string, detail: Record<string, unknown>): void => {
        document.dispatchEvent(new CustomEvent("inertia:" + name, { detail }));
    };

    const visit = (id: string, options: Record<string, unknown> = {}): Record<string, any> => ({
        id,
        prefetch: false,
        async: false,
        replace: false,
        only: [],
        except: [],
        reset: [],
        headers: {},
        ...options,
    });

    const page = (component: string, traceId?: string): Record<string, unknown> => ({
        component,
        props: { traceId },
    });

    const following = { ...config, headers: HEADERS, inertia: { trace_id: "traceId" } };

    it("gives a full visit's request the new id, and the page takes it on when the response arrives", () => {
        init(following);
        const before = currentIds().visit_id;
        const films = visit("visit-films");

        inertia("navigate", { page: page("Home", "trace-home"), type: "initial", visitId: "inertias-own" });
        inertia("start", { visit: films });

        expect(films.headers).toEqual({ "X-Visit-Id": "visit-films" });
        expect(currentIds().visit_id).toBe(before);

        inertia("beforeUpdate", { page: page("Film/Index", "trace-films") });
        captureError(new Error("thrown while Film/Index renders"));

        expect(currentIds().visit_id).toBe("visit-films");
        expect(payloads()[0]).toMatchObject({ visit_id: "visit-films", view: "Film/Index", trace_id: "trace-films" });

        inertia("navigate", { page: page("Film/Index", "trace-films"), type: "visit", visitId: "visit-films" });
        inertia("finish", { visit: films });

        expect(currentIds().visit_id).toBe("visit-films");
    });

    it("stays in the visit it was in when a visit is cancelled or fails", () => {
        init(following);
        const before = currentIds().visit_id;
        const abandoned = visit("visit-abandoned");

        inertia("start", { visit: abandoned });
        inertia("finish", { visit: abandoned });
        inertia("beforeUpdate", { page: page("Home") });

        expect(currentIds().visit_id).toBe(before);
    });

    it("lets the visit that interrupted another be the one the page takes on", () => {
        init(following);
        const first = visit("visit-first");
        const second = visit("visit-second");

        inertia("start", { visit: first });
        inertia("finish", { visit: first });
        inertia("start", { visit: second });
        inertia("beforeUpdate", { page: page("Film/Show") });

        expect(currentIds().visit_id).toBe("visit-second");
    });

    it("carries a visit through a redirect Inertia follows itself, which restarts it under the same id", () => {
        init(following);
        const asked = visit("visit-films");
        const redirected = visit("visit-films");

        inertia("start", { visit: asked });
        inertia("start", { visit: redirected });
        inertia("finish", { visit: asked });
        inertia("beforeUpdate", { page: page("Film/Index") });

        expect(redirected.headers).toEqual({ "X-Visit-Id": "visit-films" });
        expect(currentIds().visit_id).toBe("visit-films");
    });

    it("leaves a headers object the app shares between visits as it found it", () => {
        init(following);
        const shared = {};

        inertia("start", { visit: visit("visit-films", { headers: shared }) });

        expect(shared).toEqual({});
    });

    it("keeps the trace id the server gave the page it opened with, whatever its props say", () => {
        init({ ...following, trace_id: null });

        inertia("navigate", { page: page("Home", "trace-of-a-cached-copy"), type: "initial", visitId: "inertias-own" });
        track("copy");
        flush();

        expect(payloads()[0]).toMatchObject({ trace_id: null, view: "Home" });
    });

    it("still knows the page has opened when it is asked to follow a second time", () => {
        init(following);
        const before = currentIds().visit_id;

        inertia("navigate", { page: page("Home"), type: "initial", visitId: "inertias-own" });
        followInertia("traceId");
        inertia("navigate", { page: page("Film/Index"), type: "history" });

        expect(currentIds().visit_id).not.toBe(before);
    });

    it.each([
        ["an async reload", { async: true }],
        ["a partial reload", { only: ["films"] }],
        ["a reload leaving props out", { except: ["films"] }],
        ["a reload resetting props", { reset: ["films"] }],
        ["a visit replacing the history entry", { replace: true }],
    ])("keeps %s in the visit it was made from", (_name, options) => {
        init(following);
        const before = currentIds().visit_id;
        const background = visit("visit-background", options);

        inertia("start", { visit: background });
        inertia("beforeUpdate", { page: page("Film/Index") });
        inertia("navigate", { page: page("Film/Index"), type: "visit", visitId: "visit-background" });
        inertia("finish", { visit: background });

        expect(background.headers).toEqual({});
        expect(currentIds().visit_id).toBe(before);
    });

    it("keeps a prefetch in the visit it was made from", () => {
        init(following);
        const before = currentIds().visit_id;
        const prefetch = visit("visit-prefetch", { prefetch: true });

        inertia("start", { visit: prefetch });
        inertia("finish", { visit: prefetch });

        expect(prefetch.headers).toEqual({});
        expect(currentIds().visit_id).toBe(before);
    });

    it("gives a visit back its id when a background response took the next one on and that visit was abandoned", () => {
        init(following);
        const before = currentIds().visit_id;
        const poll = visit("visit-poll", { async: true });
        const abandoned = visit("visit-abandoned");
        const completed = visit("visit-completed");

        inertia("start", { visit: poll });
        inertia("start", { visit: abandoned });
        inertia("beforeUpdate", { page: page("Home") });
        inertia("finish", { visit: poll });

        expect(currentIds().visit_id).toBe("visit-abandoned");

        inertia("finish", { visit: { ...abandoned, cancelled: true } });

        expect(currentIds().visit_id).toBe("visit-abandoned");

        inertia("finish", { visit: Object.assign(abandoned, { cancelled: true }) });

        expect(currentIds().visit_id).toBe(before);

        inertia("start", { visit: poll });
        inertia("start", { visit: completed });
        inertia("beforeUpdate", { page: page("Home") });
        inertia("finish", { visit: Object.assign(completed, { completed: true }) });

        expect(currentIds().visit_id).toBe("visit-completed");
    });

    it("starts a visit on back and forward, which ask the server for nothing", () => {
        init(following);
        const before = currentIds().visit_id;

        inertia("navigate", { page: page("Home"), type: "initial", visitId: "inertias-own" });
        inertia("navigate", { page: page("Film/Index", "trace-films"), type: "history" });

        expect(currentIds().visit_id).toMatch(UUID_V4);
        expect(currentIds().visit_id).not.toBe(before);

        captureError(new Error("after going back"));

        expect(payloads()[0]).toMatchObject({ view: "Film/Index", trace_id: "trace-films" });
    });

    it("keeps the visit the page opened with when the page itself was reached by going back", () => {
        init(following);
        const before = currentIds().visit_id;

        inertia("navigate", { page: page("Home"), type: "history", visitId: "inertias-own" });

        expect(currentIds().visit_id).toBe(before);
    });

    it("starts the visit of a page nothing in flight asked for before it renders, and only once", () => {
        init(following);
        const before = currentIds().visit_id;

        inertia("navigate", { page: page("Home"), type: "initial", visitId: "inertias-own" });
        inertia("beforeUpdate", { page: { ...page("Film/Show"), url: "/films/1" } });
        const taken = currentIds().visit_id;

        inertia("navigate", { page: { ...page("Film/Show"), url: "/films/1" }, type: "visit", cached: true, visitId: "visit-prefetched" });

        expect(taken).toMatch(UUID_V4);
        expect(taken).not.toBe(before);
        expect(currentIds().visit_id).toBe(taken);

        inertia("beforeUpdate", { page: { ...page("Film/Show"), url: location.pathname + location.search } });

        expect(currentIds().visit_id).toBe(taken);

        inertia("navigate", { page: { ...page("Film/Index"), url: "/films" }, type: "history" });

        expect(currentIds().visit_id).not.toBe(taken);
    });

    it("starts a visit on going back when that is the first navigation it sees, having begun listening late", () => {
        init({ ...following, trace_id: "trace-films" });
        const before = currentIds().visit_id;

        dispatchEvent(new PopStateEvent("popstate"));
        inertia("navigate", { page: page("Home", "trace-home"), type: "history" });
        track("copy");
        flush();

        expect(currentIds().visit_id).not.toBe(before);
        expect(payloads()[0]).toMatchObject({ view: "Home", trace_id: "trace-home" });
    });

    it("starts a visit for a navigation no request announced", () => {
        init(following);

        inertia("navigate", { page: page("Home"), type: "initial", visitId: "inertias-own" });
        inertia("navigate", { page: page("Film/Show"), type: "visit", cached: true, visitId: "visit-prefetched" });

        expect(currentIds().visit_id).toBe("visit-prefetched");
    });

    it("mints an id for a visit Inertia gave none", () => {
        init(following);
        const before = currentIds().visit_id;
        const old = visit("", { id: undefined });

        inertia("start", { visit: old });
        inertia("beforeUpdate", { page: page("Film/Index") });

        expect(currentIds().visit_id).toMatch(UUID_V4);
        expect(currentIds().visit_id).not.toBe(before);
        expect(old.headers["X-Visit-Id"]).toBe(currentIds().visit_id);
    });

    it("clears the trace id of a page that shares none", () => {
        init({ ...following, trace_id: "trace-load" });

        inertia("beforeUpdate", { page: page("Film/Index") });
        track("copy");
        flush();

        expect(payloads()[0].trace_id).toBeNull();
    });

    it("leaves the trace id alone without a prop to read it from", () => {
        init({ ...config, trace_id: "trace-load", inertia: true });

        inertia("beforeUpdate", { page: page("Film/Index", "trace-films") });
        track("copy");
        flush();

        expect(payloads()[0]).toMatchObject({ trace_id: "trace-load", view: "Film/Index" });
    });

    it("reports each navigation as a pageview", () => {
        init({ ...withPageviews, inertia: true });

        history.replaceState({}, "", "/films");
        inertia("navigate", { page: page("Film/Index"), type: "visit", visitId: "visit-films" });
        flush();

        expect(payloads().map((entry) => entry.nav_type)).toEqual(["load", "spa"]);
    });

    it("ignores inertia unless asked to follow it", () => {
        init(config);
        const before = currentIds().visit_id;

        inertia("start", { visit: visit("visit-films") });
        inertia("beforeUpdate", { page: page("Film/Index") });

        expect(currentIds().visit_id).toBe(before);
    });

    it("stops following on request, and on reset", () => {
        init(config);
        const stop = followInertia((shown: any) => shown.props.traceId);

        inertia("beforeUpdate", { page: page("Film/Index", "trace-films") });
        stop();
        inertia("beforeUpdate", { page: page("Film/Show", "trace-film") });
        track("copy");
        flush();

        expect(payloads()[0]).toMatchObject({ view: "Film/Index", trace_id: "trace-films" });

        reset();
        init({ ...config, inertia: true });
        reset();
        init(config);
        inertia("beforeUpdate", { page: page("Film/Show") });
        track("copy");
        flush();

        expect(payloads()[1].view).toBeNull();
    });
});

// Inertia only says what kind of navigation it was from 3.9.0. The events
// below have the shapes the earlier releases dispatch: navigate carries the
// page alone, there is no beforeUpdate before 2.2.0, and no visit id before 3.4.0.
describe("inertia visits before 3.9", () => {
    const inertia = (name: string, detail: Record<string, unknown>): void => {
        document.dispatchEvent(new CustomEvent("inertia:" + name, { detail }));
    };

    const visit = (options: Record<string, unknown> = {}): Record<string, any> => ({
        url: new URL("https://example.test/films"),
        prefetch: false,
        async: false,
        replace: false,
        only: [],
        except: [],
        reset: [],
        headers: {},
        ...options,
    });

    const page = (component: string, traceId?: string): Record<string, unknown> => ({
        component,
        props: { traceId },
    });

    const following = { ...config, headers: HEADERS, inertia: { trace_id: "traceId" } };

    const open = (): string | null => {
        init(following);
        inertia("navigate", { page: page("Home", "trace-home") });

        return currentIds().visit_id;
    };

    it("keeps the visit the page opened with", () => {
        init(following);
        const before = currentIds().visit_id;

        inertia("navigate", { page: page("Home") });

        expect(currentIds().visit_id).toBe(before);
    });

    it("starts a visit on going back when that is the first navigation it sees, having begun listening late", () => {
        init({ ...following, trace_id: "trace-films" });
        const before = currentIds().visit_id;

        dispatchEvent(new PopStateEvent("popstate"));
        inertia("navigate", { page: page("Home", "trace-home") });
        track("copy");
        flush();

        expect(currentIds().visit_id).not.toBe(before);
        expect(payloads()[0]).toMatchObject({ view: "Home", trace_id: "trace-home" });
    });

    it("takes a full visit on once, though its navigation is not named as its own", () => {
        const before = open();
        const films = visit();

        inertia("start", { visit: films });
        const asked = films.headers["X-Visit-Id"];

        inertia("beforeUpdate", { page: page("Film/Index", "trace-films") });
        inertia("navigate", { page: page("Film/Index", "trace-films") });
        inertia("success", { page: page("Film/Index", "trace-films") });
        inertia("finish", { visit: films });

        expect(asked).toMatch(UUID_V4);
        expect(asked).not.toBe(before);
        expect(currentIds().visit_id).toBe(asked);
    });

    it("takes a full visit on when it navigates, where nothing is dispatched before the update", () => {
        open();
        const films = visit();

        inertia("start", { visit: films });
        inertia("navigate", { page: page("Film/Index", "trace-films") });
        captureError(new Error("thrown once Film/Index is shown"));

        expect(payloads()[0]).toMatchObject({
            visit_id: films.headers["X-Visit-Id"],
            view: "Film/Index",
            trace_id: "trace-films",
        });
    });

    it("takes a full visit on when it succeeds, where it ended on the address it began on and so never navigated", () => {
        open();
        const saved = visit();

        inertia("start", { visit: saved });
        inertia("success", { page: page("Film/Edit", "trace-saved") });
        inertia("finish", { visit: saved });
        captureError(new Error("after saving"));

        expect(payloads()[0]).toMatchObject({ visit_id: saved.headers["X-Visit-Id"], trace_id: "trace-saved" });
    });

    it("starts a visit for a navigation with no visit in flight: back and forward, a prefetched page", () => {
        const before = open();
        const prefetch = visit({ prefetch: true });

        inertia("navigate", { page: page("Film/Index", "trace-films") });
        const back = currentIds().visit_id;

        inertia("start", { visit: prefetch });
        inertia("beforeUpdate", { page: page("Film/Show") });
        inertia("navigate", { page: page("Film/Show") });

        expect(back).toMatch(UUID_V4);
        expect(back).not.toBe(before);
        expect(currentIds().visit_id).not.toBe(back);
    });

    it.each([
        ["an async reload", { async: true }],
        ["a partial reload", { only: ["films"] }],
    ])("keeps %s that moves to another address in the visit it was made from", (_name, options) => {
        const before = open();
        const background = visit(options);

        inertia("start", { visit: background });
        inertia("beforeUpdate", { page: page("Film/Index") });
        inertia("navigate", { page: page("Film/Index") });
        inertia("success", { page: page("Film/Index") });
        inertia("finish", { visit: background });

        expect(currentIds().visit_id).toBe(before);

        inertia("navigate", { page: page("Home") });

        expect(currentIds().visit_id).not.toBe(before);
    });

    it("knows a visit is over when Inertia 1 finishes it with a copy of the object it started", () => {
        const before = open();
        const abandoned = visit();
        const reload = visit({ only: ["films"] });

        inertia("start", { visit: abandoned });
        inertia("finish", { visit: { ...abandoned, cancelled: true } });
        inertia("start", { visit: reload });
        inertia("success", { page: page("Home") });
        inertia("finish", { visit: { ...reload, completed: true } });

        expect(currentIds().visit_id).toBe(before);

        inertia("navigate", { page: page("Film/Index") });

        expect(currentIds().visit_id).not.toBe(before);
        expect(currentIds().visit_id).not.toBe(abandoned.headers["X-Visit-Id"]);
    });
});

describe("inlined build", () => {
    it("exposes the api globally and inits from the injected config", async () => {
        (window as any).__observability = { endpoint: "/_observability", collect: ["event"] };

        await import("../src/auto");

        (window as any).observability.setTraceId("trace-2");
        (window as any).observability.setView("Users/Index");
        (window as any).observability.track("copy");
        (window as any).observability.flush();

        expect(payloads()[0].name).toBe("copy");
        expect(payloads()[0].trace_id).toBe("trace-2");
        expect(payloads()[0].view).toBe("Users/Index");
        expect(payloads()[0].visit_id).toBe((window as any).observability.ids().visit_id);
        expect((window as any).observability.startVisit()).toBe((window as any).observability.ids().visit_id);
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
        freshInit({ endpoint: "/_observability", collect: ["event"], max_bytes: 700 });

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
