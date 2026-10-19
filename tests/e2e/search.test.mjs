// E2E checks for issue #8, J1 (C1/C2/C3): a homemade (vanilla JS) search box with a
// suggestions list over graph.html opened as file://. Drives the real embedded JS
// with Playwright/Chromium.
//
// Prereq: the ego graph.html must be generated first (see run below). We rely on
// `make run` so the HTML is produced by the exact binary, not a hand-made copy.

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const REPO = resolve(import.meta.dirname, '..', '..');
const IMAGE = process.env.IMAGE || 'class-viewer-dev:issue-8';

function generateEgoHtml() {
    const rel = 'tests/e2e/.ego-search.html';
    const out = resolve(REPO, rel);
    execFileSync('make', ['run', `IMAGE=${IMAGE}`,
        `ARGS=tests/fixtures/graph/ego --output ${rel}`],
        { cwd: REPO, stdio: 'pipe' });
    return { out, url: pathToFileURL(out).href };
}

// FQCN texts of the current suggestion items, sorted.
function suggestions(page) {
    return page.$$eval('#suggestions li', (ls) => ls.map((l) => l.textContent).sort());
}
// Text of the detailed focus node (FQCN + namespace).
function focusText(page) {
    return page.$eval('g.node circle[r="10"]', (c) => c.parentElement.querySelector('text').textContent);
}
// Short names of the neighbour (non-focus) nodes, sorted.
function neighbourNames(page) {
    return page.$$eval('g.node circle[r="6"]', (cs) =>
        cs.map((c) => c.parentElement.querySelector('text').textContent).sort());
}

// Type a fresh query into the search box (clearing any previous input).
async function type(page, value) {
    await page.fill('#search', '');
    await page.fill('#search', value);
}

async function run() {
    const { out, url } = generateEgoHtml();
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        await page.goto(url);
        await page.waitForSelector('g.node');
        await page.waitForSelector('#search');

        // Baseline: initial focus is App\Focus.
        assert.equal(await focusText(page), 'App\\Focus (App)', 'initial focus must be App\\Focus');

        // --- C1.1: typing `dep` suggests App\Dep (short-name match). ---
        await type(page, 'dep');
        assert.deepEqual(await suggestions(page), ['App\\Dep'], 'C1.1: `dep` suggests App\\Dep');

        // --- C1.2: typing `app\fo` suggests App\Focus (FQCN match). ---
        await type(page, 'app\\fo');
        assert.deepEqual(await suggestions(page), ['App\\Focus'], 'C1.2: `app\\fo` suggests App\\Focus');

        // --- C1.3: typing `o` suggests App\Consumer and App\Focus, not App\Dep. ---
        await type(page, 'o');
        assert.deepEqual(await suggestions(page), ['App\\Consumer', 'App\\Focus'],
            'C1.3: `o` suggests Consumer and Focus, not Dep');

        // --- C1.4: typing `zzz` yields an empty list, no error. ---
        await type(page, 'zzz');
        assert.deepEqual(await suggestions(page), [], 'C1.4: `zzz` yields no suggestion');

        // --- C3.1: typing `DEP` (uppercase) suggests App\Dep (case-insensitive). ---
        await type(page, 'DEP');
        assert.deepEqual(await suggestions(page), ['App\\Dep'], 'C3.1: `DEP` suggests App\\Dep too');

        // --- C2.3: a marker that a page reload would wipe out. ---
        await page.evaluate(() => { window.__alive = 1; });

        // --- C2.1: selecting App\Dep re-centres the graph on it. ---
        await type(page, 'dep');
        await page.click('#suggestions li[data-id="App\\\\Dep"]');
        await page.waitForFunction(() =>
            document.querySelector('g.node circle[r="10"]')
                ?.parentElement.querySelector('text').textContent === 'App\\Dep (App)');
        assert.equal(await focusText(page), 'App\\Dep (App)', 'C2.1: App\\Dep becomes detailed focus');
        assert.deepEqual(await neighbourNames(page), ['Focus'], 'C2.1: App\\Dep shows neighbour Focus');

        // --- C2.3: no navigation happened. ---
        assert.equal(await page.evaluate(() => window.__alive), 1,
            'C2.3: no reload (window.__alive kept)');

        // --- C2.2: re-centre on App\Focus, then selecting it again is a strict no-op. ---
        await type(page, 'focus');
        await page.click('#suggestions li[data-id="App\\\\Focus"]');
        await page.waitForFunction(() =>
            document.querySelector('g.node circle[r="10"]')
                ?.parentElement.querySelector('text').textContent === 'App\\Focus (App)');
        const before = await page.$eval('#app', (el) => el.innerHTML);
        await type(page, 'focus');
        await page.click('#suggestions li[data-id="App\\\\Focus"]');
        const after = await page.$eval('#app', (el) => el.innerHTML);
        assert.equal(after, before, 'C2.2: selecting the current focus changes nothing');

        assert.deepEqual(errors, [], 'no JS error anywhere during the run');

        console.log('OK: C1.1 C1.2 C1.3 C1.4 C2.1 C2.2 C2.3 C3.1');
    } finally {
        await browser.close();
        rmSync(out, { force: true });
    }
}

run().catch((e) => { console.error(e); process.exit(1); });
