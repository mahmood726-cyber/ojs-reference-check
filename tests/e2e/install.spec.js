/**
 * Install the release package the way the Plugin Gallery does: through the OJS
 * plugin installer. (The gallery downloads the package; here it is uploaded.)
 */
const {test, expect} = require('@playwright/test');
const {signIn, pluginsPage, pluginBox} = require('./helpers');

test('the site administrator uploads the release package', async ({
	browser,
}) => {
	const {context, page} = await signIn(browser, 'admin');
	await page.goto(pluginsPage);
	await expect(pluginBox(page)).toHaveCount(0);

	await page.getByRole('link', {name: 'Upload A New Plugin'}).click();
	const form = page.locator('#uploadPluginForm');
	await form.locator('input[type=file]').setInputFiles(process.env.PACKAGE);
	await expect(form.locator('input[name="temporaryFileId"]')).not.toHaveValue(
		'',
	);
	await form.getByRole('button', {name: 'Save'}).click();
	await expect(form).toHaveCount(0);

	await page.reload();
	await expect(pluginBox(page)).toHaveCount(1);
	await expect(pluginBox(page)).not.toBeChecked();
	await context.close();
});
