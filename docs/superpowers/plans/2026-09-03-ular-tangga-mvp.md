# Implementation Plan - Ular Tangga Edukatif MVP

**Tanggal:** 2026-09-03  
**Status:** Implemented

## Task 1: Foundation

- [x] Scaffold CodeIgniter 4 di `ular-tangga`
- [x] Install `codeigniter4/shield`
- [x] Install `pusher/pusher-php-server`
- [x] Install `phpoffice/phpspreadsheet`
- [x] Setup `.env` lokal dengan SQLite

## Task 2: Database

- [x] Migration domain game inti
- [x] Seeder guru demo, board 100 kotak, soal master demo
- [x] Event log, score ledger, idempotency, dan realtime outbox

## Task 3: Backend Engine

- [x] `GameEngine::createRoom`
- [x] `GameEngine::joinByPin`
- [x] `GameEngine::start`
- [x] `GameEngine::roll`
- [x] `GameEngine::answer`
- [x] `GameEngine::snapshot`
- [x] Ladder/snake movement
- [x] Basic idempotency key response cache

## Task 4: API dan Web

- [x] Teacher dashboard
- [x] Bank soal readonly demo
- [x] Create/list/detail/control game
- [x] Join public by PIN
- [x] Projector 2D fallback
- [x] Controller tim
- [x] REST JSON state/start/roll/answer

## Task 5: Verification

- [x] `php spark migrate`
- [x] `php spark db:seed DemoGameSeeder`
- [x] Web routes return 200
- [x] API start/roll/answer verified
- [x] PHP syntax check
- [x] PHPUnit suite passes

## Next Phase

- [ ] Publish and configure Shield auth views/migrations
- [ ] Move local SQLite config to MySQL production `.env`
- [ ] Add question CRUD/import/export
- [ ] Add pause/resume, timeout worker, and redemption flow
- [ ] Add full Pusher JS client and private/presence channel authorization
- [ ] Add reports and XLSX export
- [ ] Add Spline adapter after 2D projector is stable
