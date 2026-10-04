# omnistate/national-archives-uk

## Installation

```sh
composer require glitchr/omnistate omnistate/national-archives-uk
```

In a Symfony application, `OmnistateBundle` registers it when it is installed; nothing to configure.

## What is asked

`GET https://discovery.nationalarchives.gov.uk/API/search/records`:

| Query | Parameter |
|---|---|
| `givenName`, `familyName`, `place` | `sps.searchQuery` (full text) |
| `dated` | `sps.dateFrom`, `sps.dateTo` (`YYYY-MM-DD`): the dates the document covers |
| `limit`, `page` | `sps.resultsPageSize`, `sps.page` (from 0) |

`born`, `died`, `sex` and `country` are not searched on. `find($id)` asks `/API/records/v1/details/{id}`.

## What comes back

A `CivilRecord` of kind `OTHER`: `title` (the piece's title, else its description), `description`
(its place in the catalogue, the covering dates), `date` when the piece is of one year, `period` when
it covers a span, `archive` (the holder and the reference), `url`. `persons` is empty and `images` too.

## Errors

- HTTP 429 or 5xx, no answer: `UnavailableException`.
- HTTP 204 on the details: no such record, `null`.

## Verified

Against the real service on 2026-10-04: a search by name, with dates, with no result; the details of
a record of the National Archives and of a local archive; an unknown record (204). The fixtures in
`Tests/Fixtures/` are its answers. Paging (`sps.page`) was not tried.
