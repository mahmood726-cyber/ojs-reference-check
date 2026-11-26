<?php

/**
 * @file classes/DoiLookup.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DoiLookup
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

    private const BATCH_SIZE = 20;
    private const PAUSE_SECONDS = 0.25;
    private const RETRY_SECONDS = 2.0;
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

        // A comma separates filter values, so such DOIs cannot be batched
        $batchable = [];
        foreach ($dois as $doi) {
            if (str_contains($doi, ',')) {
                $single[] = $doi;
            } else {
                $batchable[] = $doi;
            }
        }

        foreach (array_chunk($batchable, self::BATCH_SIZE) as $chunk) {
            $filter = implode(',', array_map(fn (string $doi) => 'doi:' . rawurlencode($doi), $chunk));
            $response = $this->request(self::CROSSREF . "?filter={$filter}&select=DOI,updated-by&rows=" . count($chunk));

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
            // Whatever the batch did not answer is asked for one at a time
            foreach ($chunk as $doi) {
                if (isset($found[strtolower($doi)])) {
                    $results[strtolower($doi)] = $found[strtolower($doi)];
                } else {
                    $single[] = $doi;
                }
            }
        }

        foreach ($single as $doi) {
            $results[strtolower($doi)] = $this->lookupOne($doi);
        }

        return $results;
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
        $response = $this->request(self::HANDLES . str_replace('%2F', '/', rawurlencode($doi)) . '?type=URL');
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

        return $this->result(
            $notices ? Status::mostUrgent(array_column($notices, 'status')) : Status::NO_NOTICE,
            $notices
        );
    }

    /**
     * @return ?array{status: int, body: string} Null when no usable answer could be had
     */
    private function request(string $url): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($this->failuresInARow >= self::MAX_FAILURES_IN_A_ROW || ($this->clock)() >= $this->deadline) {
                return null;
            }
            ($this->pause)($attempt === 1 ? self::PAUSE_SECONDS : self::RETRY_SECONDS);

            try {
                $response = $this->http->get($url);
                // Rate limits and server errors are worth one more try
                if ($response['status'] !== 429 && $response['status'] < 500) {
                    $this->failuresInARow = 0;
                    return $response;
                }
            } catch (Throwable $e) {
                error_log('referenceCheck: request failed: ' . $e->getMessage());
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
