# omnistate/national-archives-uk

Documents that name a person in [Discovery](https://discovery.nationalarchives.gov.uk), the catalogue
of the UK's National Archives and of some 2,500 archives across the country, for
[glitchr/omnistate](https://github.com/glitchr-studio/omnistate).

**A catalogue of archives, not civil registration.** England and Wales' births, marriages and deaths
are held by the General Register Office, which has no public API. What Discovery answers to a name is
the description of papers that mention it - a will, a service record, a prisoner-of-war card, a
settlement examination - with their reference and who holds them. Every record is of the kind `OTHER`.

```php
$registry = new NationalArchivesUk($httpClient);
$papers = $registry->search(new CivilQuery(familyName: 'Smith', givenName: 'John', dated: Period::years(1770, 1780)));
$papers[0]->title;      // "John Smith, parish of birth Shute"
$papers[0]->archive;    // Devon Archives and Local Studies Service, 3483 A/PO 20/8
$papers[0]->url;        // its page in Discovery
```

| | |
|---|---|
| Countries | GB |
| Kinds | OTHER only (a question for births, marriages or deaths alone is not one for it) |
| Picture of the document | no |
| Persons | not structured: the name is in the title or the description |
| Access | free, no key; moderation asked: one call a second, 3,000 a day per address |

Documentation: [docs/](docs/index.md). License: LGPL-3.0-or-later.
