<?php

/**
 * @file classes/DoiExtractor.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DoiExtractor
 * @brief Find the DOIs written in a free-text reference.
 */

namespace APP\plugins\generic\referenceCheck\classes;

class DoiExtractor
{
    /** HTML tags that may surround a DOI. Other "<...>" text can be part of a DOI. */
    private const HTML_TAGS = '#</?(?:a|b|br|div|em|i|li|p|span|strong|sub|sup|u)(?:\s[^<>]*)?/?>#i';

    private const CLOSERS = [')' => '(', ']' => '[', '}' => '{', '>' => '<'];

    /**
     * @return string[] Unique DOIs (compared without case), in order of appearance
     */
    public function extract(string $reference): array
    {
        // Keep link targets, then drop the markup around them
        $text = preg_replace('#<a\s[^<>]*href=["\']([^"\'<>]*)["\'][^<>]*>#i', ' $1 ', $reference) ?? $reference;
        $text = preg_replace(self::HTML_TAGS, ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Characters often introduced by copying from a PDF
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{2010}-\x{2013}\x{2212}]/u', '-', $text) ?? $text;

        // Percent-encoded DOIs inside resolver links
        $text = preg_replace_callback(
            '#(?<=doi\.org/)10(?:\.|%2E)\S+#i',
            fn (array $m) => rawurldecode($m[0]),
            $text
        ) ?? $text;

        preg_match_all('#(?<![\d.])10\.\d{4,9}(?:\.\d+)*/[^\s"]+#u', $text, $matches);

        $found = [];
        foreach ($matches[0] as $candidate) {
            $doi = $this->trim($candidate);
            if ($doi !== null) {
                $found[strtolower($doi)] ??= $doi;
            }
        }
        return array_values($found);
    }

    /**
     * Remove sentence punctuation, unmatched closing brackets and page suffixes.
     */
    private function trim(string $doi): ?string
    {
        do {
            $before = $doi;
            $doi = preg_replace('#[.,;:\'\x{2019}\x{201D}]+$#u', '', $doi) ?? $doi;
            $doi = preg_replace('#/(?:abstract|epdf|full|pdf)/?$#i', '', $doi) ?? $doi;
            $doi = preg_replace('#[?&](?:utm_[a-z]+|via|download)=.*$#i', '', $doi) ?? $doi;

            $last = substr($doi, -1);
            if (isset(self::CLOSERS[$last]) && substr_count($doi, $last) > substr_count($doi, self::CLOSERS[$last])) {
                $doi = substr($doi, 0, -1);
            }
        } while ($doi !== $before);

        // Something must remain after the prefix
        return preg_match('#^10\.[\d.]+/.+#', $doi) ? $doi : null;
    }
}
