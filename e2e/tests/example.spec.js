// @ts-check
const { test, expect } = require('@playwright/test');

test('homepage', async ({ page }) => {
  await page.goto('https://localhost/');
  await expect(page).toHaveTitle('Welcome to API Platform!');
});

test('swagger', async ({ page }) => {
  await page.goto('https://localhost/docs');
  await expect(page).toHaveTitle('Hello API Platform - API Platform');
  // Assert the resources we expose are listed rather than a hardcoded count —
  // Swagger-UI groups operations per resource plus per serialization context,
  // so the total span count shifts every time a resource or group is added.
  await expect(page.getByRole('heading', { name: 'Task', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Tag', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'User', exact: true })).toBeVisible();
});

test('admin', async ({ page, browserName }) => {
  // /admin is now gated behind ROLE_ADMIN — sign in as the admin fixture first.
  await page.goto('https://localhost/signin');
  await page.fill('#email', 'admin@aura.test');
  await page.fill('#password', 'admin123');
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/account/);

  await page.goto('https://localhost/admin');
  await page.getByLabel('Create').click();
  await page.getByLabel('Name').fill('foo' + browserName);
  await page.getByLabel('Save').click();
  await expect(page).toHaveURL(/admin#\/greetings$/);
  await page.getByText('foo' + browserName).first().click();
  await expect(page).toHaveURL(/show$/);
  await page.getByLabel('Edit').first().click();
  await page.getByLabel('Name').fill('bar' + browserName);
  await page.getByLabel('Save').click();
  await expect(page).toHaveURL(/admin#\/greetings$/);
  await page.getByText('bar' + browserName).first().click();
});
