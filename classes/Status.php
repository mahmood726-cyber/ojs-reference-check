<?php

/**
 * @file classes/Status.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Status
 * @brief The possible results of checking a reference.
 */

namespace APP\plugins\generic\referenceCheck\classes;

class Status
{
    public const RETRACTED = 'retracted';
    public const CONCERN = 'concern';
    public const CORRECTED = 'corrected';
    public const NOTICE = 'notice';
    public const DOI_NOT_FOUND = 'doi_not_found';
    public const CHECK_FAILED = 'check_failed';
    public const NOT_IN_CROSSREF = 'not_in_crossref';
    public const NO_NOTICE = 'no_notice';
    public const NO_DOI = 'no_doi';

    /** Every status, most urgent first */
    public const BY_URGENCY = [
        self::RETRACTED,
        self::CONCERN,
        self::CORRECTED,
        self::NOTICE,
        self::DOI_NOT_FOUND,
        self::CHECK_FAILED,
        self::NOT_IN_CROSSREF,
        self::NO_NOTICE,
        self::NO_DOI,
    ];

    /**
     * Crossref "update-type" values, including misspellings present in its data.
     * Any value not listed is reported as a general notice.
     */
    private const UPDATE_TYPES = [
        'retraction' => self::RETRACTED,
        'partial_retraction' => self::RETRACTED,
        'retracion' => self::RETRACTED,
        'retration' => self::RETRACTED,
        'withdrawal' => self::RETRACTED,
        'removal' => self::RETRACTED,
        'expression_of_concern' => self::CONCERN,
        'expression-of-concern' => self::CONCERN,
        'correction' => self::CORRECTED,
        'corrected' => self::CORRECTED,
        'corrected-article' => self::CORRECTED,
        'corrigendum' => self::CORRECTED,
        'erratum' => self::CORRECTED,
        'err' => self::CORRECTED,
        'addendum' => self::CORRECTED,
        'clarification' => self::CORRECTED,
    ];

    public static function fromUpdateType(string $type): string
    {
        return self::UPDATE_TYPES[strtolower(trim($type))] ?? self::NOTICE;
    }

    /**
     * @param string[] $statuses At least one status
     */
    public static function mostUrgent(array $statuses): string
    {
        foreach (self::BY_URGENCY as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }
        return self::CHECK_FAILED;
    }
}
