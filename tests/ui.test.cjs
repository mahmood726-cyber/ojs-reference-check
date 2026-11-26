/**
 * Logic tests for the editor panel, with stand-ins for Vue and OJS. They do not
 * show that the panel renders in a real browser.
 *
 *     node --test tests/ui.test.cjs
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', 'public', 'js', 'referenceCheck.js'),
	'utf8',
);

function load({dashboardPage = 'editorialDashboard', response = null} = {}) {
	const seen = {components: {}, watchers: [], urls: [], options: null};
	let extension;
	const pkp = {
		modules: {
			vue: {
				h: (tag, a, b) => {
					const hasProps =
						a && typeof a === 'object' && !Array.isArray(a) && !a.tag;
					return {
						tag,
						props: hasProps ? a : {},
						children: hasProps ? b : a,
					};
				},
				ref: (value) => ({value}),
				computed: (get) => ({
					get value() {
						return get();
					},
				}),
				watch: (from, run) => seen.watchers.push(run),
				resolveComponent: (name) => name,
			},
			useLocalize: {useLocalize: () => ({t: (key) => key})},
			useUrl: {useUrl: (url) => ({apiUrl: url})},
			useFetch: {
				useFetch: (url, options) => {
					const data = {value: null};
					seen.options = options;
					return {
						data,
						fetch: async () => {
							seen.urls.push(url.value);
							data.value = response;
						},
					};
				},
			},
		},
		registry: {
			registerComponent: (name, c) => (seen.components[name] = c),
			storeExtend: (name, run) => {
				assert.strictEqual(name, 'workflow');
				run({
					store: {
						dashboardPage,
						extender: {extendFn: (fn, f) => (extension = {fn, f})},
					},
				});
			},
		},
	};
	vm.runInNewContext(source, {pkp});
	return {seen, extension};
}

/** All text in a rendered tree */
function text(node) {
	if (node === null || node === undefined) return '';
	if (typeof node !== 'object') return `${node} `;
	if (Array.isArray(node)) return node.map(text).join('');
	if (node.default) return text(node.default());
	return text(node.children);
}

function find(node, tag, found = []) {
	if (Array.isArray(node)) node.forEach((n) => find(n, tag, found));
	else if (node && typeof node === 'object') {
		if (node.tag === tag) found.push(node);
		find(node.children, tag, found);
	}
	return found;
}

const args = {
	selectedMenuState: {
		primaryMenuItem: 'publication',
		secondaryMenuItem: 'citations',
	},
	submission: {id: 5},
	selectedPublication: {id: 7},
};

test('panel is added to the editorial references tab', () => {
	const {extension} = load();
	assert.strictEqual(extension.fn, 'getPrimaryItems');
	const items = extension.f([{component: 'Form'}], args);
	assert.strictEqual(items.length, 2);
	assert.strictEqual(items[1].component, 'ReferenceCheckPanel');
	assert.strictEqual(items[1].props.submissionId, 5);
	assert.strictEqual(items[1].props.publicationId, 7);
});

test('panel is not added elsewhere', () => {
	const {extension} = load();
	const other = {
		...args,
		selectedMenuState: {
			primaryMenuItem: 'publication',
			secondaryMenuItem: 'metadata',
		},
	};
	assert.strictEqual(extension.f([], other).length, 0);
	assert.strictEqual(
		extension.f([], {...args, selectedPublication: null}).length,
		0,
	);
	const author = load({dashboardPage: 'mySubmissions'}).extension;
	assert.strictEqual(author.f([], args).length, 0);
});

test('results list the most urgent first and hide clean references', async () => {
	const reference = (number, status) => ({
		number,
		status,
		text: `reference-${number}`,
		dois: [{doi: `10.1000/${number}#x`, status, notices: []}],
	});
	const {seen} = load({
		response: {
			checkedAt: '2026-11-26T00:00:00Z',
			total: 3,
			summary: {no_notice: 1, check_failed: 1, retracted: 1},
			references: [
				reference(1, 'no_notice'),
				reference(2, 'check_failed'),
				reference(3, 'retracted'),
			],
		},
	});
	const props = {submissionId: 5, publicationId: 7};
	const render = seen.components.ReferenceCheckPanel.setup(props);

	assert.ok(!text(render()).includes('reference-'));
	await find(render(), 'PkpButton')[0].props.onClick();

	assert.deepStrictEqual(seen.urls, ['referenceCheck/5/publications/7']);
	assert.strictEqual(seen.options.method, 'POST');
	const shown = text(render());
	assert.ok(shown.indexOf('reference-3') < shown.indexOf('reference-2'));
	assert.ok(!shown.includes('reference-1'));
	assert.ok(shown.includes('plugins.generic.referenceCheck.reference:'));
	assert.ok(shown.includes('plugins.generic.referenceCheck.limits'));
	assert.strictEqual(
		find(render(), 'a')[0].props.href,
		'https://doi.org/10.1000/3%23x',
	);

	// Another version must not show these results
	props.publicationId = 8;
	assert.ok(!text(render()).includes('reference-'));
});

test('a failed request shows no results', async () => {
	const {seen} = load({response: null});
	const render = seen.components.ReferenceCheckPanel.setup({
		submissionId: 5,
		publicationId: 7,
	});
	await find(render(), 'PkpButton')[0].props.onClick();
	assert.ok(!text(render()).includes('status.'));
});
