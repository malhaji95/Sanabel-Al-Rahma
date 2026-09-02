# Visual identity

The official identity of سنابل الرحمة, as it is applied in this codebase.

Everything lives behind [`config/brand.php`](../config/brand.php). Nothing in the
application refers to a logo by filename or to a colour by hex, so replacing the
artwork or adjusting the palette is a change to that one file.

## Colours

| Role | Hex | Token |
|---|---|---|
| الأخضر الأساسي | `#2E7D32` | `--brand-primary`, Tailwind `brand-600` |
| الذهبي المساند | `#C9A227` | `--brand-gold`, Tailwind `gold-500` |
| خلفية فاتحة | `#F7F9F4` | `--brand-surface` |
| لون النصوص | `#263238` | `--brand-ink` |

The green and gold each have a full ramp in `tailwind.config.js`, built around
the official value so states (hover, disabled, badges) stay in the family
instead of reaching for a stock Tailwind hue. A test asserts no view or class
hardcodes one of the four hex values.

**One thing to confirm:** the supplied artwork is drawn in `#01674C`, a deeper
green than the stated primary `#2E7D32`, and its gold rule is `#BE8108` rather
than `#C9A227`. The interface uses the stated palette as instructed and the
artwork is used exactly as supplied — so the two sit side by side. Confirm which
is authoritative, or supply artwork drawn in the stated palette.

### Dark mode

Both schemes are first class. The dark scheme restates the same tokens; the
green is lifted to `#5A9A5E` so text and controls stay legible on a dark field,
which is the same hue at an accessible lightness rather than a different colour.

## Typeface

IBM Plex Sans Arabic, weights 400/500/600/700, **self-hosted** from
`public/fonts/`. Self-hosted rather than loaded from a font host because the
delegate field app has to render correctly with no network at all.

The fallback stack is `'Noto Sans Arabic', 'Segoe UI', system-ui, sans-serif`.

## The logo

| Variant | File | Used for |
|---|---|---|
| Full lockup | `public/brand/logo-full.png` | Home page hero, login |
| Name only | `public/brand/logo-wordmark.png` | Site header, panel top bars, footer |
| Symbol | `public/brand/logo-symbol.png` | Favicon, app icons, empty states |

Use `<x-brand.logo>` or `<x-brand.logo-plate>`; never an `<img>` pointing at a
brand file directly. The component sets height and lets width follow the
artwork, so the lockup cannot be stretched, and it carries the clear space the
guide requires.

```blade
<x-brand.logo-plate variant="full" :height="200" />
<x-brand.logo variant="symbol" :height="36" />
```

### Where the full lockup is not used

The supplied lockup is vertical: mark above name above rule above tagline. A
site header or a panel top bar is roughly 56px tall, which is not room for it —
rendered that small it becomes an unreadable smudge. Those places use the
name-only version, which is what the guide asks for in tight space. A
**horizontal lockup** is the missing piece; supply it and the header can carry
the full logo.

## Assets that are still provisional

The handover describes a package — SVG, name-only, symbol, horizontal and
vertical lockups, colour, white and mono versions, favicons. What arrived was a
single print PDF containing the full colour lockup as a raster image.

Everything under `public/brand/` is therefore derived from that one file by
[`scripts/brand-derive.php`](../scripts/brand-derive.php), using mechanical
operations only:

- a crop to the bands the artwork already separates
- lifting the flat white backdrop to transparency, un-premultiplying edge pixels
  so the colours stay exactly as drawn

Nothing is redrawn, recoloured, reproportioned or restyled. A test asserts the
variants are crops rather than resizes.

**Two rules cannot be met until the package arrives:**

1. **The white version on dark or green backgrounds.** The colour artwork is
   unreadable on the brand green. Producing a white version would mean
   recolouring approved artwork, so instead the logo keeps a light field in dark
   mode. Set `brand.logo.on_dark` once the white file exists and
   `components/brand/logo-plate.blade.php` can drop the field.
2. **SVG.** Vectorising the raster would mean redrawing the mark. The variants
   are high-resolution PNGs until real SVGs are supplied.

When the package arrives: drop the files into `public/brand/`, point
`config/brand.php` at them, and delete `scripts/brand-derive.php`.

## Applying the identity

- **Buttons** — `.btn-primary` (green), `.btn-gold` (the supporting accent, one
  call to action at a time), `.btn-secondary`, `.btn-ghost`
- **Surfaces** — `.card`, `.card-interactive`
- **Forms** — `.field`, `.field-label`
- **Feedback** — `.alert-success`, `.alert-warning`, `.alert-danger`, `.badge`
- **Progress** — `.meter` with `.meter-fill`; a complete bar turns gold
- **Dividers** — `.rule-gold`, echoing the gold rule in the lockup

All of them are defined once in `resources/css/app.css` against the tokens above.

## Checks

`tests/Feature/BrandTest.php` covers: every variant and icon ships; icons are
square and derived from the symbol; variants are unscaled crops; the palette
matches the guide; the typeface is served from our own origin with no outside
host; pages render right-to-left with the correct logo and favicon; the header
uses the name-only version; the artwork is never recoloured onto a dark field;
the field manifest and service worker carry the brand icons and fonts; all three
panels are branded; and no view hardcodes a brand hex value.
