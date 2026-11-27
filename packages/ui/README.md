# @campus-tab/ui

Shared design tokens, logo files and (from Phase 3) shared components for every Campus TAB screen.

| File | What it is |
| --- | --- |
| `src/tokens.css` | The "Theme tokens" table from `docs/BLUEPRINT.md` as CSS variables (`--ct-*`), light and dark |
| `src/theme.css` | Maps the tokens to Tailwind CSS v4 utilities (`bg-surface`, `text-muted`, `bg-primary-solid`, `bg-status-pending-bg`, …) |
| `src/index.ts` | Brand constants, the eight status names and theme types |
| `assets/logo-mark.svg` | The logo (blue tile, white school outline). Used in both themes inside the app |
| `assets/logo-mark-dark.svg` | White tile with a #0F172A outline, for dark photos and print |
| `assets/favicon.svg` | Favicon (door dropped, thicker lines) |

Use in the app:

```css
@import "tailwindcss";
@import "@campus-tab/ui/tokens.css";
@import "@campus-tab/ui/theme.css";
```

The theme is chosen by `data-theme="light" | "dark"` on `<html>`. Never hard-code colours in components; use the token classes.
