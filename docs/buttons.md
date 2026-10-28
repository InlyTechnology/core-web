# Buttons

`features.buttons` is the agency's button: the `core-web::button` fieldset an
editor fills in, and `Inly\CoreWeb\Buttons\Button::resolve()` turning one into
what renders. What a button looks like, and how a row of them lays out, stays
the site's: its own components draw them, and only the data and the links come
from the package.

## The fieldsets

A blueprint or a block's fieldset imports them like any other:

```yaml
fields:
  - import: 'core-web::buttons'   # a `buttons` grid of buttons
  # or one button in a group:
  - handle: cta
    field:
      type: group
      fields:
        - import: 'core-web::button'
```

The button shows its label, style, what it links to and the link's field
(an entry has a **Suffix**: `?filter=x` or `#contact`, typed as it is
added). Behind **Advanced options** are two icons, opening in a new tab, a
tracker event, a hover title and a screen reader label. A button with a
label needs its link; an empty one, label and all, saves as nothing. Its handles are the agency base's (`label`,
`link_type`, `entry`, `query_string`, `url`, `email`, `tel`, `asset`,
`icon_left`, `icon_right`, `target_blank`, `attr_title`, `attr_aria`,
`tracker_event`), except that the base's `style` is `variant`, so a site's
existing buttons read as before once `buttons:migrate` has run (below).

## Switching a site over

Content saved with the agency base's fieldset names the variant `style`. Run
once, when the feature is switched on:

```bash
php artisan buttons:migrate --dry-run   # what would change
php artisan buttons:migrate
```

It renames `style` to `variant` in every button row of the site's entries and
globals, a row being an array whose `link_type` is one the button offers, so
another field named `style` is left alone; `--dry-run` names each row. The
command is there once `features.buttons` is on. It saves each entry it
changes, so commit the content afterwards. Until it has run, `resolve()` reads the old `style`,
so the front end renders as before; only the CP shows those buttons with the
default variant.

What the fieldset offers is the `buttons` group of `config/core-web.php`, so a
site changes its options there rather than in a copy of the fieldset:

```php
'features' => ['buttons' => true, 'snapshots' => true],

'buttons' => [
    'variants' => ['primary' => 'Primary', 'secondary' => 'Secondary', 'cta' => 'Call to action'],
    'inline' => true,                                   // "Inline": a text link instead of a button
    'link_types' => ['entry', 'url', 'email', 'tel', 'sms', 'asset'],
    'core_links' => [
        'brand' => ['display' => 'Brand', 'snapshot' => 'brands'],
        'campaign' => ['display' => 'Campaign', 'dictionary' => 'core_campaigns'],
    ],
    'entries_across_sites' => true,                     // pick another site's page
],
```

- **variants**: the variants the site's component draws, key => label. One
  variant hides the field. Editors see it as **Style**.
- **inline**: adds **Type** (Button or Inline). The variant applies to a
  button only.
- **link_types**: which of `entry`, `url`, `email`, `tel`, `sms` and `asset`
  are offered. `sms` is off by default.
- **entries_across_sites**: an entry link may pick another site's page, which
  keeps that site's host when it has one of its own. Otherwise an entry links
  its localization on the current site; a draft links nowhere.
- **icons**: `false` drops the two icon fields. An icon is a name from the
  site's icon set; a leading `fa-` is dropped.
- **icon_set**: a folder of SVGs turns the icon fields into Statamic's icon picker, each icon
  previewed. The value stays the file's name, which the site's own icon
  library draws, as SVGs or as a font: for Font Awesome, name the `svgs/<style>`
  folder of the copy committed with the site's font
  (`base_path('public/fonts/fontawesome/svgs/light')`), and the SVGs are only
  the picker's previews. A folder of just the icons the site uses keeps the
  picker short. Null keeps text fields.
- **asset_container**: where an asset link picks from.

A site that truly needs a fieldset of its own keeps a copy at
`resources/fieldsets/vendor/core-web/button.yaml`, which Statamic reads in
place of the package's and the config then no longer changes. The control
panel's fieldset list shows the shipped file rather than the configured
fieldset, so saving `core-web::button` there writes such a copy: don't, unless
that is what you want.

A URL is kept only when it is a web, mail or phone address, a path or an
anchor; anything else (a `javascript:` address) resolves to no link, since
Core AI drafts and content pushes write these fields too.

## Links to Core's pages

A page the site builds from Core (a brand, a campaign, a facility) has no
entry to pick, so each `core_links` key adds a link type of that name, whose
field picks the page's Core object:

- `'snapshot' => 'brands'` picks from a snapshot that declares pages
  (`Snapshot::pages()`, see [Pages](pages.md)): the documents with a page on
  the selected site, by their listing title (`Snapshot::listing()`), stored by
  document id. The link is the document's page on the current site. Needs
  `features.snapshots`: with it off the link type is left out, with a warning
  in the log.
- `'dictionary' => 'core_campaigns'` picks from a Dictionary of the site's
  own, for picks the site already stores its own way; the link is the picked
  item's `url`.

A key is the field's handle, so it cannot be one of the button's own. A pick
whose page is gone (Core no longer publishes it, or it has no page on this
site) resolves to no link, and the button is not drawn.

## Rendering

The site's own components draw the buttons. Its button component takes a row
of the fieldset, resolves it, and renders nothing when the row would render
nothing:

```blade
{{-- resources/views/components/ui/button.blade.php --}}
@props(['button' => null])

@if ($b = \Inly\CoreWeb\Buttons\Button::resolve($button))
    <a href="{{ $b['href'] }}" {{ $attributes->merge($b['attrs'])->class(['btn', 'btn-'.$b['variant']]) }}>{{ $b['label'] }}</a>
@endif
```

A section then writes `<x-ui.button :button="$set['cta']" />` (a value from
the view scope goes through `getValue()` first, since Blade escapes a bound
`Value`), and the site's
row component loops a `buttons` field through the same line, with the row's
layout in its own markup.

`resolve()` returns null for a row with no label, or whose link resolves to
nothing (an entry since deleted or unpublished, a Core page no longer
published). Otherwise it returns `href`, `label`, `variant` (a configured
one, the first otherwise), `inline` (a text link, when
`buttons.inline` is on), `icon_left`, `icon_right`, `link_type` and `attrs`.
`attrs` holds the link's attributes (`target`, `rel`, `title`, `aria-label`,
`download`, the tracker's `onclick`): merging them onto the `<a>` in the
button component means every caller gets them. A site whose views call
`resolveButton()` can keep it as a one-line wrapper of `Button::resolve()`,
with its callers moved to the null return and the `variant` key.

## Tracker events

A tracker event is sent on click while the `seo` global's trackers render in
this environment (`trackers_{environment}`): a `dataLayer` event for Google
Tag Manager, `gtag('event', …)` for Google Analytics, Fathom's
`trackEvent` when `use_fathom` is on. Each call is guarded, since the consent
banner may still hold the tag back, and the name keeps only letters, digits,
`_` and `-`.
