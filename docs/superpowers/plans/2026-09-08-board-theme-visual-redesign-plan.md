# Board Theme Visual Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the six existing board themes visually distinct (not just different flat colors) by adding a per-theme icon watermark, a thin icon overlay on some ordinary tiles, per-theme tile shape/border variation, and an accent-colored glow on player pieces.

**Architecture:** Pure client-side change (CSS + `public/assets/app.js`). No backend, database, or PHP logic is touched. Theme color data (`theme.palette`) and the theme key (`theme.key`) already flow from `GameEngine::publicTheme()` into the snapshot and are already applied as CSS custom properties + a `data-theme` attribute by the existing `applyBoardTheme()` function — this plan only adds one new custom property (`--board-icon`) and new CSS rules that key off what's already there.

**Tech Stack:** Vanilla JS (`public/assets/app.js`, single IIFE, no build step, no bundler), plain CSS (`public/assets/app.css`). No test runner exists for this layer — verification is `node --check`, `php -l` (for any view touched), and manual browser screenshots.

**Spec:** `docs/superpowers/specs/2026-09-08-board-theme-visual-redesign-design.md`

**Working directory for every command below:** `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`

---

### Task 1: Ikon SVG per tema + custom property `--board-icon`

**Files:**
- Modify: `public/assets/app.js` (add `THEME_ICON_SHAPES`, `themeIconMaskUrl()`, wire into `applyBoardTheme()`)

- [ ] **Step 1: Add the icon shape lookup and mask-URL helper**

In `public/assets/app.js`, find `function applyBoardTheme(element, theme) {` (currently at line 129) and insert this new code directly ABOVE it:

```javascript
    const THEME_ICON_SHAPES = {
        classic_arena: '<path d="M12 2 20 5v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5Z"/>',
        jungle_quest: '<path d="M12 2C4 6 4 14 4 20 10 20 20 14 20 4 16 4 14 3 12 2Z"/>',
        space_mission: '<path d="M12 2 14 10 22 12 14 14 12 22 10 14 2 12 10 10Z"/>',
        ocean_quest: '<path d="M2 15c3-4 5-4 8 0s5 4 8 0 5-4 4 0" fill="none" stroke="black" stroke-width="2.4" stroke-linecap="round"/>',
        city_challenge: '<path d="M3 21V10H8V21M10 21V4H15V21M17 21V13H21V21" fill="none" stroke="black" stroke-width="2"/>',
        lab_challenge: '<path d="M9 2h6v6l5 12c.8 1.8-.5 3-2.4 3H6.4C4.5 23 3.2 21.8 4 20l5-12Z"/>',
    };

    function themeIconMaskUrl(themeKey) {
        const shape = THEME_ICON_SHAPES[themeKey] || THEME_ICON_SHAPES.classic_arena;
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' + shape + '</svg>';

        return 'url("data:image/svg+xml,' + encodeURIComponent(svg) + '")';
    }

```

- [ ] **Step 2: Wire the new property into `applyBoardTheme()`**

Immediately below the code you just added, find the existing `applyBoardTheme` function:

```javascript
    function applyBoardTheme(element, theme) {
        const palette = (theme && theme.palette) || {};
        element.dataset.theme = theme && theme.key ? theme.key : 'classic_arena';
        Object.entries({
            '--board-bg-a': palette.board || '#10251f',
            '--board-bg-b': palette.board2 || '#172033',
            '--board-tile-a': palette.tileA || '#f8fafc',
            '--board-tile-b': palette.tileB || '#e0f2fe',
            '--board-accent': palette.accent || '#f97316',
            '--board-snake': palette.snake || '#22c55e',
            '--board-ladder': palette.ladder || '#facc15',
        }).forEach(([key, value]) => element.style.setProperty(key, value));
    }
```

Replace it with:

```javascript
    function applyBoardTheme(element, theme) {
        const palette = (theme && theme.palette) || {};
        element.dataset.theme = theme && theme.key ? theme.key : 'classic_arena';
        Object.entries({
            '--board-bg-a': palette.board || '#10251f',
            '--board-bg-b': palette.board2 || '#172033',
            '--board-tile-a': palette.tileA || '#f8fafc',
            '--board-tile-b': palette.tileB || '#e0f2fe',
            '--board-accent': palette.accent || '#f97316',
            '--board-snake': palette.snake || '#22c55e',
            '--board-ladder': palette.ladder || '#facc15',
            '--board-icon': themeIconMaskUrl(theme && theme.key),
        }).forEach(([key, value]) => element.style.setProperty(key, value));
    }
```

- [ ] **Step 3: Verify JS syntax**

Run: `node --check public/assets/app.js`
Expected: no output, exit code 0 (valid syntax).

- [ ] **Step 4: Manual sanity check of the generated URL**

Run this one-off Node script to confirm `themeIconMaskUrl` would produce a well-formed value (this doesn't run inside a browser DOM, so we just eval the two new top-level declarations in isolation):

```bash
node -e '
const THEME_ICON_SHAPES = {
    classic_arena: "<path d=\"M12 2 20 5v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5Z\"/>",
};
function themeIconMaskUrl(themeKey) {
    const shape = THEME_ICON_SHAPES[themeKey] || THEME_ICON_SHAPES.classic_arena;
    const svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\">" + shape + "</svg>";
    return "url(\"data:image/svg+xml," + encodeURIComponent(svg) + "\")";
}
console.log(themeIconMaskUrl("classic_arena").slice(0, 60) + "...");
'
```
Expected: prints something starting with `url("data:image/svg+xml,%3Csvg...` — confirms the encoding produces a valid-looking data URI (no raw `<`/`>`/`"` characters leaking unencoded into the string).

- [ ] **Step 5: Commit**

```bash
git add public/assets/app.js
git commit -m "feat: add per-theme icon shapes and a CSS mask-image URL helper"
```

---

### Task 2: Tekstur latar belakang papan

**Files:**
- Modify: `public/assets/app.css` (add `.board::before`, add `z-index` to `.board-grid`)

- [ ] **Step 1: Give `.board-grid` a z-index so it stacks above the new texture layer**

Find (`public/assets/app.css`, currently at line 962):

```css
.board-grid {
    display: grid;
    gap: 4px;
    grid-template-columns: repeat(10, minmax(0, 1fr));
    height: 100%;
    position: relative;
    width: 100%;
}
```

Replace with:

```css
.board-grid {
    display: grid;
    gap: 4px;
    grid-template-columns: repeat(10, minmax(0, 1fr));
    height: 100%;
    position: relative;
    width: 100%;
    z-index: 1;
}
```

- [ ] **Step 2: Add the background texture layer**

Find the closing `}` of the `.board { ... }` rule (currently ends at line 960, immediately before `.board-grid`), and insert this new rule right after it (before `.board-grid`):

```css
.board::before {
    background-color: var(--board-accent, #f97316);
    content: "";
    inset: 0;
    -webkit-mask-image: var(--board-icon);
    mask-image: var(--board-icon);
    -webkit-mask-repeat: repeat;
    mask-repeat: repeat;
    -webkit-mask-size: 46px 46px;
    mask-size: 46px 46px;
    opacity: .07;
    pointer-events: none;
    position: absolute;
    z-index: 0;
}
```

- [ ] **Step 3: Verify the CSS file's braces are still balanced**

There's no linter for `.css` in this project, so use a brace-count as a structural sanity check after every CSS edit in this plan:

Run: `awk 'BEGIN{o=0;c=0} {o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "open="o, "close="c}' public/assets/app.css`
Expected: the two printed numbers (`open=`, `close=`) are equal.

- [ ] **Step 4: Commit**

```bash
git add public/assets/app.css
git commit -m "feat: add a subtle per-theme icon texture behind the board"
```

---

### Task 3: Ikon tipis di sebagian kotak biasa

**Files:**
- Modify: `public/assets/app.js:102-123` (`renderBoard()`)
- Modify: `public/assets/app.css` (new `.tile-theme-icon::before` rule)

- [ ] **Step 1: Mark a deterministic subset of ordinary tiles**

In `public/assets/app.js`, inside `renderBoard()`, find:

```javascript
            const special = tileSpecial(tile, snapshot.board);
            const classes = [
                'tile',
                tile === 1 ? 'tile-start' : '',
                tile === total ? 'tile-finish' : '',
                special ? 'tile-has-special' : '',
                (teamsByPosition[tile] || []).some((team) => team.uuid === currentTeamUuid) ? 'tile-current' : '',
                recentMovement && (recentMovement.from === tile || recentMovement.to === tile || recentMovement.landed === tile) ? 'tile-recent' : '',
            ].filter(Boolean).join(' ');
```

Replace with:

```javascript
            const special = tileSpecial(tile, snapshot.board);
            const classes = [
                'tile',
                tile === 1 ? 'tile-start' : '',
                tile === total ? 'tile-finish' : '',
                special ? 'tile-has-special' : '',
                (!special && tile !== 1 && tile !== total && tile % 6 === 3) ? 'tile-theme-icon' : '',
                (teamsByPosition[tile] || []).some((team) => team.uuid === currentTeamUuid) ? 'tile-current' : '',
                recentMovement && (recentMovement.from === tile || recentMovement.to === tile || recentMovement.landed === tile) ? 'tile-recent' : '',
            ].filter(Boolean).join(' ');
```

- [ ] **Step 2: Verify JS syntax**

Run: `node --check public/assets/app.js`
Expected: no output, exit code 0.

- [ ] **Step 3: Add the icon overlay CSS**

In `public/assets/app.css`, find the existing `.tile { ... }` rule (currently at line 979-988, it's the one with `color: #142033;`) and add this new rule right after that block (and after the `.tile:nth-child(even)`, `.tile-start`, `.tile-finish`, `.tile-current`, `.tile-recent` rules that already follow it — insert it anywhere in that same cluster of `.tile-*` rules, e.g. right after `.tile-recent:not(.tile-current) { ... }`, currently ending around line 1011):

```css
.tile-theme-icon::before {
    background-color: currentColor;
    bottom: 3px;
    content: "";
    -webkit-mask-image: var(--board-icon);
    mask-image: var(--board-icon);
    -webkit-mask-position: center;
    mask-position: center;
    -webkit-mask-repeat: no-repeat;
    mask-repeat: no-repeat;
    -webkit-mask-size: contain;
    mask-size: contain;
    height: 60%;
    opacity: .16;
    position: absolute;
    right: 3px;
    width: 60%;
    z-index: 0;
}
```

- [ ] **Step 4: Verify the CSS file's braces are still balanced**

Run: `awk 'BEGIN{o=0;c=0} {o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "open="o, "close="c}' public/assets/app.css`
Expected: the two printed numbers (`open=`, `close=`) are equal.

- [ ] **Step 5: Commit**

```bash
git add public/assets/app.js public/assets/app.css
git commit -m "feat: show a faint theme icon on a subset of ordinary tiles"
```

---

### Task 4: Variasi bentuk/border kotak per tema

**Files:**
- Modify: `public/assets/app.css` (new `.board[data-theme="..."] .tile` rules)

- [ ] **Step 1: Add the per-theme tile shape rules**

In `public/assets/app.css`, add this new block right after the `.tile-theme-icon::before { ... }` rule you added in Task 3:

```css
.board[data-theme="jungle_quest"] .tile {
    border-radius: 14px 4px 14px 4px;
}

.board[data-theme="space_mission"] .tile {
    border-radius: 2px;
    clip-path: polygon(6px 0, 100% 0, 100% calc(100% - 6px), calc(100% - 6px) 100%, 0 100%, 0 6px);
}

.board[data-theme="ocean_quest"] .tile {
    border-radius: 4px 4px 12px 12px;
}

.board[data-theme="city_challenge"] .tile {
    border-radius: 2px;
}

.board[data-theme="lab_challenge"] .tile {
    border-style: dashed;
    border-width: 1.5px;
}
```

(No rule is needed for `classic_arena` — it keeps the existing default `.tile` styling, which already looks correct as the "plain" baseline.)

- [ ] **Step 2: Verify the CSS file's braces are still balanced**

Run: `awk 'BEGIN{o=0;c=0} {o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "open="o, "close="c}' public/assets/app.css`
Expected: the two printed numbers (`open=`, `close=`) are equal.

- [ ] **Step 3: Commit**

```bash
git add public/assets/app.css
git commit -m "feat: give each board theme a distinct tile shape"
```

---

### Task 5: Glow pion mengikuti tema papan

**Files:**
- Modify: `public/assets/app.css` (extend `.pawn` and `.board-mover` box-shadow)
- Modify: `public/assets/app.js:730` (`animateMovementEvent()`)

- [ ] **Step 1: Add the accent glow to resting pawns (pieces sitting on a tile)**

In `public/assets/app.css`, find (currently at line 1084):

```css
.pawn {
    border: 2px solid #ffffff;
    border-radius: 50%;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .3);
    height: 18px;
    width: 18px;
}
```

Replace with:

```css
.pawn {
    border: 2px solid #ffffff;
    border-radius: 50%;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .3), 0 0 0 3px color-mix(in srgb, var(--board-accent, #f97316) 45%, transparent);
    height: 18px;
    width: 18px;
}
```

- [ ] **Step 2: Add the accent glow to the animated moving piece**

In `public/assets/app.css`, find (currently starting at line 1203):

```css
.board-mover {
    align-items: center;
    border: 3px solid #ffffff;
    border-radius: 999px;
    box-shadow: 0 16px 36px rgba(0, 0, 0, .34), 0 0 0 7px rgba(255, 255, 255, .18);
    color: #ffffff;
    display: inline-flex;
    font-size: 15px;
    font-weight: 900;
    height: 42px;
    justify-content: center;
```

Replace the `box-shadow` line only, keeping every other line in that block unchanged:

```css
.board-mover {
    align-items: center;
    border: 3px solid #ffffff;
    border-radius: 999px;
    box-shadow: 0 16px 36px rgba(0, 0, 0, .34), 0 0 0 7px rgba(255, 255, 255, .18), 0 0 0 10px color-mix(in srgb, var(--board-accent, #f97316) 55%, transparent);
    color: #ffffff;
    display: inline-flex;
    font-size: 15px;
    font-weight: 900;
    height: 42px;
    justify-content: center;
```

- [ ] **Step 3: Pass the board's accent color to the animated mover in JS**

The `.board-mover` element is appended to `document.body`, outside the `.board` element that carries the `--board-accent` custom property, so it needs its own copy of that value. In `public/assets/app.js`, find (currently at line 730, inside `animateMovementEvent()`):

```javascript
        mover.style.setProperty('--team-color', team.color);
```

Add this line directly after it:

```javascript
        mover.style.setProperty('--team-color', team.color);
        mover.style.setProperty('--board-accent', (snapshot.board && snapshot.board.theme && snapshot.board.theme.palette && snapshot.board.theme.palette.accent) || '#f97316');
```

- [ ] **Step 4: Verify JS syntax and CSS brace balance**

Run: `node --check public/assets/app.js`
Expected: no output, exit code 0.

Run: `awk 'BEGIN{o=0;c=0} {o+=gsub(/{/,"{"); c+=gsub(/}/,"}")} END{print "open="o, "close="c}' public/assets/app.css`
Expected: the two printed numbers (`open=`, `close=`) are equal.

- [ ] **Step 5: Commit**

```bash
git add public/assets/app.css public/assets/app.js
git commit -m "feat: add theme-accent glow to resting and moving player pieces"
```

---

### Task 6: Verifikasi visual penuh dan regresi

**Files:** none (verification only)

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `./vendor/bin/phpunit`
Expected: all tests still PASS at the same count as before this plan started (no PHP files were touched by Tasks 1-5, so this is a pure regression confirmation).

- [ ] **Step 2: Lint the two touched files one more time, together**

Run:
```bash
node --check public/assets/app.js
```
Expected: no output, exit code 0.

(There is no linter for `.css` in this project — visual correctness there is confirmed by Step 3 below.)

- [ ] **Step 3: Start the dev server and check it responds**

Run: `php spark serve --host 127.0.0.1 --port 8090 &` (background it, or use whatever backgrounding convention your environment provides)
Run: `curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8090/teacher/games`
Expected: `302` (redirect to login — confirms the server started and is serving requests without a 500 error).

- [ ] **Step 4: Manually verify each of the 6 themes in a browser**

Log in as a teacher, create one room per theme (or reuse existing rooms if any exist per theme from earlier testing), and open each room's detail page (`/teacher/games/{uuid}`) or the projector view (`/game/{uuid}/projector`). For EACH of the 6 themes (Classic Arena, Jungle Quest, Space Mission, Ocean Quest, City Challenge, Lab Challenge), confirm:
- A faint icon texture is visible in the board's background (not overwhelming, not invisible).
- Roughly 1 in 6 ordinary tiles shows a small faint icon (not on tile 1, not on the last tile, not on any BONUS/TRAP/SAFE/MYSTERY tile).
- The tile shape/border looks visibly different from the other themes (rounded corners for Jungle, cut corners for Space, wavy bottom for Ocean, dashed border for Lab, crisp square for City, default for Classic Arena).
- Tile numbers are still clearly legible at a glance — if any theme makes numbers hard to read, note it (this would mean the icon opacity in Task 3's CSS, currently `.16`, needs to be lowered — this is a one-value tweak, not a structural change).
- A team's piece (both sitting on a tile, and while animating a move after a correct answer) shows a colored glow ring matching that theme's accent color.

- [ ] **Step 5: Fix and re-verify if anything from Step 4 looks wrong**

If any theme fails the legibility or visual-distinction check in Step 4, adjust the specific CSS value that causes it (e.g. lower an `opacity`, adjust a `mask-size`) directly in `public/assets/app.css`, re-run Step 4 for that theme only, then commit the fix:

```bash
git add public/assets/app.css
git commit -m "fix: tune board theme visual redesign opacity/sizing after manual review"
```

(Skip this step entirely if Step 4 found nothing to fix.)
