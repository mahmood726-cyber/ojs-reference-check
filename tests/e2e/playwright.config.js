// Runs against a disposable OJS loaded with PKP's test journal (see the workflow)
const {defineConfig} = require('@playwright/test');

module.exports = defineConfig({
	testDir: '.',
	// The steps share one site, so they run in order
	workers: 1,
	retries: 0,
	timeout: 180000,
	expect: {timeout: 30000},
	reporter: 'list',
	outputDir: 'test-results',
	use: {
		baseURL: process.env.BASE_URL || 'http://localhost:8000',
		viewport: {width: 1600, height: 1200},
		actionTimeout: 30000,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
});
