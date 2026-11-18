import { createRequire } from 'module';
import { readFileSync } from 'node:fs';
const { chromium } = createRequire(process.env.PW_DIR + '/x.js')('playwright-core');
const base = 'http://127.0.0.1:8090';
const out = process.argv[2];
const browser = process.env.SWP_BROWSER_CDP
  ? await chromium.connectOverCDP(process.env.SWP_BROWSER_CDP)
  : await chromium.launch({ executablePath: process.env.CHROME });
const results = [];
const check = (name, ok, info = '') => {
  results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${info ? ' – ' + info : ''}`);
};

const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'de-DE' });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => {
  const text = `${m.text()} ${m.location().url}`;
  // The refused publication of the invalid template is expected: the browser logs its 422 response.
  if (m.type() === 'error' && !/favicon|ERR_NETWORK_CHANGED|ERR_ABORTED|status of 422 .*admin\/templates$/.test(text)) errors.push(text);
});

await page.goto(base + '/wp-login.php');
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'e2e-admin-pass');
await Promise.all([
  page.waitForURL((url) => url.pathname.startsWith('/wp-admin/'), { waitUntil: 'domcontentloaded' }),
  page.click('#wp-submit'),
]);
await page.waitForLoadState('networkidle');

await page.goto(base + '/wp-admin/admin.php?page=sw-postlist');
await page.waitForSelector('.swp-nav__item');
const labels = await page.locator('.swp-nav__label').allInnerTexts();
check('admin SPA renders all seven navigation entries', labels.length === 7, labels.join(', '));
check('German labels are used', labels.some((l) => l.includes('Ansichten')));
for (const route of ['views', 'templates', 'presets', 'import-export', 'settings', 'diagnostics', '']) {
  await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/${route}`);
  await page.waitForSelector('.swp-main h1');
  await page.waitForFunction(() => document.querySelectorAll('.swp-main h1').length === 1);
  // The wait above already asserts exactly one heading; the page fades between routes, so do not sample twice.
  check(`route /${route} shows a heading`, true);
}
// M4: template editor with live validation, one-step publication and the view editor with a device preview.
await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/templates/new?role=entry`);
await page.waitForSelector('[data-testid="html-editor"] .cm-content');
check('the CodeMirror editor loads on demand', (await page.locator('.cm-editor').count()) >= 1);
check('the placeholder panel lists typed placeholders', (await page.locator('[data-placeholder="entry.title"]').count()) === 1 && (await page.locator('[data-formatter="truncate"]').count()) === 1);
await page.fill('[data-testid="template-title"]', 'E2E card');
const htmlEditor = page.locator('[data-testid="html-editor"] .cm-content');
await htmlEditor.click();
await page.keyboard.press('Control+A');
// The HTML editor closes tags itself: the closing tag is not typed.
await page.keyboard.type('<p class="e2e">{{entry.nope}}');
await page.waitForFunction(() => document.querySelector('.swp-issues')?.textContent?.includes('Unbekannter Platzhalter'));
check('an unknown placeholder is reported while typing', (await page.locator('.swp-issues').innerText()).includes('Unbekannter Platzhalter'));
await page.click('[data-testid="publish-template"]');
await page.waitForTimeout(800);
check('publishing an invalid template is refused and keeps the page', page.url().includes('templates/new') && (await page.locator('.swp-issues').count()) === 1);
await htmlEditor.click();
await page.keyboard.press('Control+A');
await page.keyboard.type('<p class="e2e">');
await page.keyboard.type('{{entry.title|upper}}');
await page.keyboard.press('Escape');
await page.waitForSelector('[data-testid="template-valid"]', { timeout: 10000 });
await page.click('[data-testid="publish-template"]');
await page.waitForURL(/#\/templates\/\d+/);
check('a valid template is published in one step and opens in its editor', /#\/templates\/\d+/.test(page.url()));
await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/templates`);
await page.waitForSelector('[data-testid="templates-table"]');
check('the template appears in the list', (await page.locator('[data-testid="templates-table"]').innerText()).includes('E2E card'));

await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/views/new`);
await page.waitForSelector('[data-testid="view-title"]');
await page.fill('[data-testid="view-title"]', 'E2E view');
await page.click('[data-testid="tab-template"]');
await page.click('[data-testid="view-entry-template"]');
await page.locator('.q-menu .q-item', { hasText: 'E2E card' }).click();
await page.click('[data-testid="tab-preview"]');
await page.waitForSelector('[data-testid="preview-frame"]');
await page.waitForFunction(() => document.querySelector('[data-testid="preview-frame"]')?.getAttribute('srcdoc')?.includes('e2e'));
const frameDoc = await page.locator('[data-testid="preview-frame"]').getAttribute('srcdoc');
check('the preview renders real public entries with the chosen template', frameDoc.includes('class="e2e"') && /PUBLISHED \d|OTHER \d/.test(frameDoc), (frameDoc ?? '').slice(0, 120));
await page.click('[data-device="mobile"]');
check('the device buttons change the preview width', (await page.locator('.swp-preview__frame').getAttribute('style')).includes('375px'));
await page.click('[data-testid="publish-view"]');
await page.waitForURL(/#\/views\/\d+/);
check('a view is saved and opens in its editor', /#\/views\/\d+/.test(page.url()));
await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/views`);
await page.waitForSelector('[data-testid="views-table"]');
check('the view appears in the list', (await page.locator('[data-testid="views-table"]').innerText()).includes('E2E view'));
// M5: apply a bundled preset, adjust the card in the visual builder and compare builder output with the code tab.
await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/presets`);
await page.waitForSelector('[data-preset="builder-card"]');
check('bundled presets are listed', (await page.locator('[data-preset]').count()) >= 5);
await page.click('[data-preset="builder-card"] [data-action="preview"]');
await page.waitForSelector('[data-testid="preview-frame"]');
await page.click('[data-preset="builder-card"] [data-action="apply"]');
await page.waitForSelector('[data-testid="templates-table"]');
check('applying a preset creates ordinary templates', (await page.locator('[data-testid="templates-table"]').innerText()).includes('Builder card (entry)'));
await page.locator('[data-testid="templates-table"] a', { hasText: 'Builder card (entry)' }).click();
await page.waitForSelector('[data-testid="tab-builder"]');
await page.click('[data-testid="tab-builder"]');
await page.waitForSelector('[data-testid="builder"]');
check('builder generated code opens in the builder without a warning', (await page.locator('[data-testid="builder-notice"]').count()) === 0 && (await page.locator('[role="treeitem"]').count()) >= 8);
await page.click('[data-path="1.3"] .swp-builder__label');
await page.fill('[data-field="words"]', '12');
await page.click('.q-tab:has-text("HTML")');
const generatedCode = await page.locator('[data-testid="html-editor"] .cm-content').innerText();
check('the builder change shows up in the code tab', generatedCode.includes('words:12') && generatedCode.includes('data-swp-builder'));
await page.click('[data-testid="publish-template"]');
await page.waitForTimeout(800);
await page.reload();
await page.waitForSelector('[data-testid="tab-builder"]');
await page.click('[data-testid="tab-builder"]');
await page.waitForSelector('[data-testid="builder"]');
await page.click('[data-path="1.3"] .swp-builder__label');
check('the published change survives a reload and the builder is still in sync', (await page.locator('[data-field="words"]').inputValue()) === '12' && (await page.locator('[data-testid="builder-notice"]').count()) === 0);
const badIcons = await page.evaluate(() => [...document.querySelectorAll('.q-icon, .q-icon > span')].filter((el) => !/Material Icons Round/i.test(getComputedStyle(el).fontFamily)).map((el) => `${el.className} | ${getComputedStyle(el).fontFamily} | ${el.parentElement?.className}`));
check('every icon in the builder uses the icon font', badIcons.length === 0, badIcons.slice(0, 3).join(' || '));
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(500);
await page.locator('.swp-builder__tree').screenshot({ path: `${out}/builder-tree.png` });
await page.screenshot({ path: `${out}/admin-builder.png`, fullPage: true });

await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/diagnostics`);
await page.waitForSelector('h1');
await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/settings`);
await page.waitForSelector('[data-testid="save-settings"]');
await page.screenshot({ path: `${out}/admin-editors.png`, fullPage: true });
await page.screenshot({ path: `${out}/admin-shell.png`, fullPage: true });
await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/`);
await page.waitForSelector('[data-testid="stat-views"]');
await page.screenshot({ path: `${out}/admin-dashboard.png` });
await page.click('.swp-rail__tools button');
await page.waitForTimeout(300);
await page.evaluate(() => window.scrollTo(0, 0));
await page.screenshot({ path: `${out}/admin-dashboard-dark.png` });
await page.click('.swp-rail__tools button');
for (const route of ['views', 'templates', 'presets', 'import-export', 'diagnostics']) {
  await page.goto(`${base}/wp-admin/admin.php?page=sw-postlist#/${route}`);
  await page.waitForSelector('.swp-main h1');
  await page.waitForTimeout(400);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: `${out}/admin-page-${route}.png` });
  if (route === 'presets') {
    await page.click('[data-action="preview"]');
    await page.waitForSelector('[data-testid="preview-frame"]');
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${out}/admin-page-presets-preview.png`, fullPage: true });
  }
}
check('wp-admin chrome stays visible (style isolation)', await page.locator('#adminmenu').isVisible());
check('no console or page errors', errors.length === 0, errors.join(' | '));

// M3 acceptance: two identical views on one page page independently, without JavaScript, with direct links.
const seed = JSON.parse(readFileSync(process.env.SWP_E2E_SEED, 'utf8'));
const plain = await browser.newContext({ viewport: { width: 1280, height: 900 }, javaScriptEnabled: false });
const lists = await plain.newPage();
const titles = async (index) => lists.locator('.swp-view').nth(index).locator('.swp-card__title').allInnerTexts();
await lists.goto(base + '/two-lists/');
check('both lists render', (await lists.locator('.swp-view').count()) === 2);
check('first page of each list shows entries 2 and 3 (start offset 1)', JSON.stringify(await titles(0)) === JSON.stringify(['Published 2', 'Published 3']) && JSON.stringify(await titles(1)) === JSON.stringify(['Published 2', 'Published 3']));
const keys = await lists.locator('.swp-view').evaluateAll((els) => els.map((el) => el.dataset.swpInstance));
check('instance keys are stable and distinct', JSON.stringify(keys) === JSON.stringify([`view-${seed.view_id}-1`, `view-${seed.view_id}-2`]), keys.join(','));
check('no draft, private or protected entries', !(await lists.content()).match(/Draft entry|Private entry|Protected entry/));
check('no JavaScript is needed or shipped for the lists', (await lists.locator('script[src*="sw-postlist"]').count()) === 0);
await lists.locator('.swp-view').nth(1).locator('a.swp-pagination__link--next').click();
await lists.waitForLoadState('domcontentloaded');
const secondUrl = lists.url();
check('next link of the second list targets only its own state', secondUrl.includes(encodeURIComponent(`view-${seed.view_id}-2`) ) || decodeURIComponent(secondUrl).includes(`swp[view-${seed.view_id}-2][page]=2`), secondUrl);
check('second list moved to entries 4 and 5, first stayed', JSON.stringify(await titles(1)) === JSON.stringify(['Published 4', 'Published 5']) && JSON.stringify(await titles(0)) === JSON.stringify(['Published 2', 'Published 3']));
await lists.locator('.swp-view').nth(0).locator('a.swp-pagination__link--next').click();
await lists.waitForLoadState('domcontentloaded');
check('moving the first list keeps the state of the second', JSON.stringify(await titles(0)) === JSON.stringify(['Published 4', 'Published 5']) && JSON.stringify(await titles(1)) === JSON.stringify(['Published 4', 'Published 5']));
const bothMoved = lists.url();
await lists.goBack();
check('browser back restores the earlier state', JSON.stringify(await titles(0)) === JSON.stringify(['Published 2', 'Published 3']) && JSON.stringify(await titles(1)) === JSON.stringify(['Published 4', 'Published 5']));
await lists.goto(bothMoved);
check('the direct link loads both states', JSON.stringify(await titles(0)) === JSON.stringify(['Published 4', 'Published 5']));
await lists.goto(base + `/two-lists/?swp[view-${seed.view_id}-1][page]=3`);
check('last page of the first list shows one entry', JSON.stringify(await titles(0)) === JSON.stringify(['Published 6']));
const gridColumns = await lists.locator('.swp-grid').first().evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
check('grid uses three desktop columns', gridColumns === 3, String(gridColumns));
await lists.screenshot({ path: `${out}/two-lists.png`, fullPage: true });
await plain.close();

const phone = await browser.newContext({ viewport: { width: 400, height: 800 } });
const small = await phone.newPage();
await small.goto(base + '/two-lists/');
const phoneColumns = await small.locator('.swp-grid').first().evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
check('grid collapses to one column on a phone', phoneColumns === 1, String(phoneColumns));
await small.screenshot({ path: `${out}/two-lists-mobile.png`, fullPage: true });
await phone.close();

// M6: layouts on the intended device widths, keyboard, reduced motion and on-demand modules.
const devices = { desktop: 1280, tablet: 800, mobile: 400 };
const loadedModules = (page) => page.evaluate(() => [...document.scripts].map((script) => script.src).filter((src) => src.includes('/sw-postlist/assets/js/')).map((src) => src.split('/').pop().split('?')[0]));
for (const [device, width] of Object.entries(devices)) {
  const context = await browser.newContext({ viewport: { width, height: 900 } });
  const layoutPage = await context.newPage();
  layoutPage.on('pageerror', (e) => errors.push(String(e)));
  await layoutPage.goto(base + '/layout-masonry/');
  await layoutPage.waitForSelector('.swp-masonry.is-ready');
  await layoutPage.waitForFunction(() => document.querySelector('.swp-masonry .swp-item')?.style.gridRowEnd);
  const columns = await layoutPage.locator('.swp-masonry').evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
  const overlap = await layoutPage.locator('.swp-masonry').evaluate((el) => {
    const rects = [...el.children].map((item) => item.getBoundingClientRect());
    return rects.some((a, i) => rects.some((b, j) => i < j && a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1));
  });
  check(`masonry on ${device} (${width}px) uses ${{ desktop: 3, tablet: 2, mobile: 1 }[device]} column(s) without overlap`, columns === { desktop: 3, tablet: 2, mobile: 1 }[device] && !overlap, `${columns} columns, overlap ${overlap}`);
  check(`masonry page on ${device} loads only the masonry module`, JSON.stringify(await loadedModules(layoutPage)) === JSON.stringify(['masonry.js']));
  if (device === 'desktop') await layoutPage.screenshot({ path: `${out}/layout-masonry.png`, fullPage: true });

  await layoutPage.goto(base + '/layout-slider/');
  await layoutPage.waitForSelector('.swp-slider__controls');
  const visible = await layoutPage.locator('.swp-slider__track').evaluate((track) => Math.round(track.clientWidth / track.children[0].getBoundingClientRect().width));
  check(`slider on ${device} shows ${{ desktop: 3, tablet: 2, mobile: 1 }[device]} slide(s)`, visible === { desktop: 3, tablet: 2, mobile: 1 }[device], String(visible));
  check(`slider page on ${device} loads only the slider module`, JSON.stringify(await loadedModules(layoutPage)) === JSON.stringify(['slider.js']));
  if (device === 'mobile') {
    await layoutPage.click('.swp-slider__next');
    await layoutPage.waitForFunction(() => document.querySelector('.swp-slider__track').scrollLeft > 50);
    check('the next button moves the slider and updates the dots', (await layoutPage.locator('.swp-slider__dot[aria-current="true"]').count()) === 1 && (await layoutPage.locator('.swp-slider__prev').isDisabled()) === false);
    await layoutPage.focus('.swp-slider__track');
    await layoutPage.keyboard.press('Home');
    await layoutPage.waitForFunction(() => document.querySelector('.swp-slider__track').scrollLeft < 5);
    check('the keyboard (Home) returns to the first slide', (await layoutPage.locator('.swp-slider__prev').isDisabled()) === true);
    await layoutPage.screenshot({ path: `${out}/layout-slider-mobile.png`, fullPage: true });
  }
  if (device === 'desktop') {
    await layoutPage.screenshot({ path: `${out}/layout-slider.png`, fullPage: true });
    await layoutPage.goto(base + '/layout-hover/');
    check('hover grid page loads no module', JSON.stringify(await loadedModules(layoutPage)) === '[]');
    const more = layoutPage.locator('.swp-hover__more').first();
    // The headless browser may report no hover capability: then the touch fallback applies (details always visible).
    const canHover = await layoutPage.evaluate(() => matchMedia('(hover: hover)').matches);
    check(canHover ? 'hover details are hidden at first' : 'without hover capability the details are always visible (touch fallback)', Number(await more.evaluate((el) => getComputedStyle(el).opacity)) === (canHover ? 0 : 1), `hover capable: ${canHover}`);
    await layoutPage.hover('.swp-hover');
    await layoutPage.waitForTimeout(400);
    check('hover shows the details', Number(await more.evaluate((el) => getComputedStyle(el).opacity)) === 1);
    await layoutPage.mouse.move(0, 0);
    await layoutPage.focus('.swp-hover__button');
    await layoutPage.waitForTimeout(400);
    check('keyboard focus reveals the details', Number(await more.evaluate((el) => getComputedStyle(el).opacity)) === 1);
    await layoutPage.screenshot({ path: `${out}/layout-hover.png`, fullPage: true });
    await layoutPage.goto(base + '/two-lists/');
    check('a page with grid views loads no Postlist script', JSON.stringify(await loadedModules(layoutPage)) === '[]');
  }
  await context.close();
}
const motion = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
const calm = await motion.newPage();
await calm.goto(base + '/layout-autoplay/');
await calm.waitForSelector('.swp-slider__controls');
check('reduced motion: autoplay is off and offers no pause button', (await calm.locator('.swp-slider__pause').count()) === 0);
await calm.waitForTimeout(2600);
check('reduced motion: the slider does not move by itself', (await calm.locator('.swp-slider__track').evaluate((el) => el.scrollLeft)) === 0);
await motion.close();
const lively = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'no-preference' });
const autoplayPage = await lively.newPage();
await autoplayPage.goto(base + '/layout-autoplay/');
await autoplayPage.waitForSelector('.swp-slider__pause');
await autoplayPage.mouse.move(0, 0);
await autoplayPage.waitForFunction(() => document.querySelector('.swp-slider__track').scrollLeft > 50, null, { timeout: 6000 });
check('autoplay advances the slider', true);
await autoplayPage.click('.swp-slider__pause');
await autoplayPage.waitForTimeout(400);
const resting = await autoplayPage.locator('.swp-slider__track').evaluate((el) => el.scrollLeft);
await autoplayPage.waitForTimeout(2600);
check('the pause button stops autoplay', (await autoplayPage.locator('.swp-slider__track').evaluate((el) => el.scrollLeft)) === resting && (await autoplayPage.locator('.swp-slider__pause').getAttribute('aria-pressed')) === 'true');
await lively.close();

// M7: visitor filters and dynamic pagination, with and without JavaScript.
const rowTitles = (page, index = 0) => page.locator('.swp-view').nth(index).locator('.swp-row__title').allInnerTexts();
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const noScript = await browser.newContext({ viewport: { width: 1280, height: 900 }, javaScriptEnabled: false });
const plainPage = await noScript.newPage();
await plainPage.goto(base + '/dyn-classic/');
check('without JavaScript all ten entries are paged three at a time', same(await rowTitles(plainPage), ['Published 1', 'Published 2', 'Published 3']));
await plainPage.click('.swp-filter__button:has-text("Other")');
await plainPage.waitForLoadState('domcontentloaded');
check('without JavaScript a filter button filters through a normal form submit', same(await rowTitles(plainPage), ['Other 1', 'Other 2', 'Other 3']) && plainPage.url().includes('tax_category'));
await plainPage.click('.swp-pagination__link--next');
await plainPage.waitForLoadState('domcontentloaded');
check('without JavaScript page two keeps the filter', same(await rowTitles(plainPage), ['Other 4']) && plainPage.url().includes('tax_category') && plainPage.url().includes('page'));
await plainPage.fill('input[type="search"]', 'Other 2');
await plainPage.click('.swp-filters__apply');
await plainPage.waitForLoadState('domcontentloaded');
check('without JavaScript search and the category filter combine and the page resets', same(await rowTitles(plainPage), ['Other 2']) && !plainPage.url().includes('%5Bpage%5D'));
await noScript.close();

const dyn = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const dynPage = await dyn.newPage();
dynPage.on('pageerror', (e) => errors.push(String(e)));
await dynPage.goto(base + '/dyn-ajax/');
await dynPage.waitForSelector('.swp-filters--live');
await dynPage.evaluate(() => { window.__still_here = true; });
check('the dynamic page loads the dynamic module only', JSON.stringify(await loadedModules(dynPage)) === JSON.stringify(['dynamic.js']));
await dynPage.click('.swp-filter__button:has-text("Other")');
await dynPage.waitForFunction(() => document.querySelector('.swp-row__title')?.textContent === 'Other 1');
check('a filter reloads the list without a page navigation and updates the url', (await dynPage.evaluate(() => window.__still_here === true)) && same(await rowTitles(dynPage), ['Other 1', 'Other 2', 'Other 3']) && dynPage.url().includes('tax_category'));
await dynPage.waitForFunction(() => (document.querySelector('.swp-status')?.textContent ?? '').length > 0);
check('the live region announces the result', (await dynPage.locator('.swp-status').textContent()).length > 0, await dynPage.locator('.swp-status').textContent());
check('the selected filter button is marked', (await dynPage.locator('.swp-filter__button.is-active').innerText()) === 'Other' && (await dynPage.locator('.swp-filter__button.is-active').getAttribute('aria-pressed')) === 'true');
await dynPage.click('.swp-pagination__link--next');
await dynPage.waitForFunction(() => document.querySelector('.swp-row__title')?.textContent === 'Other 4');
check('AJAX pagination keeps the filter, moves focus to the list and stays on the page', (await dynPage.evaluate(() => window.__still_here === true && document.activeElement?.classList.contains('swp-view'))) && dynPage.url().includes('page'));
await dynPage.goBack();
await dynPage.waitForFunction(() => document.querySelector('.swp-row__title')?.textContent === 'Other 1');
check('browser back restores the earlier state from the url', same(await rowTitles(dynPage), ['Other 1', 'Other 2', 'Other 3']) && (await dynPage.evaluate(() => window.__still_here === true)));
await dynPage.goBack();
await dynPage.waitForFunction(() => document.querySelector('.swp-row__title')?.textContent === 'Published 1');
check('back again shows the unfiltered first page', same(await rowTitles(dynPage), ['Published 1', 'Published 2', 'Published 3']) && (await dynPage.locator('.swp-filter__button.is-active').innerText()) === 'All');
await dynPage.goForward();
await dynPage.waitForFunction(() => document.querySelector('.swp-row__title')?.textContent === 'Other 1');
const directLink = dynPage.url();
await dynPage.goto(directLink);
check('the direct link loads the filtered state', same(await rowTitles(dynPage), ['Other 1', 'Other 2', 'Other 3']));
await dynPage.fill('input[type="search"]', 'Other 3');
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-row__title').length === 1);
check('typing in the search field filters after a short pause and keeps focus', same(await rowTitles(dynPage), ['Other 3']) && (await dynPage.evaluate(() => document.activeElement?.type === 'search')));
await dynPage.screenshot({ path: `${out}/dyn-ajax.png`, fullPage: true });

await dynPage.route('**/wp-json/sw-postlist/v1/views/*/render*', (route) => route.abort());
await dynPage.fill('input[type="search"]', '');
await dynPage.waitForSelector('.swp-notice');
check('a failing reload shows an alert with a retry and keeps the old list', (await dynPage.locator('.swp-notice[role="alert"]').count()) === 1 && same(await rowTitles(dynPage), ['Other 3']));
await dynPage.unroute('**/wp-json/sw-postlist/v1/views/*/render*');
await dynPage.click('.swp-retry');
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-row__title').length === 3);
check('retry recovers', (await dynPage.locator('.swp-notice').count()) === 0);

await dynPage.goto(base + '/dyn-two/');
await dynPage.waitForSelector('.swp-filters--live');
await dynPage.locator('.swp-view').nth(1).locator('.swp-filter__button:has-text("Other")').click();
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-view')[1].querySelector('.swp-row__title')?.textContent === 'Other 1');
check('filtering the second instance leaves the first untouched', same(await rowTitles(dynPage, 0), ['Published 1', 'Published 2', 'Published 3']) && same(await rowTitles(dynPage, 1), ['Other 1', 'Other 2', 'Other 3']));
await dynPage.locator('.swp-view').nth(0).locator('.swp-pagination__link--next').click();
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-view')[0].querySelector('.swp-row__title')?.textContent === 'Published 4');
check('paging the first instance keeps the filter of the second', same(await rowTitles(dynPage, 0), ['Published 4', 'Published 5', 'Published 6']) && same(await rowTitles(dynPage, 1), ['Other 1', 'Other 2', 'Other 3']));
const twoUrl = dynPage.url();
await dynPage.goto(twoUrl);
check('the direct link of two instances restores both states', same(await rowTitles(dynPage, 0), ['Published 4', 'Published 5', 'Published 6']) && same(await rowTitles(dynPage, 1), ['Other 1', 'Other 2', 'Other 3']));
await dynPage.goBack();
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-view')[0].querySelector('.swp-row__title')?.textContent !== undefined);
await dynPage.screenshot({ path: `${out}/dyn-two.png`, fullPage: true });

await dynPage.goto(base + '/dyn-more/');
await dynPage.waitForSelector('.swp-load-more');
check('load more hides the page links and offers a button', (await dynPage.locator('.swp-pagination').evaluate((el) => el.hidden)) && (await dynPage.locator('.swp-load-more').count()) === 1);
await dynPage.click('.swp-load-more');
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-row__title').length === 6);
await dynPage.click('.swp-load-more');
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-row__title').length === 9);
await dynPage.click('.swp-load-more');
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-row__title').length === 10);
check('load more appends every page and the button disappears at the end', (await dynPage.locator('.swp-load-more').count()) === 0 && (await rowTitles(dynPage)).at(-1) === 'Other 4' && !dynPage.url().includes('page'));
await dynPage.click('.swp-filter__button:has-text("Other")');
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-row__title').length === 3);
check('changing a filter after loading more starts again on the first page', same(await rowTitles(dynPage), ['Other 1', 'Other 2', 'Other 3']) && (await dynPage.locator('.swp-load-more').count()) === 1);

await dynPage.goto(base + '/dyn-infinite/');
await dynPage.waitForSelector('.swp-load-more');
await dynPage.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
await dynPage.waitForFunction(() => document.querySelectorAll('.swp-row__title').length >= 6, null, { timeout: 8000 });
check('infinite scroll loads more entries when the end comes into view', (await dynPage.locator('.swp-row__title').count()) >= 6);
await dyn.close();

// Presets: every bundled preset on a page with images, desktop and phone.
const presetShots = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const presetPage = await presetShots.newPage();
const phoneShots = await browser.newContext({ viewport: { width: 400, height: 800 } });
const phonePage = await phoneShots.newPage();
const imagePresets = ['builder-card', 'card-grid', 'dark-overlay', 'editorial-hero', 'magazine-cards', 'neo-brutalist', 'news-teaser', 'teaser-pills'];
for (const id of seed.presets) {
  await presetPage.goto(`${base}/preset-${id}/`);
  await presetPage.waitForSelector('.swp-view .swp-item');
  await presetPage.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
  await presetPage.waitForTimeout(300);
  const overflow = await presetPage.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  check(`preset ${id} renders without horizontal overflow on desktop`, !overflow);
  check(`preset ${id} shows four entries${imagePresets.includes(id) ? ' with images' : ''}`, (await presetPage.locator('.swp-item').count()) === 4 && (!imagePresets.includes(id) || (await presetPage.locator('.swp-item img').count()) >= 4));
  check(`preset ${id} has no list item outside a list`, await presetPage.evaluate(() => [...document.querySelectorAll('.swp-view li')].every((li) => li.closest('ul, ol'))));
  await presetPage.locator('.swp-view').first().screenshot({ path: `${out}/preset-${id}.png` });
  await phonePage.goto(`${base}/preset-${id}/`);
  await phonePage.waitForSelector('.swp-view .swp-item');
  await phonePage.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
  await phonePage.waitForTimeout(300);
  check(`preset ${id} renders without horizontal overflow on a phone`, !(await phonePage.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)));
  await phonePage.locator('.swp-view').first().screenshot({ path: `${out}/preset-${id}-mobile.png` });
}
await presetShots.close();
await phoneShots.close();

// M9 accessibility: automated axe-core scan of the delivered markup (the theme around it is out of scope). The heading\n// level of entries is a template decision that depends on the embedding page, so heading-order is not asserted.
const axeSource = readFileSync(process.env.PW_DIR + '/axe-core/axe.min.js', 'utf8');
const axeContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const axePage = await axeContext.newPage();
for (const slug of ['two-lists', 'layout-masonry', 'layout-hover', 'layout-slider', 'layout-autoplay', 'dyn-classic', 'dyn-ajax', 'dyn-more', 'dyn-two']) {
  await axePage.goto(`${base}/${slug}/`);
  await axePage.waitForSelector('.swp-view');
  await axePage.evaluate(axeSource);
  const found = await axePage.evaluate(async () => {
    const result = await window.axe.run({ include: [['.swp-view']] }, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] }, rules: { 'heading-order': { enabled: false } } });
    return result.violations.map((violation) => `${violation.id} (${violation.nodes.length}): ${violation.nodes[0].html.slice(0, 80)}`);
  });
  check(`axe finds no accessibility violation in the views of /${slug}/`, found.length === 0, found.join(' | '));
}
await axeContext.close();

await context.close();
await browser.close();
console.log(results.join('\n'));
