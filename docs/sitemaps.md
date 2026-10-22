# Sitemaps

A snapshot that declares `->sitemap()` gets `/sitemap-core-{type}.xml` at every
site's address it has URLs on: 404 when it lists nothing there, 503 while
nothing at all can be read (an empty sitemap would read as "every URL is
gone"). A site under a path of a host it shares with other sites
(`https://example.com/second`) has its sitemaps under that path
(`/second/sitemap-core-{type}.xml`, route `core.sitemap.site`), and a `loc`
the snapshot gives as a path is on the site, so it lands under that path too.
List them in robots.txt and the sitemap index with the tag (`site="handle"`
for another site than the current one, as a shared host's one robots.txt
needs):

```antlers
{{ core_sitemaps }}
Sitemap: {{ url }}
{{ /core_sitemaps }}
```
