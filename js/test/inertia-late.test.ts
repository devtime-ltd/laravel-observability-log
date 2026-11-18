import { router } from "@inertiajs/core";
import axios from "axios";
import { expect, it, vi } from "vitest";

import { captureError, currentIds, init } from "../src/core";

// The installed router again, with the agent starting only after the app is
// up and has navigated, as it does when telemetry is loaded lazily.
it("starts a visit on going back when the agent began listening after Inertia had opened the page", async () => {
    const pages: Record<string, Record<string, unknown>> = {
        "/": { component: "Home", props: { traceId: "trace-home" }, url: "/", version: "1" },
        "/films": { component: "Film/Index", props: { traceId: "trace-films" }, url: "/films", version: "1" },
    };

    const sent: Record<string, any>[] = [];

    const next = (name: string): Promise<void> =>
        new Promise((resolve) => document.addEventListener("inertia:" + name, () => resolve(), { once: true }));

    const settled = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 20));

    vi.stubGlobal("scrollTo", () => {});
    vi.stubGlobal("localStorage", { getItem: () => null, setItem: () => {}, removeItem: () => {} });
    vi.stubGlobal("fetch", (_url: string, options: { body: string }) => {
        sent.push(...JSON.parse(options.body).entries);

        return Promise.resolve({ ok: true });
    });

    axios.defaults.adapter = async (config) => ({
        data: JSON.stringify(pages[new URL(config.url!, location.href).pathname]),
        status: 200,
        statusText: "OK",
        headers: { "x-inertia": "true" },
        config,
    });

    const opened = next("navigate");

    router.init({
        initialPage: pages["/"] as any,
        resolveComponent: async (name: string) => name,
        swapComponent: async () => {},
    });

    await opened;

    const finished = next("finish");

    router.visit("/films");
    await finished;
    await settled();

    init({ endpoint: "/_observability", collect: ["error"], trace_id: "trace-films", inertia: { trace_id: "traceId" } });
    const before = currentIds().visit_id;
    const restored = next("navigate");

    history.back();
    await restored;
    await settled();
    captureError(new Error("after going back"));

    expect(currentIds().visit_id).not.toBe(before);
    expect(sent.at(-1)).toMatchObject({ view: "Home", trace_id: "trace-home", visit_id: currentIds().visit_id });
});
