# Design Spec — Public Landing + SEO Basics

**Tanggal:** 2026-09-09  
**Status:** Approved (user chose approach A / implementation option 1)  
**Site:** `https://edugame.cloudedu.id`

## Problem

- Layouts only set `<title>`; no description / Open Graph / Twitter cards.
- `/` redirects to `/teacher` (auth-gated) → weak Google indexing and poor link previews when the root URL is shared.
- No favicon or default OG image / logo assets.

## Goals

1. Public marketing landing at `/` for brand + SEO.
2. Shareable URL shows title, description, and preview image (WhatsApp/Telegram/Facebook/Google).
3. Favicon + simple brand mark present site-wide on public pages.
4. Private app areas remain `noindex`.

## Non-goals

- Full multi-section marketing site.
- Blog, blog CMS, or per-room public SEO pages.
- Changing teacher/game auth flows beyond optional logged-in redirect from `/`.

## Approach

**Landing ringan + SEO partial** (approved).

---

## 1. Routing & Home

| Path | Behavior |
|------|----------|
| `GET /` | Render public landing view (no longer blind redirect to `/teacher`) |
| Optional | If `auth()->loggedIn()`, redirect to `/teacher` (or `/superadmin` if applicable) |

Controllers: update `Home::index` to return landing view for guests.

## 2. Landing content (first viewport)

One composition, brand-first:

- Brand name: **Ular Tangga Edukatif** (hero-level)
- One headline (e.g. kuis kelas berbasis papan ular tangga)
- One short supporting sentence
- CTA group: **Login Guru**, **Daftar Guru**, **Join Tim**
- Dominant visual: atmosphere background (gradient/pattern) + simple board/game motif (CSS/SVG — not a card collage)

Avoid: dashboard chrome, stat strips, purple-default AI look, cream+terracotta cliché.

Subsequent short section (below fold OK): 3 benefit lines max (interaktif, bank soal guru, proyektor kelas) — one purpose.

## 3. SEO meta (public layouts)

Add shared partial or helpers used by `layouts/public.php` and landing (and login if it uses its own layout):

| Tag | Source |
|-----|--------|
| `<title>` | Per-page + site suffix |
| `meta name="description"` | Per-page |
| `link rel="canonical"` | Absolute URL from `baseURL` + path |
| `og:title`, `og:description`, `og:type`, `og:url`, `og:image`, `og:site_name`, `og:locale` | `id_ID` |
| `twitter:card` = `summary_large_image` | + title/description/image |
| `link rel="icon"` | favicon SVG + ICO fallback |

Default copy (landing):

- Title: `Ular Tangga Edukatif — Kuis Kelas Interaktif`
- Description: short Indonesian blurb (~140–160 chars) about teachers running snake-and-ladder quiz games in class.

Per-page overrides via view data: `$seoTitle`, `$seoDescription`, `$seoImage` (optional).

## 4. robots & sitemap

- `public/robots.txt` (or route):
  - `Allow` public paths
  - `Disallow: /teacher`, `/superadmin`, `/game/`, `/api/`
  - `Sitemap: https://edugame.cloudedu.id/sitemap.xml`
- `GET /sitemap.xml` listing: `/`, `/login`, `/join`, `/daftar-guru` (absolute URLs from `baseURL`)

## 5. Indexing policy

| Area | robots |
|------|--------|
| Landing, login, join, daftar-guru | `index,follow` |
| teacher/*, superadmin/*, projector, controller | `noindex,nofollow` |

## 6. Brand assets (MVP)

Place under `public/assets/brand/`:

| File | Role |
|------|------|
| `favicon.svg` | Primary favicon (simple mark: ladder/snake or “UT” monogram) |
| `favicon.ico` | Optional fallback if easy; else SVG-only OK for modern browsers |
| `logo.svg` | Header brand mark (can match favicon + wordmark in HTML) |
| `og-default.png` | 1200×630 Open Graph image (brand + product name) |

Assets may be generated as clean SVG + a simple PNG OG image for MVP; replaceable later without code changes (same paths).

## 7. Files (expected)

| File | Change |
|------|--------|
| `app/Controllers/Home.php` | Landing / optional auth redirect |
| `app/Views/public/landing.php` | New landing |
| `app/Views/layouts/public.php` | SEO head partial + favicon |
| `app/Views/partials/seo_head.php` | Shared meta tags |
| `app/Views/auth/login.php` / layout | Ensure SEO meta if separate |
| `app/Controllers/SeoController.php` or static | sitemap (and robots if routed) |
| `public/robots.txt` | Crawl rules |
| `public/assets/brand/*` | Favicon, logo, OG image |
| `public/assets/app.css` | Landing styles |
| Teacher/projector/controller layouts | `noindex` |

## 8. Success criteria

- Sharing `https://edugame.cloudedu.id/` shows title + description + image preview.
- Google can discover `/` as a real document (not only auth redirect).
- Browser tab shows favicon.
- Logged-in teacher still reaches app easily (CTA or auto-redirect).
- Private routes not advertised in sitemap.

## Deploy note

After ship: `git pull`, ensure `app.baseURL` is `https://edugame.cloudedu.id/`, submit sitemap in Google Search Console (manual ops).
