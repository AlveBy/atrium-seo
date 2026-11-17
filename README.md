# Atrium SEO

SEO for every site and language of [Atrium](https://github.com/AlveBy/atrium):

- titles and descriptions from templates per module, with defaults in 15 languages;
- canonical and hreflang across sites and languages, with `x-default`;
- `sitemap.xml` as an index per language, in parts of 50,000, with `lastmod` and pictures;
- IndexNow pings to Bing and Yandex when a record is saved;
- one schema.org JSON-LD graph per page that any module adds to;
- 301/302/410 redirects with a CSV import, chain and loop detection, and a 404 log;
- an SEO check that lists each problem with a link to the record.

## Install

```bash
composer require alveby/atrium-seo
php artisan atrium:module:install alveby/atrium-seo
```

The panel gets an **SEO** group, and the settings are under **Modules → SEO**. The full guide, with the contract for a module's own markup: [docs/seo.md](https://github.com/AlveBy/atrium/blob/main/docs/seo.md) ([по-русски](https://github.com/AlveBy/atrium/blob/main/docs/seo.ru.md)).

## Works with

- [`alveby/atrium-theme`](https://github.com/AlveBy/atrium/tree/main/packages/theme) draws the pages the tags go into.
- The catalog, news, FAQ, stores and reviews modules get their markup without a line of their own.
- Another module adds its nodes with a `SchemaProvider` and its addresses with a `SitemapSource`, without depending on this package.

## Tests

`Atrium\Seo\Schema\Validator::errors($json)` checks JSON-LD against the schema.org types. Use it in a module's tests to check its markup.

## Contributing

This repository is a read-only copy of `packages/seo` in [AlveBy/atrium](https://github.com/AlveBy/atrium). Issues and pull requests go there.

## License

MIT
