/**
 * The installed plugin in a running OJS.
 *
 * Needs a network: the checks really ask Crossref and doi.org.
 *
 * Submission 1 (publication 1) is published. Submission 2 (publication 3):
 * author ccorino, section editor minoue assigned. Submission 3 (publication 4):
 * minoue is NOT assigned. Submission 4 (publication 5) has no references.
 * Assistant svogt is assigned to submission 9 (publication 10), not to 2.
 */
const fs = require('node:fs');
const path = require('node:path');
const {test, expect, firefox, webkit} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {
	JOURNAL,
	REFERENCES,
	api,
	workflow,
	signIn,
	post,
	saveReferences,
	runCheck,
	pluginBox,
	pluginsPage,
} = require('./helpers');

const OWN = api(2, 3);
const dashboard = `${JOURNAL}/en/dashboard/editorial`;

test.describe.configure({mode: 'serial'});

test('the endpoint does not exist until the plugin is enabled', async ({
	browser,
}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(dashboard);
	expect((await post(page, OWN)).status()).toBe(404);
	await context.close();
});

test('a journal manager can enable the plugin', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(pluginsPage);
	await expect(pluginBox(page)).not.toBeChecked();
	await pluginBox(page).click();
	await expect(pluginBox(page)).toBeChecked();
	await page.reload();
	await expect(pluginBox(page)).toBeChecked();
	await expect(page.getByText('Reference Check', {exact: true})).toBeVisible();
	await context.close();
});

test('an editor saves references and checks them', async ({browser}) => {
	const {context, page, errors} = await signIn(browser, 'dbarnes');
	await page.goto(workflow(2));
	await saveReferences(page, 3, REFERENCES);
	const {panel} = await runCheck(page);

	await expect(panel).toContainText('References checked: 4.');
	await expect(panel).toContainText('Retracted, withdrawn or removed: 1');
	await expect(panel).toContainText(
		'DOI not found - check for a typing error: 1',
	);
	await expect(panel).toContainText('No notice found: 1');
	await expect(panel).toContainText('No DOI - not checked: 1');
	// Only references needing attention are listed, most urgent first
	const listed = panel.locator('ol > li');
	await expect(listed).toHaveCount(2);
	await expect(listed.first()).toContainText('Wakefield');
	// Each is identified by its position in the saved list
	await expect(listed.first()).toContainText('Reference 1: Retracted');
	await expect(listed.nth(1)).toContainText('Reference 3: DOI not found');
	// The style sheet is loaded
	await expect(listed.first()).toHaveCSS('border-left-style', 'solid');
	await expect(panel.locator('ol')).not.toContainText('Kucsko');
	await expect(
		panel.locator('a[href="https://doi.org/10.1016/s0140-6736(10)60175-4"]'),
	).toBeVisible();
	// No untranslated keys
	await expect(panel).not.toContainText('##');
	await expect(panel).not.toContainText('plugins.generic');

	await panel.screenshot({path: 'screenshots/panel.png'});
	await page.screenshot({path: 'screenshots/workflow.png'});

	const accessibility = await new AxeBuilder({page})
		.include('.referenceCheckPanel')
		.analyze();
	expect(
		accessibility.violations.map((v) => `${v.id}: ${v.nodes[0].html}`),
	).toEqual([]);
	expect(errors).toEqual([]);
	await context.close();
});

test('saving a changed list removes the old results', async ({browser}) => {
	const {context, page, errors} = await signIn(browser, 'dbarnes');
	await page.goto(workflow(2));
	const {panel} = await runCheck(page);
	await expect(panel).toContainText('References checked: 4.');

	await saveReferences(page, 3, REFERENCES.slice(1));
	await expect(panel).toContainText('differs from the one checked');
	await expect(panel).not.toContainText('References checked');
	await expect(panel.locator('ol')).toHaveCount(0);

	await runCheck(page);
	await expect(panel).toContainText('References checked: 3.');
	await expect(panel).not.toContainText('Retracted');
	expect(errors).toEqual([]);
	await context.close();
});

test('hostile reference text is only ever text', async ({browser}) => {
	const {context, page, errors} = await signIn(browser, 'dbarnes');
	await page.goto(workflow(2));
	await saveReferences(page, 3, [
		'<img src=x onerror="window.injected=1"> doi:10.1038/this-does-not-exist-000000',
		'"><script>window.injected=2</script> https://doi.org/10.1038/x"onmouseover="window.injected=3',
		'doi:10.1038/<svg/onload=window.injected=4>',
		'<a href="javascript:window.injected=5">doi:10.1038/also-not-real-000000</a>',
	]);
	const {panel, report} = await runCheck(page);
	expect(report.total).toBeGreaterThan(0);
	await expect(panel).toContainText('References checked');
	await expect(panel.locator('img, script, svg, iframe')).toHaveCount(0);
	for (const link of await panel.locator('a').all()) {
		expect(await link.getAttribute('href')).toMatch(/^https:\/\/doi\.org\//);
		await link.hover();
	}
	expect(await page.evaluate(() => window.injected)).toBeUndefined();
	expect(errors).toEqual([]);
	await context.close();
});

test('an emptied list says that nothing was checked', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(workflow(2));
	await saveReferences(page, 3, []);
	const {panel} = await runCheck(page);
	await expect(panel).toContainText('so nothing was checked');
	// Later tests rely on this list
	await saveReferences(page, 3, REFERENCES);
	await context.close();
});

test('a long reference list is checked within the time allowed', async ({
	browser,
}) => {
	const long = fs
		.readFileSync(path.join(__dirname, 'long-list.txt'), 'utf8')
		.trim()
		.split('\n');
	const {context, page, errors} = await signIn(browser, 'dbarnes');
	await page.goto(workflow(4));
	await saveReferences(page, 5, long);
	const started = Date.now();
	const {panel, report} = await runCheck(page);
	const seconds = (Date.now() - started) / 1000;
	console.log(
		`long list: ${report.total} references in ${seconds}s`,
		JSON.stringify(report.summary),
	);
	expect(report.total).toBe(long.length);
	expect(seconds).toBeLessThan(75);
	// A shared test machine can be rate limited, but most must get through
	expect(report.summary.check_failed).toBeLessThan(long.length / 5);
	await expect(panel).toContainText(`References checked: ${long.length}.`);
	expect(errors).toEqual([]);
	await context.close();
});

test('an author sees no panel', async ({browser}) => {
	const {context, page} = await signIn(browser, 'ccorino');
	await page.goto(workflow(2, 'mySubmissions'));
	// Wait until the references view itself is on screen
	await expect(
		page.getByRole('heading', {name: /References/}).first(),
	).toBeVisible();
	await page.waitForLoadState('networkidle');
	await expect(page.locator('.referenceCheckPanel')).toHaveCount(0);
	await context.close();
});

for (const [who, url, why] of [
	['ccorino', OWN, 'the author of the submission'],
	['jjanssen', OWN, 'a reviewer'],
	['minoue', api(3, 4), 'a section editor who is not assigned'],
	['svogt', OWN, 'an assistant who is not assigned'],
	['dbarnes', api(2, 4), 'a publication belonging to another submission'],
	['dbarnes', api(2, 99999), 'a publication that does not exist'],
	['dbarnes', api(99999, 3), 'a submission that does not exist'],
]) {
	test(`the API refuses ${why}`, async ({browser}) => {
		const {context, page} = await signIn(browser, who);
		await page.goto(`${JOURNAL}/en/dashboard`);
		expect([401, 403, 404]).toContain((await post(page, url)).status());
		await context.close();
	});
}

for (const [who, url, why] of [
	['admin', OWN, 'the site administrator'],
	['rvaca', OWN, 'a journal manager'],
	['dbuskins', OWN, 'an assigned section editor'],
	['minoue', OWN, 'another assigned section editor'],
	['svogt', api(9, 10), 'an assigned assistant'],
	['dbarnes', api(1, 1), 'an editor of a published submission'],
]) {
	test(`the API answers ${why}`, async ({browser}) => {
		const {context, page} = await signIn(browser, who);
		await page.goto(dashboard);
		const response = await post(page, url);
		expect(response.status()).toBe(200);
		expect((await response.json()).references).toBeDefined();
		await context.close();
	});
}

test('a new version of a publication can be checked', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(dashboard);
	const original = await (await post(page, api(1, 1))).json();
	const created = await post(
		page,
		`${JOURNAL}/api/v1/submissions/1/publications/1/version`,
	);
	expect(created.status()).toBe(200);
	const {id} = await created.json();
	const response = await post(page, api(1, id));
	expect(response.status()).toBe(200);
	expect((await response.json()).total).toBe(original.total);
	await context.close();
});

test('the API requires the CSRF token', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(dashboard);
	const response = await post(page, OWN, {token: false});
	expect([401, 403]).toContain(response.status());
	await context.close();
});

test('the API refuses other methods, as OJS does', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(dashboard);
	const ours = await page.request.get(OWN);
	// A POST-only endpoint of OJS itself, for comparison
	const core = await page.request.get(
		`${JOURNAL}/api/v1/submissions/1/publications/1/version`,
	);
	console.log(`GET on a POST-only endpoint: OJS ${core.status()}`);
	expect(ours.ok()).toBe(false);
	expect(ours.status()).toBe(core.status());
	expect(await ours.text()).not.toContain('"references"');
	await context.close();
});

test('the API refuses someone who is not signed in', async ({request}) => {
	expect([401, 403]).toContain((await request.post(OWN)).status());
});

for (const engine of [firefox, webkit]) {
	test(`the panel works in ${engine.name()}`, async ({baseURL}) => {
		const other = await engine.launch();
		try {
			const {page, errors} = await signIn(other, 'dbarnes', {
				baseURL,
				viewport: {width: 1600, height: 1200},
			});
			await page.goto(workflow(2));
			const {panel} = await runCheck(page);
			await expect(panel).toContainText('Reference 1: Retracted');
			await panel.screenshot({path: `screenshots/panel-${engine.name()}.png`});
			expect(errors).toEqual([]);
		} finally {
			await other.close();
		}
	});
}
