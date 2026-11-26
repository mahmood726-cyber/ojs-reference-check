<?php

namespace APP\plugins\generic\referenceCheck\tests\unit;

use APP\plugins\generic\referenceCheck\classes\DoiExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DoiExtractorTest extends TestCase
{
    public static function references(): array
    {
        $wakefield = '10.1016/S0140-6736(97)11096-0';
        $sici = '10.1002/(SICI)1097-4636(199807)41:1<30::AID-JBM4>3.0.CO;2-X';

        return [
            'no DOI' => ['Smith J. A book. London: Press; 1999.', []],
            'empty' => ['', []],
            'trailing full stop' => ['Nature 2013. doi:10.1038/nature12373.', ['10.1038/nature12373']],
            'resolver link' => ['https://doi.org/10.1038/nature12373', ['10.1038/nature12373']],
            'brackets inside' => ["Lancet. doi: {$wakefield}.", [$wakefield]],
            'wrapped in brackets' => ["(doi:{$wakefield}) ", [$wakefield]],
            'wrapped in square brackets' => ['[10.1038/nature12373]', ['10.1038/nature12373']],
            'percent encoded' => ['https://doi.org/10.1016/S0140-6736%2897%2911096-0', [$wakefield]],
            'angle brackets inside' => ["J Biomed. {$sici}.", [$sici]],
            'wrapped in angle brackets' => ['<https://doi.org/10.1038/nature12373>', ['10.1038/nature12373']],
            'link markup' => [
                '<a href="https://doi.org/10.1038/nature12373">https://doi.org/10.1038/nature12373</a>.',
                ['10.1038/nature12373'],
            ],
            'italic markup' => ['<i>J</i> 10.1038/nature12373</p>', ['10.1038/nature12373']],
            'two DOIs' => ['10.1000/abc; erratum 10.1000/def', ['10.1000/abc', '10.1000/def']],
            'repeated in another case' => ['10.1000/ABC https://doi.org/10.1000/abc', ['10.1000/ABC']],
            'publisher page suffix' => ['https://onlinelibrary.wiley.com/doi/10.1002/jrsm.1411/full', ['10.1002/jrsm.1411']],
            'tracking parameter' => ['https://doi.org/10.1000/abc?utm_source=x', ['10.1000/abc']],
            'soft hyphen from PDF' => ["10.1038/na\u{00AD}ture12373", ['10.1038/nature12373']],
            'en dash from PDF' => ["10.1016/S0140\u{2013}6736(97)11096\u{2013}0", [$wakefield]],
            'dotted prefix' => ['10.1000.10/123', ['10.1000.10/123']],
            'a version number is not a DOI' => ['Software version 210.1234/5', []],
            'prefix alone is not a DOI' => ['see 10.1000/.', []],
        ];
    }

    #[DataProvider('references')]
    public function testExtract(string $reference, array $expected): void
    {
        self::assertSame($expected, (new DoiExtractor())->extract($reference));
    }

    public function testInvalidUtf8DoesNotThrow(): void
    {
        self::assertIsArray((new DoiExtractor())->extract("\xB1\x31 10.1000/abc"));
    }
}
