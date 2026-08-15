import { router, type Page } from "@inertiajs/core";

import { init, pageview, setTraceId, type Config } from "./core";

/**
 * Inertia visits change route without a server request, so the server log
 * never sees them. Returns Inertia's own unsubscribe function.
 *
 * The trace id of a visit arrives in the page props (share it from
 * HandleInertiaRequests), because router events cannot read headers.
 */
export function observeInertia(
    config: Config,
    options: { traceId?: (page: Page) => string | null | undefined } = {}
): () => void {
    init(config);

    return router.on("navigate", (event) => {
        if (options.traceId) {
            setTraceId(options.traceId(event.detail.page) ?? null);
        }

        pageview();
    });
}
