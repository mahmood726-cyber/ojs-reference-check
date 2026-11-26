<?php

namespace APP\plugins\generic\referenceCheck\tests\unit;

use APP\plugins\generic\referenceCheck\classes\DoiExtractor;
use APP\plugins\generic\referenceCheck\classes\DoiLookup;
use APP\plugins\generic\referenceCheck\classes\HttpFetcher;
use APP\plugins\generic\referenceCheck\classes\ReferenceChecker;
use APP\plugins\generic\referenceCheck\classes\Status;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Awkward and hostile reference text, and answers that could mislead.
 */
class HostileInputTest extends TestCase
{
    private const WAKEFIELD = '10.1016/S0140-6736(97)11096-0';

    /** @var string[] */
    private array $requests = [];
    /** @var float[] */
    private array $pauses = [];

    private function checker(Closure $respond): ReferenceChecker
    {
        $this->requests = [];
        $this->pauses = [];
        $http = new class ($respond, $this->requests) implements HttpFetcher {
            public function __construct(private Closure $respond, private array &$requests) {}

            public function get(string $url): array
            {
                $this->requests[] = $url;
                return ($this->respond)(rawurldecode($url));
            }
        };
        $pause = function (float $seconds) {
            $this->pauses[] = $seconds;
        };
        return new ReferenceChecker(new DoiLookup($http, 90, $pause));
    }

    private static function record(string $doi, ?string $title = null, array $updates = []): array
    {
        return ['DOI' => $doi, 'title' => $title === null ? [] : [$title], 'updated-by' => $updates];
    }

    private static function batch(array ...$items): array
    {
        return ['status' => 200, 'body' => json_encode(['message' => ['items' => $items]])];
    }

    public static function writtenAgainstTheDoi(): array
    {
        $doi = self::WAKEFIELD;
        return [
            'query string' => ["https://journals.sagepub.com/doi/{$doi}?icid=int.sj-abstract.citing-articles.1"],
            'access token' => ["https://onlinelibrary.wiley.com/doi/{$doi}?casa_token=SECRET"],
            'fragment' => ["https://doi.org/{$doi}#bib1"],
            'label' => ["doi:{$doi}[Epub]"],
            'unfinished label' => ["doi:{$doi}[Epub ahead of print]"],
            'markdown' => ["**doi:{$doi}**"],
            'after "DOI."' => ["DOI.{$doi}"],
            'after page numbers' => ["pp637-41.{$doi}"],
            'encoded slash' => ['doi:10.1016%2FS0140-6736(97)11096-0'],
            'encoded in a query' => ['https://example.org/r?doi=10.1016%2FS0140-6736%2897%2911096-0&type=x'],
            'Chinese text' => ["doi:{$doi}\u{3002}\u{67E5}\u{9605}"],
            'Arabic text' => ["doi:{$doi}\u{0627}\u{0646}\u{0638}\u{0631}"],
            'one invalid byte' => ["Wakefield A\xE9. doi:{$doi}"],
        ];
    }

    #[DataProvider('writtenAgainstTheDoi')]
    public function testOnlyTheDoiIsTaken(string $reference): void
    {
        self::assertSame([self::WAKEFIELD], (new DoiExtractor())->extract($reference));
    }

    public function testOldDoiEndingInHashIsKept(): void
    {
        $doi = '10.1002/(SICI)1096-8644(199705)103:1<1::AID-AJPA1>3.0.CO;2-#';
        self::assertSame([$doi], (new DoiExtractor())->extract("Am J. doi:{$doi}"));
    }

    public static function brokenDois(): array
    {
        return [
            'space after slash' => ['doi: 10.1016/ j.jclinepi.2020.01.001', true],
            'space before slash' => ['doi: 10.1016 /j.jclinepi.2020.01.001', true],
            'cut off at the slash' => ['https://doi.org/10.1016/', true],
            'whole DOI' => ['doi:10.1016/j.jclinepi.2020.01.001', false],
            'no DOI' => ['A book. Ratio 10.5 / 2.', false],
        ];
    }

    #[DataProvider('brokenDois')]
    public function testBrokenDoiIsNoticed(string $reference, bool $broken): void
    {
        self::assertSame($broken, (new DoiExtractor())->hasBrokenDoi($reference));
    }

    public function testBrokenDoiIsNotReportedAsAbsentOrClean(): void
    {
        $report = $this->checker(fn () => self::batch(self::record('10.1000/a')))->check([
            'doi: 10.1016/ j.x',
            '10.1000/a and also doi: 10.1016/ j.x',
        ]);

        self::assertSame(Status::UNREADABLE_DOI, $report['references'][0]['status']);
        self::assertSame(Status::UNREADABLE_DOI, $report['references'][1]['status']);
    }

    public static function longInput(): array
    {
        $n = 65000;
        return [
            'closing brackets' => ['10.1000/a' . str_repeat(')', $n)],
            'full stops' => ['10.1000/a' . str_repeat('.', $n) . 'b'],
            'page suffixes' => ['10.1000/a' . str_repeat('/pdf', intdiv($n, 4))],
            'question marks' => ['10.1000/a' . str_repeat('?a', intdiv($n, 2))],
            'brackets' => ['10.1000/a' . str_repeat('[a', intdiv($n, 2))],
            'unclosed tags' => [str_repeat('<a ', intdiv($n, 3)) . '10.1000/a'],
            'unclosed link' => ['<a ' . str_repeat("href='x' ", intdiv($n, 9)) . '10.1000/a'],
            'encoded' => [str_repeat('10.1000%2F', intdiv($n, 10))],
            'spaces after a prefix' => [str_repeat('10.1000' . str_repeat(' ', 500), intdiv($n, 507))],
            'at signs' => ['10.1000/' . str_repeat('a@', intdiv($n, 2))],
        ];
    }

    #[DataProvider('longInput')]
    public function testLongInputIsQuick(string $reference): void
    {
        $start = microtime(true);
        $this->checker(fn () => ['status' => 404, 'body' => '{}'])->check([$reference]);

        // Linear work takes milliseconds; the allowance is for slow machines
        self::assertSame(true, microtime(true) - $start < 2.0);
    }

    public static function unfitToSend(): array
    {
        return [
            'e-mail address' => [self::WAKEFIELD . '.Contact:jane.doe@example.org'],
            'dot segments' => ['10.99999/../10.1038/nature12373'],
            'single dot segment' => ['10.1000/./a'],
            'over long' => ['10.1000/' . str_repeat('a', 600)],
        ];
    }

    #[DataProvider('unfitToSend')]
    public function testSomeStringsNeverLeaveTheServer(string $doi): void
    {
        $report = $this->checker(fn () => self::batch())->check(["doi:{$doi}"]);

        self::assertSame([], $this->requests);
        self::assertSame(Status::UNREADABLE_DOI, $report['references'][0]['status']);
    }

    public static function titles(): array
    {
        return [
            ['RETRACTED: Silibinin inhibits mitogenesis', Status::RETRACTED],
            ['Retracted: Assessment of engagement', Status::RETRACTED],
            ['RETRACTED ARTICLE: A trial', Status::RETRACTED],
            ['WITHDRAWN: Abundance of bacteria', Status::RETRACTED],
            ['[Retracted] A trial', Status::RETRACTED],
            ["Retracted \u{2013} A trial", Status::RETRACTED],
            ['A trial (Retracted)', Status::RETRACTED],
            ['Retracted memories: a study', Status::NO_NOTICE],
            ['Withdrawal symptoms: a study', Status::NO_NOTICE],
            ['Why are papers retracted: a review', Status::NO_NOTICE],
        ];
    }

    #[DataProvider('titles')]
    public function testMarkedTitles(string $title, string $expected): void
    {
        $report = $this->checker(fn () => self::batch(self::record('10.1000/a', $title)))->check(['10.1000/a']);

        self::assertSame($expected, $report['references'][0]['status']);
    }

    public function testMarkedTitleAddsNothingToARetractionNotice(): void
    {
        $record = self::record('10.1000/a', 'RETRACTED: A trial', [['type' => 'retraction']]);
        $report = $this->checker(fn () => self::batch($record))->check(['10.1000/a']);

        self::assertCount(1, $report['references'][0]['dois'][0]['notices']);
    }

    public function testBatchedQueriesAreSpacedForCrossref(): void
    {
        $references = array_map(fn (int $i) => "10.1000/{$i}", range(1, 120));
        $this->checker(function (string $url) {
            preg_match_all('#doi:(10\.1000/\d+)#', $url, $m);
            return self::batch(...array_map(fn ($doi) => self::record($doi), $m[1]));
        })->check($references);

        self::assertCount(3, $this->requests);
        // One filtered query a second is all Crossref allows
        self::assertSame(true, min($this->pauses) > 1.0);
    }

    public function testRepeatedRefusalStopsTheRequests(): void
    {
        $references = array_map(fn (int $i) => "10.1000/{$i}", range(1, 200));
        $report = $this->checker(fn () => ['status' => 403, 'body' => ''])->check($references);

        self::assertSame(true, count($this->requests) <= 4);
        self::assertSame(200, $report['summary'][Status::CHECK_FAILED]);
    }

    public function testReportCanAlwaysBeEncoded(): void
    {
        $report = $this->checker(fn () => self::batch())->check(["bad \xB1\x31 bytes"]);

        self::assertSame(true, is_string(json_encode($report)));
    }
}
