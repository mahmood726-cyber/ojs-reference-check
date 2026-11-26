<?php

/**
 * @file classes/DoiLookup.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DoiLookup
 *
 * @brief Ask Crossref, and failing that doi.org, about a set of DOIs.
 *
 * Anything that cannot be established is reported as CHECK_FAILED.
 */

namespace APP\plugins\generic\referenceCheck\classes;

use Closure;
use Throwable;

class DoiLookup
{
    public const CROSSREF = 'https://api.crossref.org/works';
    public const HANDLES = 'https://doi.org/api/handles/';
    /** Says which registry holds each of several DOIs */
    public const REGISTRIES = 'https://doi.org/ra/';

    private const BATCH_SIZE = 40;
    /** Crossref allows one filtered query, but several single records, each second */
    private const BATCH_PAUSE_SECONDS = 1.1;
    private const PAUSE_SECONDS = 0.25;
    /** Longer strings are taken to be something other than a DOI */
    private const MAX_DOI_LENGTH = 512;
    /** A title that publishers mark when a work is retracted or withdrawn */
    private const TITLE_MARKER = '#^\s*(?:(?:retracted(?:\s+article)?|withdrawn)\s*(?::|\x{2013}|\x{2014}|\s-\s)'
        . '|\[(?:retracted|withdrawn)\])|\((?:retracted|withdrawn)\)\s*$#iu';
    /** Waits grow by this much with each further attempt */
    private const RETRY_SECONDS = 2.0;
    /** A rate limit usually passes, for instance when two editors check at once */
    private const ATTEMPTS_WHEN_RATE_LIMITED = 4;
    private const ATTEMPTS_AFTER_OTHER_FAILURES = 2;
    /** Stop sending requests after this many failures in a row */
    private const MAX_FAILURES_IN_A_ROW = 3;

    private Closure $pause;
    private Closure $clock;
    private float $deadline = 0;
    private int $failuresInARow = 0;

    public function __construct(
        private HttpFetcher $http,
        private int $timeBudgetSeconds = 90,
        ?Closure $pause = null,
        ?Closure $clock = null
    ) {
        $this->pause = $pause ?? fn (float $seconds) => usleep((int) ($seconds * 1000000));
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    /**
     * @param string[] $dois Unique DOIs
     *
     * @return array<string, array{status: string, notices: array}> Keyed by lower-case DOI
     */
    public function lookup(array $dois): array
    {
        $this->deadline = ($this->clock)() + $this->timeBudgetSeconds;
        $this->failuresInARow = 0;

        $results = [];
        $single = [];
        $unanswered = [];

        // A comma separates filter values, so such DOIs cannot be batched
        $batchable = [];
        foreach ($dois as $doi) {
            if (!$this->maySend($doi)) {
                $results[strtolower($doi)] = $this->result(Status::UNREADABLE_DOI);
            } elseif (str_contains($doi, ',')) {
                $single[] = $doi;
            } else {
                $batchable[] = $doi;
            }
        }

        foreach (array_chunk($batchable, self::BATCH_SIZE) as $chunk) {
            $filter = implode(',', array_map(fn (string $doi) => 'doi:' . rawurlencode($doi), $chunk));
            $response = $this->request(
                self::CROSSREF . "?filter={$filter}&select=DOI,updated-by,title&rows=" . count($chunk),
                self::BATCH_PAUSE_SECONDS
            );

            if ($response === null) {
                foreach ($chunk as $doi) {
                    $results[strtolower($doi)] = $this->result(Status::CHECK_FAILED);
                }
                continue;
            }

            $items = $response['status'] === 200 ? ($this->decode($response['body'])['message']['items'] ?? null) : null;
            $found = [];
            foreach (is_array($items) ? $items : [] as $item) {
                if (is_array($item) && is_string($item['DOI'] ?? null)) {
                    $found[strtolower($item['DOI'])] = $this->fromRecord($item);
                }
            }
            foreach ($chunk as $doi) {
                if (isset($found[strtolower($doi)])) {
                    $results[strtolower($doi)] = $found[strtolower($doi)];
                } else {
                    $unanswered[] = $doi;
                }
            }
        }

        // Find out, many at a time, who holds the DOIs Crossref did not return
        foreach (array_chunk($unanswered, self::BATCH_SIZE) as $chunk) {
            $registries = $this->registries($chunk);
            foreach ($chunk as $doi) {
                $answer = $registries[strtolower($doi)] ?? null;
                if ($answer === 'Crossref') {
                    $single[] = $doi;
                } else {
                    $results[strtolower($doi)] = $this->result($answer ?? Status::CHECK_FAILED);
                }
            }
        }

        foreach ($single as $doi) {
            $results[strtolower($doi)] = $this->lookupOne($doi);
        }

        return $results;
    }

    /**
     * Refuse strings that could carry something else off the server, or that
     * would be read as a different address.
     */
    private function maySend(string $doi): bool
    {
        return strlen($doi) <= self::MAX_DOI_LENGTH
            // Path segments "." and ".." would be resolved away
            && !preg_match('#(?:^|/)\.{1,2}(?=/|$)#', $doi)
            // An e-mail address written against the DOI
            && !preg_match('/@[\w\-]+\.[A-Za-z]{2,}/', $doi);
    }

    /**
     * @param string[] $dois None containing a comma
     *
     * @return array<string, string> For each DOI answered, keyed in lower case:
     *   "Crossref" or the status that settles it
     */
    private function registries(array $dois): array
    {
        $path = implode(',', array_map($this->encodePath(...), $dois));
        $response = $this->request(self::REGISTRIES . $path);
        if ($response === null || $response['status'] !== 200) {
            return [];
        }

        $answers = [];
        foreach ($this->decode($response['body']) as $entry) {
            if (!is_array($entry) || !is_string($entry['DOI'] ?? null)) {
                continue;
            }
            $registry = $entry['RA'] ?? null;
            $problem = $entry['status'] ?? null;
            if (is_string($registry) && $registry !== '') {
                $answer = $registry === 'Crossref' ? $registry : Status::NOT_IN_CROSSREF;
            } elseif (in_array($problem, ['DOI does not exist', 'Invalid DOI'], true)) {
                $answer = Status::DOI_NOT_FOUND;
            } else {
                continue;
            }
            $answers[strtolower($entry['DOI'])] = $answer;
        }
        return $answers;
    }

    private function encodePath(string $doi): string
    {
        return str_replace('%2F', '/', rawurlencode($doi));
    }

    private function lookupOne(string $doi): array
    {
        $response = $this->request(self::CROSSREF . '/' . rawurlencode($doi));
        if ($response === null) {
            return $this->result(Status::CHECK_FAILED);
        }
        if ($response['status'] === 200) {
            $record = $this->decode($response['body'])['message'] ?? null;
            return is_array($record) && is_string($record['DOI'] ?? null)
                ? $this->fromRecord($record)
                : $this->result(Status::CHECK_FAILED);
        }
        if ($response['status'] !== 404) {
            return $this->result(Status::CHECK_FAILED);
        }

        // Unknown to Crossref: is it registered anywhere?
        $response = $this->request(self::HANDLES . $this->encodePath($doi) . '?type=URL');
        $code = $response === null ? null : ($this->decode($response['body'])['responseCode'] ?? null);

        return $this->result(match ($code) {
            // 200: the handle exists but holds no value of the requested type
            1, 200 => Status::NOT_IN_CROSSREF,
            100 => Status::DOI_NOT_FOUND,
            default => Status::CHECK_FAILED,
        });
    }

    private function fromRecord(array $record): array
    {
        $updates = $record['updated-by'] ?? [];
        if (!is_array($updates)) {
            return $this->result(Status::CHECK_FAILED);
        }

        $notices = [];
        foreach ($updates as $update) {
            $type = is_array($update) && is_string($update['type'] ?? null) ? $update['type'] : 'unknown';
            $date = $update['updated']['date-time'] ?? null;
            $notices[] = [
                'type' => $type,
                'status' => Status::fromUpdateType($type),
                'doi' => is_string($update['DOI'] ?? null) ? $update['DOI'] : null,
                'date' => is_string($date) ? substr($date, 0, 10) : null,
                'source' => is_string($update['source'] ?? null) ? $update['source'] : null,
            ];
        }

        $title = is_array($record['title'] ?? null) ? ($record['title'][0] ?? null) : null;
        if (
            is_string($title)
            && !in_array(Status::RETRACTED, array_column($notices, 'status'), true)
            && preg_match(self::TITLE_MARKER, $title)
        ) {
            $notices[] = [
                'type' => 'title marked as retracted or withdrawn',
                'status' => Status::RETRACTED,
                'doi' => null,
                'date' => null,
                'source' => 'Crossref title',
            ];
        }

        return $this->result(
            $notices ? Status::mostUrgent(array_column($notices, 'status')) : Status::NO_NOTICE,
            $notices
        );
    }

    /**
     * @return ?array{status: int, body: string} Null when no usable answer could be had
     */
    private function request(string $url, float $pause = self::PAUSE_SECONDS): ?array
    {
        $attempts = self::ATTEMPTS_AFTER_OTHER_FAILURES;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $wait = $attempt === 1 ? $pause : self::RETRY_SECONDS * ($attempt - 1);
            // Do not start a wait that would run past the time allowed
            if (
                $this->failuresInARow >= self::MAX_FAILURES_IN_A_ROW
                || ($this->clock)() + ($attempt === 1 ? 0 : $wait) >= $this->deadline
            ) {
                break;
            }
            ($this->pause)($wait);

            try {
                $response = $this->http->get($url);
            } catch (Throwable $e) {
                error_log('referenceCheck: request failed: ' . $e->getMessage());
                continue;
            }
            if ($response['status'] === 429) {
                $attempts = self::ATTEMPTS_WHEN_RATE_LIMITED;
            } elseif ($response['status'] < 500) {
                // Other refusals, such as 403, count towards giving up
                if (in_array($response['status'], [200, 400, 404], true)) {
                    $this->failuresInARow = 0;
                } else {
                    $this->failuresInARow++;
                }
                return $response;
            }
        }
        $this->failuresInARow++;
        return null;
    }

    private function decode(string $body): array
    {
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    private function result(string $status, array $notices = []): array
    {
        return ['status' => $status, 'notices' => $notices];
    }
}
