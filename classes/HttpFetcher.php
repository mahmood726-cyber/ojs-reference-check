<?php

/**
 * @file classes/HttpFetcher.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @interface HttpFetcher
 * @brief The one network operation the checker needs.
 */

namespace APP\plugins\generic\referenceCheck\classes;

interface HttpFetcher
{
    /**
     * Send a GET request. HTTP error codes are returned, not thrown.
     *
     * @throws \Throwable When no response is received
     *
     * @return array{status: int, body: string}
     */
    public function get(string $url): array;
}
