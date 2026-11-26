/**
 * The installed plugin in a running OJS, with PKP's test journal.
 *
 * Needs a network: the checks really ask Crossref and doi.org.
 */
const {test, expect} = require('@playwright/test');

const JOURNAL = '/index.php/publicknowledge';
const api = (submission, publication) =>
	`${JOURNAL}/api/v1/referenceCheck/${submission}/publications/${publication}`;

// Submission 2 (publication 3): author ccorino, section editor minoue assigned.
// Submission 3 (publication 4): minoue is NOT assigned.
const OWN = api(2, 3);

const REFERENCES = [
	'Wakefield AJ, et al. Lancet. 1998;351:637-41. doi:10.1016/S0140-6736(97)11096-0',
	'Kucsko G, et al. Nature. 2013;500:54-8. https://doi.org/10.1038/nature12373',
	'A reference with a mistyped DOI. doi:10.1038/this-does-not-exist-000000',
	'A book without a DOI. London: Press; 1999.',
];

test.describe.configure({mode: 'serial'});

/** A signed-in browser session. PKP test passwords repeat the user name. */
async function signIn(browser, username) {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto(`${JOURNAL}/en/login`);
	await page.locator('input#username').fill(username);
	await page
		.locator('input#password')
		.fill(username === 'admin' ? 'admin' : username + username);
	await page.locator('form#login button[type=submit]').click();
	await page.waitForURL((url) => !url.pathname.includes('/login'));
	return {context, page};
}

/** Call the plugin's API as the user of a session */
async function callApi(page, url, {token = true} = {}) {
	await page.waitForFunction(() => window.pkp?.currentUser?.csrfToken);
	const csrf = await page.evaluate(() => window.pkp.currentUser.csrfToken);
	return page.request.post(url, {
		headers: token ? {'X-Csrf-Token': csrf} : {},
		timeout: 90000,
	});
}

const workflow = (dashboard) =>
	`${JOURNAL}/en/dashboard/${dashboard}?workflowSubmissionId=2&workflowMenuKey=publication_citations`;

test('the endpoint does not exist until the plugin is enabled', async ({
	browser,
}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(`${JOURNAL}/en/dashboard/editorial`);
	expect((await callApi(page, OWN)).status()).toBe(404);
	await context.close();
});

test('a journal manager can enable the plugin', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(`${JOURNAL}/en/management/settings/website#plugins`);
	const box = page.locator(
		'input[id^="select-cell-referencecheckplugin-enabled"]',
	);
	await expect(box).not.toBeChecked();
	await box.click();
	await expect(box).toBeChecked();
	await page.reload();
	await expect(box).toBeChecked();
	await expect(page.getByText('Reference Check', {exact: true})).toBeVisible();
	await context.close();
});

test('an editor saves references and checks them', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	const errors = [];
	page.on('pageerror', (error) => errors.push(String(error.stack || error)));

	await page.goto(workflow('editorial'));
	const textarea = page.locator('textarea[name="citationsRaw"]');
	await textarea.fill(REFERENCES.join('\n'));
	const saved = page.waitForResponse(
		(r) =>
			r.url().includes('/publications/3') && r.request().method() !== 'GET',
	);
	await page
		.locator('form', {has: textarea})
		.getByRole('button', {name: 'Save'})
		.click();
	expect((await saved).status()).toBe(200);

	const panel = page.locator('.referenceCheckPanel');
	await panel.getByRole('button', {name: 'Check references'}).click();

	await expect(panel).toContainText('References checked: 4.', {timeout: 90000});
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
		panel.locator(
			'a[href="https://doi.org/10.1016/s0140-6736(10)60175-4"]',
		),
	).toBeVisible();
	// No untranslated keys
	await expect(panel).not.toContainText('##');
	await expect(panel).not.toContainText('plugins.generic');

	await panel.screenshot({path: 'test-results/panel.png'});
	await page.screenshot({path: 'test-results/workflow.png'});
	expect(errors).toEqual([]);
	await context.close();
});

test('an author sees no panel', async ({browser}) => {
	const {context, page} = await signIn(browser, 'ccorino');
	await page.goto(workflow('mySubmissions'));
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
	['dbarnes', api(2, 4), 'a publication belonging to another submission'],
]) {
	test(`the API refuses ${why}`, async ({browser}) => {
		const {context, page} = await signIn(browser, who);
		await page.goto(`${JOURNAL}/en/dashboard`);
		expect([401, 403, 404]).toContain((await callApi(page, url)).status());
		await context.close();
	});
}

test('the API answers an assigned section editor', async ({browser}) => {
	const {context, page} = await signIn(browser, 'minoue');
	await page.goto(`${JOURNAL}/en/dashboard/editorial`);
	const response = await callApi(page, OWN);
	expect(response.status()).toBe(200);
	expect((await response.json()).total).toBe(4);
	await context.close();
});

test('the API requires the CSRF token', async ({browser}) => {
	const {context, page} = await signIn(browser, 'dbarnes');
	await page.goto(`${JOURNAL}/en/dashboard/editorial`);
	const response = await callApi(page, OWN, {token: false});
	expect([401, 403]).toContain(response.status());
	await context.close();
});

test('the API refuses someone who is not signed in', async ({request}) => {
	expect([401, 403]).toContain((await request.post(OWN)).status());
});
