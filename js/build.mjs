import { build } from "esbuild";
import { gzipSync } from "node:zlib";
import { readFileSync, writeFileSync } from "node:fs";
import { execFileSync } from "node:child_process";

// The inlined agent is served on every request of every page that uses the
// directive, so its size is worth watching. This is a tripwire for a
// regression that changes the order of magnitude, not a tight ceiling.
const BUDGET_BYTES = 4096;
const INLINE_TARGET = "../resources/client.min.js";

await build({
    entryPoints: ["src/index.ts", "src/react.tsx", "src/inertia.ts"],
    outdir: "dist",
    format: "esm",
    target: "es2020",
    bundle: true,
    external: ["react", "react/jsx-runtime", "@inertiajs/core"],
});

execFileSync("npx", ["tsc", "--emitDeclarationOnly"], { stdio: "inherit" });

await build({
    entryPoints: ["src/auto.ts"],
    outfile: INLINE_TARGET,
    format: "iife",
    target: "es2020",
    bundle: true,
    minify: true,
    legalComments: "none",
});

const bytes = readFileSync(INLINE_TARGET);
const gzipped = gzipSync(bytes).length;

writeFileSync(INLINE_TARGET, bytes.toString().trim() + "\n");

console.log(`client.min.js ${bytes.length} bytes, ${gzipped} gzipped (budget ${BUDGET_BYTES})`);

if (gzipped > BUDGET_BYTES) {
    console.error(`over budget by ${gzipped - BUDGET_BYTES} bytes`);
    process.exit(1);
}
