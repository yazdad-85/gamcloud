# Landing + SEO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Public landing at `/` with SEO meta, favicon/logo/OG image, robots.txt, and sitemap for indexable public URLs.

**Architecture:** Shared `partials/seo_head.php` injected by public layout; `Home::index` renders landing for guests; static `robots.txt` + `SeoController::sitemap`; brand assets under `public/assets/brand/`; private layouts get `noindex`.

**Tech Stack:** CI4 views/CSS, SVG brand assets, PNG OG via PHP GD one-shot.

**Spec:** `docs/superpowers/specs/2026-09-09-landing-seo-design.md`

---

### Task 1: Brand assets + seo_head + public layout
### Task 2: Landing page + Home controller + CSS
### Task 3: robots.txt, sitemap route, noindex on private layouts
### Task 4: Verify + commit + push
