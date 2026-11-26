/**
 * Run after main.spec.js, with OJS configured to use a proxy that does not
 * answer. This also shows that the plugin obeys the proxy setting of OJS.
 */
const {test, expect} = require('@playwright/test');
const {workflow, signIn, runCheck} = require('./helpers');

test('when the services cannot be reached, nothing looks checked', async ({
	browser,
}) => {
	const {context, page, errors} = await signIn(browser, 'dbarnes');
	await page.goto(workflow(2));
	const {panel, report} = await runCheck(page);
	expect(report.summary.check_failed).toBe(3);
	expect(report.summary.no_notice).toBe(0);
	await expect(panel).toContainText('Could not be checked - try again: 3');
	await expect(panel).not.toContainText('No notice found:');
	await expect(panel.locator('ol > li')).toHaveCount(3);
	await panel.screenshot({path: 'screenshots/panel-outage.png'});
	expect(errors).toEqual([]);
	await context.close();
});
