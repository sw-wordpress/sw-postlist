#!/usr/bin/env node
/**
 * Post-build checks of the generated admin bundle (PLAN.md §9, CODE-STYLE.md).
 *
 * - Size budget (gzip): entry JS, all JS chunks and the stylesheet.
 * - No bundle references a remote font host or uses @import (no CDN, no webfonts).
 * - The stylesheet is scoped: no unscoped `html`/`body` rules that would leak into wp-admin.
 *
 * Budgets are regression guards for the admin app; frontend runtime modules (M6/M7) get their own budgets
 * in this script when they exist.
 */
import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { gzipSync } from "node:zlib";

const root = resolve(import.meta.dirname, "..");
const dist = join(root, "assets/admin");
// CodeMirror (template editor) is a lazy chunk of its own: it only loads on the template editor page.
const BUDGET_KIB = {
    "app.js": 120,
    "app.css": 60,
    codeEditor: 200,
    otherChunksTotal: 150,
};
// Frontend runtime (M6): modules load only for the layouts that need them, so each has its own small budget.
const FRONTEND_BUDGET_KIB = {
    "assets/js/masonry.js": 2,
    "assets/js/slider.js": 4,
    "assets/js/dynamic.js": 6,
    "assets/css/frontend.css": 8,
};
const failures = [];
const kib = (bytes) => Math.round((bytes / 1024) * 100) / 100;

function files(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? files(path) : [path];
    });
}

if (!existsSync(join(dist, "app.js"))) {
    console.error('assets/admin/app.js missing: run "make build" first.');
    process.exit(1);
}

const gzipKib = (path) => kib(gzipSync(readFileSync(path)).length);
const entry = gzipKib(join(dist, "app.js"));
const css = gzipKib(join(dist, "app.css"));
const chunkFiles = files(join(dist, "chunks")).filter((file) =>
    file.endsWith(".js"),
);
const isEditor = (file) => /CodeEditor-/.test(file);
const sum = (list) =>
    Math.round(list.reduce((total, file) => total + gzipKib(file), 0) * 100) /
    100;
const editor = sum(chunkFiles.filter(isEditor));
const others = sum(chunkFiles.filter((file) => !isEditor(file)));
console.log(`app.js ${entry} KiB gzip (budget ${BUDGET_KIB["app.js"]})`);
console.log(`app.css ${css} KiB gzip (budget ${BUDGET_KIB["app.css"]})`);
console.log(
    `code editor chunk ${editor} KiB gzip (budget ${BUDGET_KIB.codeEditor})`,
);
console.log(
    `other chunks ${others} KiB gzip (budget ${BUDGET_KIB.otherChunksTotal})`,
);
if (entry > BUDGET_KIB["app.js"]) failures.push("app.js exceeds its budget");
if (css > BUDGET_KIB["app.css"]) failures.push("app.css exceeds its budget");
if (editor > BUDGET_KIB.codeEditor)
    failures.push("the code editor chunk exceeds its budget");
if (others > BUDGET_KIB.otherChunksTotal)
    failures.push("the other chunks exceed their budget");

for (const [file, budget] of Object.entries(FRONTEND_BUDGET_KIB)) {
    const path = join(root, file);
    if (!existsSync(path)) {
        failures.push(`${file} is missing`);
        continue;
    }
    const size = gzipKib(path);
    console.log(`${file} ${size} KiB gzip (budget ${budget})`);
    if (size > budget) failures.push(`${file} exceeds its budget`);
}

const remote =
    /fonts\.(googleapis|gstatic)\.com|use\.typekit|fonts\.bunny|cdn\.jsdelivr|unpkg\.com|cdnjs\./i;
for (const file of files(dist)) {
    if (!/\.(js|css)$/.test(file)) continue;
    const text = readFileSync(file, "utf8");
    if (remote.test(text)) failures.push(`${file} references a remote host`);
    if (file.endsWith(".css") && /@import/i.test(text))
        failures.push(`${file} uses @import`);
}

const stylesheet = readFileSync(join(dist, "app.css"), "utf8");
if (/(^|\})\s*(html|body)\s*[,{]/.test(stylesheet))
    failures.push("app.css contains unscoped html/body rules");

if (failures.length > 0) {
    console.error(failures.map((failure) => `FAIL ${failure}`).join("\n"));
    process.exit(1);
}
console.log("Bundle checks passed.");
