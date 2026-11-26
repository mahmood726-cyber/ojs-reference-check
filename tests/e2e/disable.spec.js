// Run last: switching the plugin off removes the panel and the endpoint
const {test, expect} = require('@playwright/test');
const {
	api,
	workflow,
	signIn,
	post,
	pluginBox,
	pluginsPage,
} = require('./helpers');

test('disabling the plugin removes the panel and the endpoint', async ({
	browser,
}) => {
	const {context, page, errors} = await signIn(browser, 'dbarnes');
	await page.goto(pluginsPage);
	await expect(pluginBox(page)).toBeChecked();
	await pluginBox(page).click();
	// OJS asks for confirmation
	await page.getByRole('dialog').getByRole('button', {name: 'OK'}).click();
	await expect(pluginBox(page)).not.toBeChecked();

	await page.goto(workflow(2));
	await expect(page.locator('textarea[name="citationsRaw"]')).toBeVisible();
	await page.waitForLoadState('networkidle');
	await expect(page.locator('.referenceCheckPanel')).toHaveCount(0);
	expect((await post(page, api(2, 3))).status()).toBe(404);
	expect(errors).toEqual([]);
	await context.close();
});
