<?php

/**
 * @file classes/OjsHttpFetcher.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class OjsHttpFetcher
 * @brief Network access through the OJS HTTP client, so its proxy settings apply.
 */

namespace APP\plugins\generic\referenceCheck\classes;

use GuzzleHttp\ClientInterface;

class OjsHttpFetcher implements HttpFetcher
{
    private const USER_AGENT = 'OJSReferenceCheck/1.0';

    public function __construct(private ClientInterface $client) {}

    /** @copydoc HttpFetcher::get() */
    public function get(string $url): array
    {
        $response = $this->client->request('GET', $url, [
            'http_errors' => false,
            'connect_timeout' => 5,
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
        ]);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
