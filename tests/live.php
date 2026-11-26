<?php

/**
 * Smoke test against the real Crossref and doi.org services (needs a network):
 *
 *     php tests/live.php
 *
 * The expected results are facts about published records and may change.
 */

use APP\plugins\generic\referenceCheck\classes\DoiLookup;
use APP\plugins\generic\referenceCheck\classes\HttpFetcher;
use APP\plugins\generic\referenceCheck\classes\ReferenceChecker;

require __DIR__ . '/bootstrap.php';

$http = new class () implements HttpFetcher {
    public function get(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'OJSReferenceCheck/1.0 (test)',
        ]);
        $body = curl_exec($curl);
        if ($body === false) {
            throw new RuntimeException(curl_error($curl));
        }
        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }
};

$cases = [
    // Wakefield et al., Lancet 1998: retracted 2010
    'retracted' => 'Wakefield AJ, et al. Lancet. 1998;351:637-41. doi:10.1016/S0140-6736(97)11096-0.',
    'no_notice' => 'https://doi.org/10.1038/nature12373',
    // A Zenodo record: registered with DataCite
    'not_in_crossref' => 'https://doi.org/10.5281/zenodo.3678325',
    'doi_not_found' => 'doi:10.1038/this-does-not-exist-000000',
    'no_doi' => 'A book without a DOI. London; 1999.',
];

$report = (new ReferenceChecker(new DoiLookup($http)))->check(array_values($cases));

$failed = 0;
foreach (array_keys($cases) as $index => $expected) {
    $actual = $report['references'][$index]['status'];
    $failed += $actual === $expected ? 0 : 1;
    echo ($actual === $expected ? 'ok  ' : 'FAIL'), " expected {$expected}, got {$actual}\n";
    foreach ($report['references'][$index]['dois'] as $doi) {
        foreach ($doi['notices'] as $notice) {
            echo "       {$notice['type']} {$notice['date']} ({$notice['source']})\n";
        }
    }
}
exit($failed ? 1 : 0);
