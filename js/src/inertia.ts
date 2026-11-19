import type { Page } from "@inertiajs/core";

import { followInertia, init, type Config } from "./core";

/**
 * Inertia visits change route without a full page load, so each one gets
 * its own visit id, view and trace id. Returns a function that stops
 * observing.
 *
 * The trace id of a visit arrives in the page props (share it from
 * HandleInertiaRequests), because router events cannot read headers.
 */
export function observeInertia(
    config: Config,
    options: { traceId?: (page: Page) => string | null | undefined } = {}
): () => void {
    init(config);

    return followInertia(options.traceId ?? null);
}
