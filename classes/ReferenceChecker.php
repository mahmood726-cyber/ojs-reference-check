<?php

/**
 * @file classes/ReferenceChecker.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ReferenceChecker
 * @brief Turn a reference list into a report.
 */

namespace APP\plugins\generic\referenceCheck\classes;

class ReferenceChecker
{
    /** DOIs beyond this number are reported as not checked, never dropped */
    public const MAX_DOIS = 500;

    public function __construct(
        private DoiLookup $lookup,
        private DoiExtractor $extractor = new DoiExtractor()
    ) {}

    /**
     * @param string[] $references One reference per entry, in list order
     */
    public function check(array $references): array
    {
        $references = array_values($references);
        $doisByReference = array_map($this->extractor->extract(...), $references);

        $unique = [];
        foreach (array_merge([], ...$doisByReference) as $doi) {
            $unique[strtolower($doi)] ??= $doi;
        }
        $results = $this->lookup->lookup(array_values(array_slice($unique, 0, self::MAX_DOIS, true)));

        $summary = array_fill_keys(Status::BY_URGENCY, 0);
        $report = [];
        foreach ($references as $index => $text) {
            $dois = [];
            foreach ($doisByReference[$index] as $doi) {
                $dois[] = ['doi' => $doi] + ($results[strtolower($doi)]
                    ?? ['status' => Status::CHECK_FAILED, 'notices' => []]);
            }
            $statuses = array_column($dois, 'status');
            if ($this->extractor->hasBrokenDoi($text)) {
                $statuses[] = Status::UNREADABLE_DOI;
            }
            $status = $statuses ? Status::mostUrgent($statuses) : Status::NO_DOI;
            $summary[$status]++;
            $report[] = [
                'number' => $index + 1,
                // Invalid bytes would make the whole report impossible to encode
                'text' => mb_scrub($text, 'UTF-8'),
                'status' => $status,
                'dois' => $dois,
            ];
        }

        return [
            'checkedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'total' => count($references),
            'summary' => $summary,
            'references' => $report,
        ];
    }
}
