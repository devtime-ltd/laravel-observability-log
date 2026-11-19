import { router } from "@inertiajs/core";
import axios from "axios";
import { beforeAll, describe, expect, it, vi } from "vitest";

import { captureError, currentIds, init } from "../src/core";

// The installed router itself, so these follow the events it really
// dispatches rather than ones a test made up. Only its requests are stubbed.
describe("the installed Inertia router", () => {
    const pages: Record<string, Record<string, unknown>> = {
        "/": { component: "Home", props: { traceId: "trace-home" }, url: "/", version: "1" },
        "/films": { component: "Film/Index", props: { traceId: "trace-films" }, url: "/films", version: "1" },
        "/films/1": { component: "Film/Show", props: { traceId: "trace-film" }, url: "/films/1", version: "1" },
        "/films/2": { component: "Film/Show", props: { traceId: "trace-other" }, url: "/films/2", version: "1" },
    };

    const requests: { path: string; visit: unknown; partial: unknown }[] = [];
    const sent: Record<string, any>[] = [];
    const rendered: { component: string; visit: string | null }[] = [];

    const next = (name: string): Promise<void> =>
        new Promise((resolve) => document.addEventListener("inertia:" + name, () => resolve(), { once: true }));

    const settled = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 20));

    const visit = async (url: string, options: Record<string, unknown> = {}): Promise<void> => {
        const finished = next("finish");

        router.visit(url, options);
        await finished;
        await settled();
    };

    beforeAll(async () => {
        vi.stubGlobal("scrollTo", () => {});
        vi.stubGlobal("localStorage", { getItem: () => null, setItem: () => {}, removeItem: () => {} });
        vi.stubGlobal("fetch", (_url: string, options: { body: string }) => {
            sent.push(...JSON.parse(options.body).entries);

            return Promise.resolve({ ok: true });
        });

        axios.defaults.adapter = async (config) => {
            const path = new URL(config.url!, location.href).pathname;

            requests.push({
                path,
                visit: config.headers.get("X-Visit-Id"),
                partial: config.headers.get("X-Inertia-Partial-Data"),
            });

            return {
                data: JSON.stringify(pages[path]),
                status: 200,
                statusText: "OK",
                headers: { "x-inertia": "true" },
                config,
            };
        };

        init({
            endpoint: "/_observability",
            collect: ["error"],
            headers: { visit_id: "X-Visit-Id" },
            visit_id: "visit-of-the-load",
            inertia: { trace_id: "traceId" },
        });

        const opened = next("navigate");

        router.init({
            initialPage: pages["/"] as any,
            resolveComponent: async (name: string) => name,
            swapComponent: async ({ page }: any) => {
                rendered.push({ component: page.component, visit: currentIds().visit_id });
            },
        });

        await opened;
        await settled();
    });

    it("keeps the visit the document opened with", () => {
        expect(currentIds().visit_id).toBe("visit-of-the-load");
    });

    it("sends a full visit's id on its own request, and takes it on", async () => {
        await visit("/films");

        expect(requests.at(-1)).toMatchObject({ path: "/films" });
        expect(requests.at(-1)!.visit).not.toBe("visit-of-the-load");
        expect(currentIds().visit_id).toBe(requests.at(-1)!.visit);

        captureError(new Error("on the films page"));

        expect(sent.at(-1)).toMatchObject({ view: "Film/Index", trace_id: "trace-films", visit_id: requests.at(-1)!.visit });
    });

    it("stays in the visit through a partial reload", async () => {
        const before = currentIds().visit_id;

        await visit("/films", { only: ["films"] });

        expect(requests.at(-1)).toMatchObject({ path: "/films", partial: "films" });
        expect(currentIds().visit_id).toBe(before);
    });

    it("stays in the visit when a filter replaces the history entry", async () => {
        const before = currentIds().visit_id;

        await visit("/films?q=zz", { replace: true });

        expect(currentIds().visit_id).toBe(before);
    });

    it("starts a visit on going back, which asks the server for nothing", async () => {
        await visit("/films/1");
        const shown = currentIds().visit_id;
        const asked = requests.length;
        const restored = next("navigate");

        history.back();
        await restored;
        await settled();

        expect(requests).toHaveLength(asked);
        expect(currentIds().visit_id).not.toBe(shown);

        captureError(new Error("after going back"));

        expect(sent.at(-1)).toMatchObject({ view: "Film/Index", visit_id: currentIds().visit_id });
    });

    it("starts the visit of a page shown from the prefetch cache before that page renders", async () => {
        const before = currentIds().visit_id;
        const prefetched = next("prefetched");

        router.prefetch("/films/2", {}, { cacheFor: 60_000 });
        await prefetched;
        await settled();

        expect(currentIds().visit_id).toBe(before);

        const asked = requests.length;
        const shown = next("navigate");

        router.visit("/films/2");
        await shown;
        await settled();

        expect(requests).toHaveLength(asked);
        expect(currentIds().visit_id).not.toBe(before);
        expect(rendered.at(-1)).toEqual({ component: "Film/Show", visit: currentIds().visit_id });
    });
});
