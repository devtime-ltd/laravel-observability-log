import { captureError, flush, init, pageview, track } from "./core";

// Entry point for the inlined build. The Blade directive writes the config
// immediately before this script, and a page with no build step needs a
// global to reach track() through.
const api = { track, pageview, flush, captureError };

type Global = Window & {
    __observability?: Parameters<typeof init>[0];
    observability?: typeof api;
};

const scope = window as Global;

scope.observability = api;

init(scope.__observability);
