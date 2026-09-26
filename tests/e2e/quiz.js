/**
 * Browser check for the quiz player.
 *   BGQ_E2E_URL=https://your-site/quiz-page/ node tests/e2e/quiz.js
 * Expects the fixture quiz from tests/fixtures/sample-score.json on that page.
 */
const { chromium } = require('playwright');
const URL = process.env.BGQ_E2E_URL || 'https://test.chimkins.com/quiz-e2e/';
(async () => {
  const browser = await chromium.launch();
  const out = [];
  const log = (ok, msg) => out.push((ok ? '  ok   - ' : '  FAIL - ') + msg);
  for (const [name, vp] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 375, height: 800 }]]) {
    const ctx = await browser.newContext({ viewport: vp, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(URL, { waitUntil: 'networkidle' });
    const root = page.locator('.bgq');
    log(await root.count() === 1, `${name}: quiz container present`);
    log(await page.locator('.bgq-start .bgq-btn--primary').count() === 1, `${name}: start screen`);
    const leak = await page.evaluate(() => JSON.stringify(window.bgq_data_411 || {}));
    log(!leak.includes('"correct"') && !leak.includes('"points"'), `${name}: no grading data in page`);

    // Q1 via keyboard only: Tab to first answer, Enter selects, Tab to Next, Enter.
    await page.locator('.bgq-start .bgq-btn--primary').click();
    await page.waitForSelector('.bgq-question');
    log((await page.locator('.bgq-count').textContent()).includes('1'), `${name}: progress shows question 1`);
    log(await page.locator('.bgq-timer').count() === 1, `${name}: timer shown`);
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => document.activeElement.className);
    log(focused.includes('bgq-answer'), `${name}: first Tab lands on an answer (${focused})`);
    await page.keyboard.press('Enter');
    log(await page.locator('.bgq-answer.is-selected').count() === 1, `${name}: Enter selects answer`);
    await page.keyboard.press('Tab'); await page.keyboard.press('Tab'); await page.keyboard.press('Tab');
    const nextFocused = await page.evaluate(() => document.activeElement.textContent.trim());
    await page.keyboard.press('Enter');
    await page.waitForTimeout(150);
    log((await page.locator('.bgq-count').textContent()).includes('2'), `${name}: keyboard Next reached question 2 (focused "${nextFocused}")`);

    // Q2 multiple: pick Sandalwood + Amber.
    await page.locator('.bgq-answer', { hasText: 'Sandalwood' }).click();
    await page.locator('.bgq-answer', { hasText: 'Amber' }).click();
    log(await page.locator('.bgq-answer.is-selected').count() === 2, `${name}: multiple choice keeps two selections`);
    await page.locator('.bgq-nav .bgq-btn--primary').click();
    // Q3: Next without answer shows error.
    await page.locator('.bgq-nav .bgq-btn--primary').click();
    log(await page.locator('.bgq-error:not([hidden])').count() === 1, `${name}: submitting without an answer shows a message`);
    await page.locator('.bgq-answer', { hasText: '4 to 6 hours' }).click();
    await page.locator('.bgq-nav .bgq-btn--primary').click();
    await page.waitForSelector('.bgq-result', { timeout: 15000 });
    const title = (await page.locator('.bgq-result__title').textContent()).trim();
    const score = (await page.locator('.bgq-score').textContent()).trim();
    log(title === 'You are a nose!', `${name}: correct result shown (${title})`);
    log(/100/.test(score), `${name}: score 100% (${score})`);
    log(await page.locator('.bgq-product .bgq-btn--primary').count() === 1, `${name}: product result has Add to cart`);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    log(!overflow, `${name}: no horizontal overflow`);
    await page.locator('.bgq').screenshot({ path: `shot-quiz-${name}.png` });
    log(errors.length === 0, `${name}: no JS errors` + (errors.length ? ' -> ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(out.join('\n'));
  process.exit(out.some(l => l.includes('FAIL')) ? 1 : 0);
})().catch(e => { console.error('ERROR', e); process.exit(1); });
