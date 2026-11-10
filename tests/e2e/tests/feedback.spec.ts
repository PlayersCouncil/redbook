import { test, expect, runId } from '../helpers/test';
import { loadUsers, storageFor } from '../helpers/users';
import { appConsole } from '../helpers/console';
import { waitForMail } from '../helpers/mailpit';

// The bug report form (Ketura, 2026-11-09): in the top bar on every page; the page and the
// name filled in for you; sent to the admins by email.

test.use({ storageState: storageFor('voter') });

test('the bug report form fills in the page and name, and emails the admins', async ({ page }) => {
  const users = loadUsers();
  // someone to receive it: the admins, when APP_FEEDBACK_EMAIL is left empty
  appConsole(['fos:user:promote', users.author.username, 'ROLE_ADMIN']);
  await page.goto('/decklists');
  await page.click('#nav-feedback');
  const modal = page.locator('#feedback-modal');
  await expect(modal).toBeVisible();
  await expect(page.locator('#feedback-page')).toHaveValue(/\/decklists$/);
  await expect(page.locator('#feedback-name')).toHaveValue(users.voter.username);
  // empty: not sent
  await modal.locator('button[type=submit]').click();
  await expect(modal.locator('.rb-feedback-status')).toHaveClass(/is-error/);
  await page.selectOption('#feedback-category', 'question');
  await page.fill('#feedback-contact', 'voter@example.com');
  await page.fill('#feedback-message', `How do **libraries** work? <script>alert(1)</script> ${runId}`);
  await modal.locator('button[type=submit]').click();
  await expect(modal.locator('.rb-feedback-status')).toHaveClass(/is-sent/);
  const mail = await waitForMail(users.author.email, new RegExp(`Question: How do libraries work\\?.*`));
  expect(mail.text).toContain(runId);
  expect(mail.text).toContain('/decklists');
  expect(mail.text).toContain(users.voter.username);
});
