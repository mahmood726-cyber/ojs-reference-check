// Shared by the browser tests. The site holds PKP's test journal.
const {expect} = require('@playwright/test');

const JOURNAL = '/index.php/publicknowledge';

const api = (submission, publication) =>
	`${JOURNAL}/api/v1/referenceCheck/${submission}/publications/${publication}`;

const workflow = (submission, dashboard = 'editorial') =>
	`${JOURNAL}/en/dashboard/${dashboard}?workflowSubmissionId=${submission}&workflowMenuKey=publication_citations`;

// DOIs: retracted, clean, mistyped, none
const REFERENCES = [
	'Wakefield AJ, et al. Lancet. 1998;351:637-41. doi:10.1016/S0140-6736(97)11096-0',
	'Kucsko G, et al. Nature. 2013;500:54-8. https://doi.org/10.1038/nature12373',
	'A reference with a mistyped DOI. doi:10.1038/this-does-not-exist-000000',
	'A book without a DOI. London: Press; 1999.',
];

/** A signed-in browser session. PKP test passwords repeat the user name. */
async function signIn(browser, username, options = {}) {
	const context = await browser.newContext(options);
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', (error) => errors.push(String(error.stack || error)));
	page.on('dialog', async (dialog) => {
		errors.push(`dialog: ${dialog.message()}`);
		await dialog.dismiss();
	});
	await page.goto(`${JOURNAL}/en/login`);
	await page.locator('input#username').fill(username);
	await page
		.locator('input#password')
		.fill(username === 'admin' ? 'admin' : username + username);
	await page.locator('form#login button[type=submit]').click();
	await page.waitForURL((url) => !url.pathname.includes('/login'));
	return {context, page, errors};
}

/** POST to the OJS API as the user of a session */
async function post(page, url, {token = true} = {}) {
	await page.waitForFunction(() => window.pkp?.currentUser?.csrfToken);
	const csrf = await page.evaluate(() => window.pkp.currentUser.csrfToken);
	return page.request.post(url, {
		headers: token ? {'X-Csrf-Token': csrf} : {},
		timeout: 90000,
	});
}

/** Type a reference list into the References form and save it */
async function saveReferences(page, publication, references) {
	const textarea = page.locator('textarea[name="citationsRaw"]');
	await textarea.fill(references.join('\n'));
	const saved = page.waitForResponse(
		(r) =>
			r.url().includes(`/publications/${publication}`) &&
			r.request().method() !== 'GET',
	);
	await page
		.locator('form', {has: textarea})
		.getByRole('button', {name: 'Save'})
		.click();
	expect((await saved).status()).toBe(200);
}

/** Press the button and return the panel and the report behind it */
async function runCheck(page) {
	const panel = page.locator('.referenceCheckPanel');
	const answered = page.waitForResponse(
		(r) => r.url().includes('/referenceCheck/'),
		{timeout: 120000},
	);
	await panel.getByRole('button', {name: 'Check references'}).click();
	const response = await answered;
	expect(response.status()).toBe(200);
	return {panel, report: await response.json()};
}

const pluginBox = (page) =>
	page.locator('input[id^="select-cell-referencecheckplugin-enabled"]');

const pluginsPage = `${JOURNAL}/en/management/settings/website#plugins`;

module.exports = {
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
};
