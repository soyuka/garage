# brand

`build.mjs` draws the bonneref logo from the parametric oil-filter profile (Mazda 6 2.0, `src/showcase.json` cluster c1: Ø76, 93, seal Ø71/Ø62) and writes `public/brand/mark.svg`, `mark-static.svg` and `logo.svg`. No mesh is used.

Rerun (tooling lives outside the repo; opentype.js outlines the wordmark):

    mkdir -p /tmp/brand-tools && (cd /tmp/brand-tools && npm i opentype.js)
    BRAND_TOOLS=/tmp/brand-tools BRAND_FONT=/path/IBMPlexSans-Medium.ttf node brand/build.mjs

Font: IBM Plex Sans Medium (OFL), `https://github.com/IBM/plex/raw/master/packages/plex-sans/fonts/complete/ttf/IBMPlexSans-Medium.ttf`. Not committed.
