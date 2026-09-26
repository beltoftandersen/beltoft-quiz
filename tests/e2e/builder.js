/**
 * Browser check for the quiz builder. Needs COOKIE=<logged_in cookie>;;<secure_auth cookie> for an admin
 * and BGQ_E2E_ADMIN=https://your-site/wp-admin/.
 */
const { chromium } = require('playwright');
const ADMIN = process.env.BGQ_E2E_ADMIN || 'https://test.chimkins.com/wp-admin/';
const HOST = new URL(ADMIN).hostname;
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
  await ctx.addCookies(process.env.COOKIE.split(';;').map(c => { const i = c.indexOf('='); return { name: c.slice(0, i), value: c.slice(i + 1), domain: HOST, path: '/', secure: true, httpOnly: true }; }));
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  page.on('response', async r => { if (r.url().includes('/bgq/v1/quizzes/')) { let b = ''; try { b = (await r.text()).slice(0, 300); } catch (e) {} console.log('  REST ' + r.request().method() + ' ' + r.status() + ' ' + b); } });
  const out = []; const log = (ok, msg) => { const line = (ok ? '  ok   - ' : '  FAIL - ') + msg; out.push(line); console.log(line); };

  await page.goto(ADMIN + 'post-new.php?post_type=bgq_quiz', { waitUntil: 'networkidle' });
  await page.waitForSelector('#bgq-builder .bgq-header', { timeout: 30000 });
  log(await page.locator('#bgq-builder .bgq-header').count() === 1, 'builder replaces the editor');
  log(await page.locator('#wpfooter').count() === 1, 'single admin footer');
  await page.fill('.bgq-title-input', 'E2E built quiz');
  // Two questions with answers.
  for (let i = 0; i < 2; i++) { await page.locator('.bgq-section[data-section=questions]').locator('button', { hasText: 'Add question' }).click(); }
  const items = page.locator('.bgq-section[data-section=questions]').locator('.bgq-item');
  log(await items.count() === 2, 'two questions added');
  for (let i = 0; i < 2; i++) {
    const item = items.nth(i);
    await item.locator('.bgq-field input.large-text').first().fill('Question ' + (i + 1));
    const answers = item.locator('.bgq-answer-row input[type=text]');
    await answers.nth(0).fill('Right'); await answers.nth(1).fill('Wrong');
  }
  // Two results with ranges.
  const resSection = page.locator('.bgq-section[data-section=results]');
  for (let i = 0; i < 2; i++) { await resSection.locator('button', { hasText: 'Add result' }).click(); }
  const results = resSection.locator('.bgq-item');
  await results.nth(0).locator('input.large-text').first().fill('Top');
  await results.nth(0).locator('.bgq-range input').nth(0).fill('50'); await results.nth(0).locator('.bgq-range input').nth(1).fill('100');
  await results.nth(1).locator('input.large-text').first().fill('Low');
  await results.nth(1).locator('.bgq-range input').nth(0).fill('0'); await results.nth(1).locator('.bgq-range input').nth(1).fill('49');
  await page.selectOption('.bgq-header select', 'publish');
  await page.click('.bgq-save');
  await page.waitForFunction(() => /Saved|Could not|error/i.test(document.getElementById('bgq-save-status').textContent), null, { timeout: 15000 });
  const status = await page.locator('#bgq-save-status').textContent();
  log(/Saved/.test(status), 'save reports success (' + status.trim() + ')');
  const savedId = await page.evaluate(() => window.bgq_builder.quiz_id);
  await page.goto(ADMIN + 'post.php?post=' + savedId + '&action=edit', { waitUntil: 'networkidle' });
  await page.waitForSelector('#bgq-builder .bgq-header', { timeout: 30000 });
  log((await page.locator('.bgq-title-input').inputValue()) === 'E2E built quiz', 'title persisted after reload');
  log(await page.locator('.bgq-section[data-section=questions]').locator('.bgq-item').count() === 2, 'questions persisted');
  log((await page.locator('.bgq-section[data-section=results]').locator('.bgq-item').first().locator('input.large-text').first().inputValue()) === 'Top', 'result persisted');
  log((await page.locator('.bgq-header select').inputValue()) === 'publish', 'status persisted');
  // Validation: clear a question text and save → error shown, not persisted.
  await page.locator('.bgq-section[data-section=questions]').locator('.bgq-item').first().locator('.bgq-field input.large-text').first().fill('');
  await page.click('.bgq-save');
  await page.waitForFunction(() => /Saved|Could not|errors|error/i.test(document.getElementById('bgq-save-status').textContent), null, { timeout: 15000 });
  log(await page.locator('.bgq-errors').count() === 1, 'validation errors listed');
  log(await page.locator('.bgq-item.has-error').count() >= 1, 'field-level error highlighted');
  const quizId = await page.evaluate(() => window.bgq_builder.quiz_id);
  log(/^\d+$/.test(String(quizId)), 'quiz id ' + quizId);
  log(errors.length === 0, 'no JS errors' + (errors.length ? ' -> ' + errors.join(' | ') : ''));
  await page.screenshot({ path: 'shot-builder.png', fullPage: true });
  console.log('QUIZ_ID=' + quizId);
  await browser.close();
  process.exit(out.some(l => l.includes('FAIL')) ? 1 : 0);
})().catch(e => { console.error('ERROR', e); process.exit(1); });
