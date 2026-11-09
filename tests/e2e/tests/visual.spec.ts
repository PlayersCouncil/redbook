import { test, expect } from '../helpers/test';
import { storageFor } from '../helpers/users';

// Screenshot comparisons of key pages, to catch template and CSS breakage
// (the PHP/Symfony upgrade in particular).
//
// Baselines are not committed: fonts, CDN availability and the OS all change
// the pixels. Record them on your machine with `npm run test:visual:baseline`
// on the known-good version, switch versions, then `npm run test:visual`.
//
// Things that change from run to run (dates, decklists made by other tests,
// card images from the external CDN) are masked.

test.use({ storageState: storageFor('author') });

const opts = { fullPage: true, maxDiffPixelRatio: 0.01 };

// Each page in both themes (Ketura, 2026-11-09): the light theme, and the dark one set the
// way the theme switch sets it, before the page paints.
for (const theme of ['light', 'dark'] as const) {
  test.describe(theme, () => {
    test.beforeEach(async ({ context }) => {
      await context.addInitScript((t) => { try { localStorage.setItem('pc-theme', t); } catch (e) { /* none */ } }, theme);
    });
    const shot = (name: string) => name.replace('.png', `-${theme}.png`);

    test('login page', async ({ browser }) => {
      // Logged out: a fresh context without the stored login
      const context = await browser.newContext();
      await context.addInitScript((t) => { try { localStorage.setItem('pc-theme', t); } catch (e) { /* none */ } }, theme);
      const page = await context.newPage();
      await page.goto('/login');
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(shot('login.png'), { ...opts, mask: [page.locator('.cc-window')] });
      await context.close();
    });

    test('card page', async ({ page }) => {
      await page.goto('/card/01001');
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(shot('card.png'), { ...opts, mask: [page.locator('img'), page.locator('time')] });
    });

    test('card search results', async ({ page }) => {
      await page.goto('/find?q=Eddard&view=list');
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(shot('search.png'), { ...opts, mask: [page.locator('img')] });
    });

    test('new deck page', async ({ page }) => {
      await page.goto('/deck/new');
      await page.waitForLoadState('networkidle');
      await expect(page).toHaveScreenshot(shot('deck-new.png'), opts);
    });
  });
}
