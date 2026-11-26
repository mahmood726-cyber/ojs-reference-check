/**
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Adds a "Check references" panel below the References form on the editorial
 * workflow page. Written without a build step, so this is the code that runs.
 */
(function () {
	const {h, ref, computed, watch, resolveComponent} = pkp.modules.vue;

	// Statuses an editor should look at, most urgent first
	const ATTENTION = [
		'retracted',
		'concern',
		'corrected',
		'notice',
		'doi_not_found',
		'check_failed',
		'not_in_crossref',
	];
	const ALL = ATTENTION.concat(['no_notice', 'no_doi']);

	function doiLink(doi) {
		return h(
			'a',
			{
				href:
					'https://doi.org/' + encodeURIComponent(doi).replace(/%2F/g, '/'),
				target: '_blank',
				rel: 'noopener noreferrer',
			},
			doi,
		);
	}

	const ReferenceCheckPanel = {
		name: 'ReferenceCheckPanel',
		props: {
			submissionId: {type: Number, required: true},
			publicationId: {type: Number, required: true},
		},
		setup(props) {
			const {useLocalize} = pkp.modules.useLocalize;
			const {useUrl} = pkp.modules.useUrl;
			const {useFetch} = pkp.modules.useFetch;
			const {t} = useLocalize();

			const {apiUrl} = useUrl(
				computed(
					() =>
						`referenceCheck/${props.submissionId}/publications/${props.publicationId}`,
				),
			);
			const {data, fetch} = useFetch(apiUrl, {method: 'POST'});
			const isChecking = ref(false);
			const checkedPublicationId = ref(null);

			// Never show one version's results against another
			watch(
				() => props.publicationId,
				() => (checkedPublicationId.value = null),
			);

			async function check() {
				const publicationId = props.publicationId;
				isChecking.value = true;
				checkedPublicationId.value = null;
				try {
					await fetch();
					checkedPublicationId.value = publicationId;
				} finally {
					isChecking.value = false;
				}
			}

			const statusLabel = (status) =>
				t(`plugins.generic.referenceCheck.status.${status}`);

			function noticeItem(notice) {
				return h('li', [
					[notice.type, notice.date, notice.source].filter(Boolean).join(', '),
					notice.doi ? [': ', doiLink(notice.doi)] : null,
				]);
			}

			function referenceItem(reference) {
				return h('li', {key: reference.number, value: reference.number}, [
					h('strong', statusLabel(reference.status)),
					h('div', reference.text),
					h(
						'ul',
						reference.dois.map((doi) =>
							h('li', [
								doiLink(doi.doi),
								' - ',
								statusLabel(doi.status),
								doi.notices.length
									? h('ul', doi.notices.map(noticeItem))
									: null,
							]),
						),
					),
				]);
			}

			function results() {
				const report = data.value;
				if (
					!report ||
					checkedPublicationId.value !== props.publicationId
				) {
					return null;
				}
				if (!report.total) {
					return h('p', t('plugins.generic.referenceCheck.noReferences'));
				}
				const flagged = report.references
					.filter((reference) => ATTENTION.includes(reference.status))
					.sort(
						(a, b) =>
							ATTENTION.indexOf(a.status) - ATTENTION.indexOf(b.status) ||
							a.number - b.number,
					);

				return h('div', [
					h(
						'p',
						t('plugins.generic.referenceCheck.checked', {
							total: report.total,
							date: report.checkedAt,
						}),
					),
					h(
						'ul',
						ALL.filter((status) => report.summary[status]).map((status) =>
							h('li', `${statusLabel(status)}: ${report.summary[status]}`),
						),
					),
					flagged.length ? h('ol', flagged.map(referenceItem)) : null,
					h('p', t('plugins.generic.referenceCheck.limits')),
				]);
			}

			return () =>
				h('div', {class: 'referenceCheckPanel'}, [
					h('h3', t('plugins.generic.referenceCheck.displayName')),
					h('p', t('plugins.generic.referenceCheck.intro')),
					h(
						resolveComponent('PkpButton'),
						{isDisabled: isChecking.value, onClick: check},
						{
							default: () =>
								isChecking.value
									? t('plugins.generic.referenceCheck.checking')
									: t('plugins.generic.referenceCheck.check'),
						},
					),
					h('div', {'aria-live': 'polite'}, [results()]),
				]);
		},
	};

	pkp.registry.registerComponent('ReferenceCheckPanel', ReferenceCheckPanel);

	pkp.registry.storeExtend('workflow', (piniaContext) => {
		piniaContext.store.extender.extendFn('getPrimaryItems', (items, args) => {
			const menu = args?.selectedMenuState;
			if (
				menu?.primaryMenuItem !== 'publication' ||
				menu?.secondaryMenuItem !== 'citations' ||
				// Editors only; the server enforces this as well
				piniaContext.store.dashboardPage !== 'editorialDashboard' ||
				!args?.submission ||
				!args?.selectedPublication
			) {
				return items;
			}
			return [
				...items,
				{
					component: 'ReferenceCheckPanel',
					props: {
						submissionId: args.submission.id,
						publicationId: args.selectedPublication.id,
					},
				},
			];
		});
	});
})();
