# Landing Match Login Implementation Plan

> **For agentic workers:** Implement task-by-task. Steps use checkbox syntax.

**Goal:** Make `/` use the same visual shell as `/login`, with an action card instead of a login form.

**Architecture:** Reuse existing `login-landing` CSS/markup patterns in `public/landing.php`; drop the separate light `landing-*` page chrome for guests; add CSS cache-bust on the public layout.

**Tech Stack:** CodeIgniter 4 views, `public/assets/app.css`, PlatformSettingsService branding.

---

### Task 1: Spec + failing/asserting test

- [ ] Extend `HomeLandingTest` to assert landing view contains `login-landing` and CTA paths `/daftar-guru`, `/login`, `/join`.

### Task 2: Rewrite landing view + Home body classes

- [ ] Rewrite `app/Views/public/landing.php` to shell A.
- [ ] Update `Home::index` to drop `landing-body` / `landing-page` (match login public page).
- [ ] Prefer branding `seo_description` when rendering meta (via existing seo_head + optional controller pass).

### Task 3: CSS + cache bust

- [ ] Add `.landing-actions` styles for CTA stack in the card.
- [ ] In `layouts/public.php`, load `/assets/app.css?v=` + `filemtime`.

### Task 4: Verify + commit

- [ ] Run PHPUnit; commit and push.
