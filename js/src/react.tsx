import { Component, useMemo, type ErrorInfo, type ReactNode } from "react";

import { captureError, init, pageview, track, type Config } from "./core";

/** Starts the agent once, wherever you mount it in the tree. */
export function ObservabilityProvider({
    config,
    children,
}: {
    config: Config;
    children?: ReactNode;
}) {
    // During render rather than in an effect: an effect runs after the
    // children have mounted, so a child that throws on its first render
    // would report before the agent existed. init is idempotent.
    useMemo(() => init(config), []);

    return <>{children}</>;
}

export function useTrack(): typeof track {
    return track;
}

export function usePageview(): typeof pageview {
    return pageview;
}

/** Reports what it catches, then renders `fallback` in place of the subtree. */
export class ObservabilityBoundary extends Component<
    { children?: ReactNode; fallback?: ReactNode },
    { failed: boolean }
> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        captureError(
            Object.assign(error, {
                stack: [error.stack, info.componentStack].filter(Boolean).join("\n"),
            })
        );
    }

    render() {
        return this.state.failed ? this.props.fallback ?? null : this.props.children;
    }
}
