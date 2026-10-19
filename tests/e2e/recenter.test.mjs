// E2E checks for issue #7, J1 (C1/C2/C3): client-side re-centring of graph.html
// opened as file://. Drives the real embedded JS with Playwright/Chromium.
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
const IMAGE = process.env.IMAGE || 'class-viewer-dev:issue-7';

function generateEgoHtml() {
    // `make run` writes inside the Docker-mounted CURDIR, so the output path must
    // live under the repo (not the host /tmp, which is the container's /tmp).
    const rel = 'tests/e2e/.ego.html';
    const out = resolve(REPO, rel);
    execFileSync('make', ['run', `IMAGE=${IMAGE}`,
        `ARGS=tests/fixtures/graph/ego --output ${rel}`],
        { cwd: REPO, stdio: 'pipe' });
    return { out, url: pathToFileURL(out).href };
}

// Text of the detailed focus node (FQCN + namespace) for a given short name.
function focusText(page) {
    return page.$eval('g.node circle[r="10"]', (c) => c.parentElement.querySelector('text').textContent);
}
// Short names of the neighbour (non-focus) nodes.
function neighbourNames(page) {
    return page.$$eval('g.node circle[r="6"]', (cs) =>
        cs.map((c) => c.parentElement.querySelector('text').textContent).sort());
}
// [from,to] ids of each rendered edge, resolved from endpoints vs the focus centre.
function edgeSenses(page) {
    return page.$$eval('line.edge', (ls) => ls.map((l) => l.classList.contains('out') ? 'out' : 'in'));
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

        // Baseline: initial focus is App\Focus with two neighbours.
        assert.equal(await focusText(page), 'App\\Focus (App)', 'initial focus must be App\\Focus');

        // --- C2.1: a marker that a page reload would wipe out. ---
        await page.evaluate(() => { window.__alive = 1; });

        // --- C1.1 + C3.1: click neighbour App\Dep -> it becomes the detailed focus. ---
        const start = await page.evaluate(() => performance.now());
        await page.click('g.node[data-id="App\\\\Dep"]');
        await page.waitForFunction(() =>
            document.querySelector('g.node circle[r="10"]')
                ?.parentElement.querySelector('text').textContent === 'App\\Dep (App)');
        const elapsed = await page.evaluate((s) => performance.now() - s, start);

        assert.equal(await focusText(page), 'App\\Dep (App)', 'C1.1: App\\Dep must become focus');
        assert.deepEqual(await neighbourNames(page), ['Focus'], 'C1.1: App\\Dep has one neighbour, Focus');
        assert.deepEqual(await edgeSenses(page), ['in'], 'C1.1: Focus->Dep is incoming for Dep');
        assert.ok(elapsed < 1000, `C3.1: re-centring must be < 1000 ms, was ${elapsed.toFixed(1)} ms`);

        // --- C2.1: no navigation happened. ---
        assert.equal(await page.evaluate(() => window.__alive), 1, 'C2.1: no reload (window.__alive kept)');

        // --- C1.2: chained re-centring, click App\Focus back. ---
        await page.click('g.node[data-id="App\\\\Focus"]');
        await page.waitForFunction(() =>
            document.querySelector('g.node circle[r="10"]')
                ?.parentElement.querySelector('text').textContent === 'App\\Focus (App)');
        assert.equal(await focusText(page), 'App\\Focus (App)', 'C1.2: App\\Focus is focus again');
        assert.deepEqual(await neighbourNames(page), ['Consumer', 'Dep'],
            'C1.2: App\\Focus shows both neighbours');

        // --- C1.3: clicking the current focus is a strict no-op. ---
        const before = await page.content();
        await page.click('g.node[data-id="App\\\\Focus"]');
        const after = await page.content();
        assert.equal(after, before, 'C1.3: clicking the current focus changes nothing');
        assert.deepEqual(errors, [], 'C1.3: no JS error anywhere during the run');

        console.log('OK: C1.1 C1.2 C1.3 C2.1 C3.1');
    } finally {
        await browser.close();
        rmSync(out, { force: true });
    }
}

run().catch((e) => { console.error(e); process.exit(1); });
