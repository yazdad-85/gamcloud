# Projector Game Audio Phase 1 Implementation Plan

> **For agentic workers:** Implement task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire projector event cues + tension timer + mute via Web Audio in `game-fx.js` / `app.js`.

**Architecture:** Extend existing synth `SOUND_LIBRARY` with public `GameFx.sound.*` API (play/mute/tension). Projector countdown and sequenced overlays call the API. No backend changes.

**Tech Stack:** Vanilla JS, Web Audio API, CodeIgniter projector view/CSS.

---

### Task 1: Sound API + cues + tension + mute

**Files:** `public/assets/game-fx.js`

- [ ] Add mute flag + localStorage `edugame.projector.muted`
- [ ] Add cues: `ladder`, `snake`, `shield`, `points` (+ aliases bonus/trap/safe)
- [ ] Export `play`, `startTension`, `stopTension`, `setMuted`, `isMuted`
- [ ] Gate `playSoundSafe` on mute; idempotent tension loop

### Task 2: Projector UI mute

**Files:** `app/Views/game/projector.php`, `public/assets/game-fx.css` (or `app.css`)

- [ ] Mute toggle button next to unlock / in side panel
- [ ] Init muted state from localStorage after unlock

### Task 3: Wire projector countdown tension + event cues

**Files:** `public/assets/app.js`

- [ ] On countdown ≤10s with active question → `startTension`; else `stopTension`
- [ ] Map snake/ladder started/resolved banners to `play('snake'|'ladder'|...)`
- [ ] Ensure tile/correct/wrong/winner paths still work via GameFx (already play internal)

### Task 4: Verify + commit

- [ ] Manual sanity: unlock → events sound; mute silent; tension stops
- [ ] Commit + push
