/**
 * On OJS before 3.5.0-4, which has no plugin API endpoints, enabling the plugin
 * must leave the site as it was.
 */
const {test, expect} = require('@playwright/test');
const {
	api,
	workflow,
	signIn,
	post,
	pluginBox,
	pluginsPage,
} = require('./helpers');

test('an unsupported release shows no panel and keeps working', async ({
	browser,
}) => {
	const {context, page, errors} = await signIn(browser, 'dbarnes');
	await page.goto(pluginsPage);
	await pluginBox(page).click();
	await expect(pluginBox(page)).toBeChecked();

	await page.goto(workflow(2));
	await expect(page.locator('textarea[name="citationsRaw"]')).toBeVisible();
	await page.waitForLoadState('networkidle');
	await expect(page.locator('.referenceCheckPanel')).toHaveCount(0);
	expect((await post(page, api(2, 3))).status()).toBe(404);
	expect(errors).toEqual([]);
	await context.close();
});
