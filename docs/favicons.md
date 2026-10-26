# Favicons

`features.favicons` puts the site's icons in the head and answers `/site.webmanifest` and `/favicon.ico`. Every
size is cut from one PNG an editor uploads: by the CDN when the `media` feature serves the file, by Glide
otherwise. Nothing is generated or stored, so a new upload is the new icon everywhere. It replaces Peak Tools'
favicon generation, which writes files with Imagick.

## Setup

1. Switch it on: `'features' => ['favicons' => true]` in `config/core-web.php`.
2. Add a `favicons` global whose blueprint imports the fieldset:

   ```yaml
   # resources/blueprints/globals/favicons.yaml
   tabs:
     main:
       sections:
         - fields:
             - import: core-web::favicons
   ```

   Its asset fields use the `favicons` container. Another global handle goes in `favicons.global`.
3. Render the links in the head, beside `<x-core-web::seo.head>` when `seo` is on:

   ```blade
   <x-core-web::favicons.head />
   ```

## Fields

| Handle | What it is |
| --- | --- |
| `favicon` | A square PNG, at least 512 px. The tab icon (32), the Apple touch icon (180) and the manifest's icons (192, 512) are cut from it. |
| `favicon_svg` | Optional. Browsers that read SVG favicons show it in the tab instead of the PNG. |
| `favicon_svg_dark` | Optional. With `favicon_svg`, the tab icon in dark mode. |
| `theme_color` | Optional. `<meta name="theme-color">` and the manifest's theme and background colour. |

An SVG is never the only source: the CDN passes SVG through untouched rather than turning it into PNGs, and
Apple's touch icon and the manifest need PNGs.

## What it serves

- **The head**: the 32 px PNG with `sizes="32x32"` (so a browser that reads SVG still prefers the SVG), the SVG
  links, the Apple touch icon, the manifest link and the theme colour. A link renders only when its file is set.
- **`/site.webmanifest`**, and `/{path}/site.webmanifest` for a site under a path of a shared host: the site's
  name (the `seo` global's `site_name` when `seo` is on, else `app.name`), `standalone`, the theme colour and the
  192 and 512 px icons.
- **`/favicon.ico`**: a redirect to the default site's 32 px PNG, for the browsers and crawlers that ask for it
  whatever the head says. A real `public/favicon.ico` is served before the route is reached: delete it.

Without the CDN, each size is one Glide render, made once and cached by Glide like any other.
