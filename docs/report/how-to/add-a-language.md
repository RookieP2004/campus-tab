# Add a language

Example: shipping Marathi (`mr`).

1. Copy `frontend/src/locales/en/` to `frontend/src/locales/mr/` and translate every value in `common.json`. Keep every key; never translate "Campus TAB".
2. In [`frontend/src/i18n/languages.ts`](../../../frontend/src/i18n/languages.ts) set `shipped: true` on the `mr` entry. (A language not in the list: add an entry with its code, English name, native name, script and `dir`.)
3. Fonts: Latin and Devanagari are bundled. For another script, install its Noto family inside the container (`docker compose exec web npm install @fontsource-variable/noto-sans-<script> -w @campus-tab/frontend`), import it in `frontend/src/main.tsx` and add it to `--ct-font-sans` in [`packages/ui/src/tokens.css`](../../../packages/ui/src/tokens.css).
4. Right-to-left (Urdu): `dir: 'rtl'` mirrors the layout automatically as long as screens use logical classes (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`). Check every screen with the language selected.
5. Run `docker compose exec web npm test -w @campus-tab/frontend`. The test "has a locale file for every shipped language with the same keys as English" fails if a key is missing.
6. Add a `CHANGELOG.md` entry and update the "Languages" part of [frontend.md](../frontend.md).
