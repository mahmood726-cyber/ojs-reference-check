<?php

/**
 * @file classes/DoiExtractor.php
 *
 * Copyright (c) 2026 Mahmood Ahmad
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DoiExtractor
 * @brief Find the DOIs written in a free-text reference.
 *
 * The text is written by authors, so every step takes time in proportion to its
 * length.
 */

namespace APP\plugins\generic\referenceCheck\classes;

class DoiExtractor
{
    /** HTML tags that may surround a DOI. Other "<...>" text can be part of a DOI. */
    private const HTML_TAGS = '#</?(?:a|b|br|div|em|i|li|p|span|strong|sub|sup|u)(?:\s[^<>]*)?/?>#i';

    /** Writing that is never taken to continue a DOI, even without a space */
    private const OTHER_SCRIPTS = '\p{Arabic}\p{Cyrillic}\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}'
        . '\x{3000}-\x{303F}\x{FF00}-\x{FF60}';

    private const CLOSERS = [')' => '(', ']' => '[', '}' => '{', '>' => '<'];

    private const PUNCTUATION = ['.', ',', ';', ':', "'", '*', '|', "\u{2019}", "\u{201D}"];

    private const PAGE_SUFFIXES = ['/abstract', '/epdf', '/full', '/pdf'];

    /**
     * @return string[] Unique DOIs (compared without case), in order of appearance
     */
    public function extract(string $reference): array
    {
        preg_match_all(
            '#(?<!\d)10\.\d{4,9}(?:\.\d+)*/[^\s"' . self::OTHER_SCRIPTS . ']+#u',
            $this->normalize($reference),
            $matches
        );

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
     * Whether the reference holds the start of a DOI that is broken off, for
     * example by a space, so that it cannot be looked up.
     */
    public function hasBrokenDoi(string $reference): bool
    {
        return (bool) preg_match(
            '#(?<!\d)10\.\d{4,9}(?:\.\d+)*+(?:\s+/|/(?=\s|$))#u',
            $this->normalize($reference)
        );
    }

    private function normalize(string $reference): string
    {
        // One bad byte must not hide the rest of the reference
        $text = mb_scrub($reference, 'UTF-8');
        // Keep link targets, then drop the markup around them
        $text = preg_replace('#<a\s[^<>]*href=["\']([^"\'<>]*)["\'][^<>]*>#i', ' $1 ', $text) ?? $text;
        $text = preg_replace(self::HTML_TAGS, ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Characters often introduced by copying from a PDF
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{2010}-\x{2013}\x{2212}]/u', '-', $text) ?? $text;

        // Percent-encoded DOIs, as found inside links
        return preg_replace_callback(
            // After a resolver address, or where the slash itself is encoded
            '#(?:(?<=doi\.org/)10(?:\.|%2E)|(?<!\d)10(?:\.|%2E)(?=\d{4,9}+(?:\.\d++)*+%2F))[^\s"]*+#i',
            fn (array $m) => mb_scrub(rawurldecode($m[0]), 'UTF-8'),
            $text
        ) ?? $text;
    }

    /**
     * Remove what follows the DOI: query strings, labels, punctuation, unmatched
     * closing brackets and page suffixes.
     */
    private function trim(string $doi): ?string
    {
        // Query parameters, which can hold personal access tokens
        $doi = preg_replace('#[?&][\w.\-]{1,64}=.*$#s', '', $doi) ?? $doi;
        // A fragment. Some old DOIs legitimately end in "-#".
        $doi = preg_replace('/(?<!-)#.*$/s', '', $doi) ?? $doi;
        // A label written against the DOI, such as "[Epub]"
        $doi = preg_replace('#\[[A-Za-z]{0,30}\]?$#', '', $doi) ?? $doi;

        $count = count_chars($doi, 0);
        $end = strlen($doi);
        while ($end > 0) {
            $last = $doi[$end - 1];
            if (isset(self::CLOSERS[$last])) {
                if ($count[ord($last)] <= $count[ord(self::CLOSERS[$last])]) {
                    break;
                }
                $count[ord($last)]--;
                $end--;
                continue;
            }
            $length = $this->trailingLength($doi, $end);
            if (!$length) {
                break;
            }
            $end -= $length;
        }
        $doi = substr($doi, 0, $end);

        // Something must remain after the prefix
        return preg_match('#^10\.[\d.]+/.#', $doi) ? $doi : null;
    }

    /**
     * Length of the punctuation or page suffix ending at $end, or 0
     */
    private function trailingLength(string $doi, int $end): int
    {
        foreach (self::PUNCTUATION as $mark) {
            $length = strlen($mark);
            if ($end >= $length && substr_compare($doi, $mark, $end - $length, $length) === 0) {
                return $length;
            }
        }
        $slash = $doi[$end - 1] === '/' ? 1 : 0;
        foreach (self::PAGE_SUFFIXES as $suffix) {
            $length = strlen($suffix);
            $start = $end - $slash - $length;
            if ($start >= 0 && substr_compare($doi, $suffix, $start, $length, true) === 0) {
                return $length + $slash;
            }
        }
        return 0;
    }
}
