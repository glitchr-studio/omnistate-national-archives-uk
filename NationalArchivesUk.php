<?php

namespace Omnistate\NationalArchivesUk;

use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Model\Archive;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecord;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\PartialDate;
use Omnistate\Model\Period;
use Omnistate\Registry\CivilRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery, the catalogue of the UK's National Archives and of some 2,500
 * archives across the country: wills, service records, prisoner-of-war
 * cards, settlement examinations, parish papers... described piece by piece,
 * many of them by the name of the person they are about.
 *
 * It is a catalogue of archives, NOT civil registration: births, marriages
 * and deaths of England and Wales are held by the General Register Office,
 * which has no public API. Every record is therefore of the kind OTHER: a
 * document that names the person, with its reference and who holds it - not
 * an act, no structured person, no picture. A question for births only is
 * not one for it.
 *
 * Free, no key. The API asks for moderation: one call a second, at most
 * 3,000 a day per address.
 */
final class NationalArchivesUk implements CivilRegistryInterface
{
    public const URL = 'https://discovery.nationalarchives.gov.uk/API';
    public const PAGE = 'https://discovery.nationalarchives.gov.uk/details/r/';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly float $timeout = 10,
        private readonly string $url = self::URL,
    ) {
    }

    public function name(): string
    {
        return 'national-archives-uk';
    }

    public function countries(): array
    {
        return ['GB'];
    }

    public function kinds(): array
    {
        return [CivilRecordKind::OTHER];
    }

    public function period(): ?Period
    {
        return null;
    }

    public function hasImages(): bool
    {
        return false;
    }

    public function supports(CivilQuery $query): bool
    {
        return $query->hasName() && $query->inCountries('GB') && $query->wants(CivilRecordKind::OTHER);
    }

    public function search(CivilQuery $query): array
    {
        $when = $query->dated;
        $answer = $this->ask('/search/records', array_filter([
            'sps.searchQuery' => trim($query->name().' '.($query->place ?? '')),
            'sps.dateFrom' => $when?->from?->earliest()?->format('Y-m-d'),
            'sps.dateTo' => $when?->to?->latest()?->format('Y-m-d'),
            'sps.resultsPageSize' => $query->limit,
            'sps.page' => $query->page > 1 ? $query->page - 1 : null,
        ], static fn (mixed $value) => null !== $value && '' !== $value));

        return array_map(fn (array $record) => $this->record($record), $answer['records'] ?? []);
    }

    public function find(string $identifier): ?CivilRecord
    {
        if (!preg_match('/^[\w-]{2,60}$/', $identifier)) {
            return null;
        }
        $answer = $this->ask('/records/v1/details/'.$identifier, [], true);

        return null === $answer || !isset($answer['id']) ? null : $this->record($answer);
    }

    /** @return array<string, mixed>|null null: no such record (find only) */
    private function ask(string $path, array $query, bool $find = false): ?array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->url, '/').$path, ['query' => $query, 'headers' => ['Accept' => 'application/json'], 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            if (429 === $status || $status >= 500) {
                throw new UnavailableException(sprintf('Discovery answered %d.', $status), 429 === $status ? 60 : null);
            }
            if ($find && \in_array($status, [204, 404], true)) {
                return null;
            }
            if ($status >= 400) {
                throw new OmnistateException(sprintf('Discovery refused the question (%d).', $status));
            }
            $body = $response->getContent(false);

            return '' === trim($body) ? ($find ? null : []) : json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new UnavailableException('Discovery did not answer: '.$e->getMessage(), null, $e);
        }
    }

    /**
     * A line of a search or the details of a record: the two do not name
     * their fields alike.
     *
     * @param array<string, mixed> $record
     */
    private function record(array $record): CivilRecord
    {
        $scope = $record['scopeContent']['description'] ?? $record['description'] ?? null;
        $scope = \is_string($scope) ? trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($scope)))) : null;
        $title = trim((string) ($record['title'] ?? '')) ?: $scope;

        $from = self::date($record['numStartDate'] ?? $record['coveringFromDate'] ?? null);
        $to = self::date($record['numEndDate'] ?? $record['coveringToDate'] ?? null);
        // a document of one year is dated by it; a span stays a span
        $date = null !== $from && $from->year === $to?->year ? ((string) $from === (string) $to ? $from : new PartialDate($from->year)) : null;

        $holder = $record['heldBy'][0] ?? null;
        $holder = \is_array($holder) ? ($holder['xReferenceName'] ?? null) : $holder;

        return new CivilRecord(
            identifier: (string) $record['id'],
            kind: CivilRecordKind::OTHER,
            source: $this->name(),
            date: $date,
            archive: new Archive((string) ($holder ?? 'The National Archives'), null, ($record['reference'] ?? $record['citableReference'] ?? null) ?: null, self::PAGE.$record['id']),
            url: self::PAGE.$record['id'],
            title: $title ?: null,
            description: implode(' — ', array_filter([$record['context'] ?? null, $scope !== $title ? $scope : null, ($record['coveringDates'] ?? null) ?: null])) ?: null,
            period: self::span($from, $to),
            raw: $record,
        );
    }

    /** Whole years (1 January to 31 December) are told as years: "1939/1945". */
    private static function span(?PartialDate $from, ?PartialDate $to): ?Period
    {
        if (null === $from && null === $to) {
            return null;
        }
        if (1 === $from?->month && 1 === $from->day && 12 === $to?->month && 31 === $to->day) {
            return Period::years($from->year, $to->year);
        }

        return new Period($from, $to);
    }

    /** 17740101 */
    private static function date(mixed $number): ?PartialDate
    {
        return is_numeric($number) && $number > 0 ? PartialDate::parse(sprintf('%08d', $number)) : null;
    }
}
