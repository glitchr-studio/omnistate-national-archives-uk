<?php

namespace Omnistate\NationalArchivesUk\Tests;

use Omnistate\Exception\UnavailableException;
use Omnistate\Model\CivilQuery;
use Omnistate\Model\CivilRecordKind;
use Omnistate\Model\Period;
use Omnistate\NationalArchivesUk\NationalArchivesUk;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** On answers recorded from discovery.nationalarchives.gov.uk on 2026-10-04. */
final class NationalArchivesUkTest extends TestCase
{
    public function testItIsACatalogueNotCivilRegistration(): void
    {
        $discovery = new NationalArchivesUk(new MockHttpClient());

        self::assertSame('national-archives-uk', $discovery->name());
        self::assertSame(['GB'], $discovery->countries());
        self::assertSame([CivilRecordKind::OTHER], $discovery->kinds());
        self::assertFalse($discovery->hasImages());

        self::assertTrue($discovery->supports(new CivilQuery(familyName: 'Smith', givenName: 'John')));
        self::assertTrue($discovery->supports(new CivilQuery(familyName: 'Smith', country: 'gb')));
        self::assertFalse($discovery->supports(new CivilQuery(familyName: 'Smith', kinds: [CivilRecordKind::BIRTH])), 'it holds no births');
        self::assertFalse($discovery->supports(new CivilQuery(familyName: 'Smith', country: 'US')));
    }

    public function testDocumentsThatNameSomeone(): void
    {
        $asked = null;
        $discovery = new NationalArchivesUk(new MockHttpClient(function (string $method, string $url) use (&$asked) {
            $asked = urldecode($url);

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/search.json'));
        }));
        $records = $discovery->search(new CivilQuery(familyName: 'Smith', givenName: 'John', dated: Period::years(1770, 1780), limit: 3, page: 2));

        self::assertSame('https://discovery.nationalarchives.gov.uk/API/search/records?sps.searchQuery=John Smith&sps.dateFrom=1770-01-01&sps.dateTo=1780-12-31&sps.resultsPageSize=3&sps.page=1', $asked);
        self::assertCount(3, $records);

        $paper = $records[0];
        self::assertSame('28d83883-ca69-45e3-bbdc-c25fb2da0f1b', $paper->identifier);
        self::assertSame(CivilRecordKind::OTHER, $paper->kind);
        self::assertSame('John Smith, parish of birth Shute', $paper->title);
        self::assertSame('1774', (string) $paper->date);
        self::assertSame('Devon Archives and Local Studies Service (South West Heritage Trust)', $paper->archive?->name);
        self::assertSame('3483 A/PO 20/8', $paper->archive?->reference);
        self::assertSame('https://discovery.nationalarchives.gov.uk/details/r/28d83883-ca69-45e3-bbdc-c25fb2da0f1b', $paper->url);
        self::assertSame([], $paper->persons, 'the person is in the title, not in a field');
        self::assertStringContainsString('Settlement Examinations', (string) $paper->description);

        $card = $records[1];
        self::assertSame('C17678834', $card->identifier);
        self::assertNull($card->date, 'a span is not a date');
        self::assertSame('1939/1945', (string) $card->period);
        self::assertSame('The National Archives, Kew', $card->archive?->name);
        self::assertSame('WO 416/335/41', $card->archive?->reference);
    }

    public function testTheDetailsOfARecord(): void
    {
        $discovery = new NationalArchivesUk(new MockHttpClient([
            new MockResponse(file_get_contents(__DIR__.'/Fixtures/details.json')),
            new MockResponse(file_get_contents(__DIR__.'/Fixtures/details-kew.json')),
        ]));

        $paper = $discovery->find('28d83883-ca69-45e3-bbdc-c25fb2da0f1b');
        self::assertSame('John Smith, parish of birth Shute', $paper?->title);
        self::assertSame('3483 A/PO 20/8', $paper->archive?->reference);
        self::assertSame('Devon Archives and Local Studies Service (South West Heritage Trust)', $paper->archive?->name);
        self::assertSame('1774', (string) $paper->date);

        $card = $discovery->find('C17678834');
        self::assertSame('Name: John Smith. Date of Birth: [unspecified].', $card?->title, 'no title of its own: its description, without the markup');
        self::assertSame('WO 416/335/41', $card->archive?->reference);
    }

    public function testNoSuchRecordAnswers204(): void
    {
        $discovery = new NationalArchivesUk(new MockHttpClient(new MockResponse('', ['http_code' => 204])));

        self::assertNull($discovery->find('00000000-0000-0000-0000-000000000000'));
        self::assertNull($discovery->find('not/an/identifier'), 'not asked');
    }

    public function testNothingFound(): void
    {
        $discovery = new NationalArchivesUk(new MockHttpClient(new MockResponse('{"records":[],"count":0,"nextBatchMark":""}')));

        self::assertSame([], $discovery->search(new CivilQuery(familyName: 'zzzzqqqxxnobody')));
    }

    public function testDownIsNotNobody(): void
    {
        $this->expectException(UnavailableException::class);
        (new NationalArchivesUk(new MockHttpClient(new MockResponse('', ['http_code' => 503]))))->search(new CivilQuery(familyName: 'Smith'));
    }
}
