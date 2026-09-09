# Platform Settings + Profiles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans.

**Goal:** Superadmin platform branding settings + superadmin/teacher profile pages with password change.

**Architecture:** `platform_settings` key-value table; `teachers.school_name`; `PlatformSettingsService` for get/set/upload; controllers under Superadmin/Teacher; layouts/SEO consume branding with fallbacks.

**Tech Stack:** CI4, Shield, PHPUnit DatabaseTestTrait.

**Spec:** `docs/superpowers/specs/2026-09-09-platform-settings-profiles-design.md`

---

### Task 1: Migration + PlatformSettingsService (TDD)
### Task 2: Superadmin settings + profile UI
### Task 3: Teacher profile UI + nav links
### Task 4: Wire branding into seo_head/landing/layouts
### Task 5: Full tests + commit + push
