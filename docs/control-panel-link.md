# Control panel link

`features.cp_link` adds a link to Core right under Dashboard in the control
panel, for every CP user, opened in a new tab so unsaved CP work stays open.
It points at `client.base_url` (`CORE_URL`); without one there is no link.

The site names the link and may pick its icon, in its own
`config/core-web.php`; the icon is a Statamic icon name or an SVG, and null
keeps Core's mark:

```php
return [
    'features' => ['cp_link' => true],
    'cp_link' => ['label' => 'Acme Core', 'icon' => null],
];
```
