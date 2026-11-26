<?php

namespace APP\plugins\generic\referenceCheck\tests\unit;

use APP\plugins\generic\referenceCheck\classes\DoiLookup;
use APP\plugins\generic\referenceCheck\classes\HttpFetcher;
use APP\plugins\generic\referenceCheck\classes\ReferenceChecker;
use APP\plugins\generic\referenceCheck\classes\Status;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReferenceCheckerTest extends TestCase
{
    /** @var string[] */
    private array $requests = [];

    private function checker(Closure $respond, int $budget = 90, ?Closure $clock = null): ReferenceChecker
    {
        $this->requests = [];
        $http = new class ($respond, $this->requests) implements HttpFetcher {
            public function __construct(private Closure $respond, private array &$requests) {}

            public function get(string $url): array
            {
                $this->requests[] = $url;
                return ($this->respond)(rawurldecode($url));
            }
        };
        return new ReferenceChecker(new DoiLookup($http, $budget, fn () => null, $clock));
    }

    private static function json(array $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($data)];
    }

    private static function batch(array ...$items): array
    {
        return self::json(['message' => ['items' => $items]]);
    }

    private static function work(string $doi, string ...$updateTypes): array
    {
        $item = ['DOI' => $doi];
        foreach ($updateTypes as $type) {
            $item['updated-by'][] = [
                'type' => $type,
                'DOI' => '10.1000/notice',
                'source' => 'retraction-watch',
                'updated' => ['date-time' => '2010-02-06T00:00:00Z'],
            ];
        }
        return $item;
    }

    public static function updateTypes(): array
    {
        return [
            ['retraction', Status::RETRACTED],
            ['Retraction', Status::RETRACTED],
            ['partial_retraction', Status::RETRACTED],
            ['withdrawal', Status::RETRACTED],
            ['removal', Status::RETRACTED],
            ['expression_of_concern', Status::CONCERN],
            ['expression-of-concern', Status::CONCERN],
            ['correction', Status::CORRECTED],
            ['Erratum', Status::CORRECTED],
            ['new_version', Status::NOTICE],
            ['something_new', Status::NOTICE],
        ];
    }

    #[DataProvider('updateTypes')]
    public function testNoticeTypes(string $type, string $expected): void
    {
        $report = $this->checker(fn () => self::batch(self::work('10.1000/a', $type)))->check(['10.1000/a']);

        self::assertSame($expected, $report['references'][0]['status']);
        self::assertSame($type, $report['references'][0]['dois'][0]['notices'][0]['type']);
    }

    public function testRetractionOutranksCorrectionAndKeepsBoth(): void
    {
        $report = $this->checker(fn () => self::batch(self::work('10.1000/a', 'correction', 'retraction')))
            ->check(['10.1000/A']);

        self::assertSame(Status::RETRACTED, $report['references'][0]['status']);
        self::assertCount(2, $report['references'][0]['dois'][0]['notices']);
        self::assertSame('2010-02-06', $report['references'][0]['dois'][0]['notices'][0]['date']);
    }

    public function testNoNoticeAndNoDoiAreCountedSeparately(): void
    {
        $report = $this->checker(fn () => self::batch(self::work('10.1000/a')))
            ->check(['10.1000/a', 'A book without a DOI']);

        self::assertSame(1, $report['summary'][Status::NO_NOTICE]);
        self::assertSame(1, $report['summary'][Status::NO_DOI]);
        self::assertSame(2, $report['total']);
    }

    public function testEmptyListSendsNothing(): void
    {
        $report = $this->checker(fn () => self::batch())->check([]);

        self::assertSame(0, $report['total']);
        self::assertSame([], $this->requests);
    }

    public function testDoisAreBatchedAndRepeatsAskedOnce(): void
    {
        $references = array_map(fn (int $i) => "10.1000/{$i}", range(1, 90));
        $references[] = '10.1000/1';

        $report = $this->checker(function (string $url) {
            preg_match_all('#doi:(10\.1000/\d+)#', $url, $m);
            return self::batch(...array_map(fn ($doi) => self::work($doi), $m[1]));
        })->check($references);

        self::assertCount(3, $this->requests);
        self::assertSame(91, $report['summary'][Status::NO_NOTICE]);
    }

    public static function registryAnswers(): array
    {
        return [
            'registered elsewhere' => [['RA' => 'DataCite'], Status::NOT_IN_CROSSREF],
            'does not exist' => [['status' => 'DOI does not exist'], Status::DOI_NOT_FOUND],
            'invalid' => [['status' => 'Invalid DOI'], Status::DOI_NOT_FOUND],
            'registry error' => [['status' => 'Error'], Status::CHECK_FAILED],
            'unexpected body' => [['other' => true], Status::CHECK_FAILED],
        ];
    }

    #[DataProvider('registryAnswers')]
    public function testDoiNotReturnedByCrossref(array $answer, string $expected): void
    {
        $report = $this->checker(fn (string $url) => str_starts_with($url, DoiLookup::REGISTRIES)
            ? self::json([['DOI' => '10.5281/ZENODO.1'] + $answer])
            : self::batch())->check(['10.5281/zenodo.1']);

        self::assertSame($expected, $report['references'][0]['status']);
        self::assertCount(2, $this->requests);
    }

    public function testManyOtherRegistryDoisTakeFewRequests(): void
    {
        $references = array_map(fn (int $i) => "10.5281/zenodo.{$i}", range(1, 120));
        $report = $this->checker(function (string $url) {
            if (!str_starts_with($url, DoiLookup::REGISTRIES)) {
                return self::batch();
            }
            $dois = explode(',', substr($url, strlen(DoiLookup::REGISTRIES)));
            return self::json(array_map(fn ($doi) => ['DOI' => $doi, 'RA' => 'DataCite'], $dois));
        })->check($references);

        self::assertCount(6, $this->requests);
        self::assertSame(120, $report['summary'][Status::NOT_IN_CROSSREF]);
    }

    public function testDoiLeftOutOfTheRegistryAnswerIsNotClean(): void
    {
        $report = $this->checker(fn (string $url) => str_starts_with($url, DoiLookup::REGISTRIES)
            ? self::json([])
            : self::batch())->check(['10.1000/a']);

        self::assertSame(Status::CHECK_FAILED, $report['references'][0]['status']);
    }

    public static function handleAnswers(): array
    {
        return [
            'registered elsewhere' => [200, ['responseCode' => 1], Status::NOT_IN_CROSSREF],
            'exists without URL' => [200, ['responseCode' => 200], Status::NOT_IN_CROSSREF],
            'does not exist' => [404, ['responseCode' => 100], Status::DOI_NOT_FOUND],
            'resolver error' => [200, ['responseCode' => 2], Status::CHECK_FAILED],
            'unexpected body' => [200, ['other' => true], Status::CHECK_FAILED],
        ];
    }

    #[DataProvider('handleAnswers')]
    public function testDoiWithCommaUnknownToCrossref(int $status, array $body, string $expected): void
    {
        $report = $this->checker(fn (string $url) => str_starts_with($url, DoiLookup::HANDLES)
            ? self::json($body, $status)
            : ['status' => 404, 'body' => 'Resource not found.'])->check(['https://doi.org/10.5281/a,b']);

        self::assertSame($expected, $report['references'][0]['status']);
    }

    public function testDoiWithCommaIsLookedUpAlone(): void
    {
        $report = $this->checker(fn () => self::json(['message' => self::work('10.1000/a,b', 'retraction')]))
            ->check(['https://doi.org/10.1000/a,b']);

        self::assertSame(Status::RETRACTED, $report['references'][0]['status']);
        self::assertSame([DoiLookup::CROSSREF . '/10.1000%2Fa%2Cb'], $this->requests);
    }

    public function testRejectedBatchFallsBackToSingleLookups(): void
    {
        $report = $this->checker(function (string $url) {
            if (str_contains($url, 'filter=')) {
                return ['status' => 400, 'body' => '{}'];
            }
            return str_starts_with($url, DoiLookup::REGISTRIES)
                ? self::json([['DOI' => '10.1000/a', 'RA' => 'Crossref']])
                : self::json(['message' => self::work('10.1000/a', 'retraction')]);
        })->check(['10.1000/a']);

        self::assertSame(Status::RETRACTED, $report['references'][0]['status']);
    }

    public static function failures(): array
    {
        return [
            'network error' => [fn () => throw new RuntimeException('offline')],
            'server error' => [fn () => ['status' => 503, 'body' => '']],
            'rate limited' => [fn () => ['status' => 429, 'body' => '']],
            'forbidden' => [fn () => ['status' => 403, 'body' => '<html></html>']],
            'HTML instead of JSON' => [fn () => ['status' => 200, 'body' => '<html></html>']],
            'malformed notices' => [fn () => self::batch(['DOI' => '10.1000/a', 'updated-by' => 'x'])],
        ];
    }

    #[DataProvider('failures')]
    public function testFailuresNeverLookClean(Closure $respond): void
    {
        $report = $this->checker($respond)->check(['10.1000/a']);

        self::assertSame(Status::CHECK_FAILED, $report['references'][0]['status']);
        self::assertSame(0, $report['summary'][Status::NO_NOTICE]);
    }

    public function testOneFailedAttemptIsRetried(): void
    {
        $calls = 0;
        $report = $this->checker(function () use (&$calls) {
            return ++$calls === 1 ? ['status' => 429, 'body' => ''] : self::batch(self::work('10.1000/a'));
        })->check(['10.1000/a']);

        self::assertSame(Status::NO_NOTICE, $report['references'][0]['status']);
    }

    public function testStopsAskingAfterRepeatedFailures(): void
    {
        $references = array_map(fn (int $i) => "10.1000/{$i}", range(1, 200));
        $report = $this->checker(fn () => throw new RuntimeException('offline'))->check($references);

        self::assertCount(6, $this->requests);
        self::assertSame(200, $report['summary'][Status::CHECK_FAILED]);
    }

    public function testTimeBudget(): void
    {
        $now = 0.0;
        $clock = function () use (&$now) {
            return $now;
        };
        $references = array_map(fn (int $i) => "10.1000/{$i}", range(1, 80));

        $report = $this->checker(function (string $url) use (&$now) {
            $now += 100;
            preg_match_all('#doi:(10\.1000/\d+)#', $url, $m);
            return self::batch(...array_map(fn ($doi) => self::work($doi), $m[1]));
        }, 90, $clock)->check($references);

        self::assertSame(40, $report['summary'][Status::NO_NOTICE]);
        self::assertSame(40, $report['summary'][Status::CHECK_FAILED]);
    }

    public function testDoisOverTheLimitAreReportedNotDropped(): void
    {
        $references = array_map(fn (int $i) => "10.1000/{$i}", range(1, ReferenceChecker::MAX_DOIS + 3));
        $report = $this->checker(function (string $url) {
            preg_match_all('#doi:(10\.1000/\d+)#', $url, $m);
            return self::batch(...array_map(fn ($doi) => self::work($doi), $m[1]));
        })->check($references);

        self::assertSame(3, $report['summary'][Status::CHECK_FAILED]);
        self::assertSame(ReferenceChecker::MAX_DOIS + 3, $report['total']);
    }
}
