(function () {
    function jsonFetch(url, options) {
        const method = (options && options.method) || 'GET';
        const idempotencyKey = method === 'POST' ? crypto.randomUUID() : undefined;

        return fetch(url, Object.assign({
            headers: Object.assign({
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            }, idempotencyKey ? {'Idempotency-Key': idempotencyKey} : {}),
        }, options || {}))
            .then((response) => response.json().then((body) => ({response, body})))
            .then(({response, body}) => {
                if (!response.ok || body.ok === false) {
                    throw new Error((body.error && body.error.message) || 'Permintaan gagal.');
                }

                return body.data;
            });
    }

    function orderedTiles(total) {
        const rows = [];
        for (let row = 9; row >= 0; row--) {
            const start = row * 10 + 1;
            const nums = Array.from({length: 10}, (_, i) => start + i);
            rows.push(row % 2 === 0 ? nums : nums.reverse());
        }

        return rows.flat().filter((number) => number <= total);
    }

    function tileSpecial(tile, board) {
        const ladder = (board.ladders || []).find((item) => Number(item.from) === tile);
        if (ladder) {
            return '<span class="special-mark ladder-mark">Tangga ' + escapeHtml(ladder.to) + '</span>';
        }
        const snake = (board.snakes || []).find((item) => Number(item.from) === tile);
        if (snake) {
            return '<span class="special-mark snake-mark">Ular ' + escapeHtml(snake.to) + '</span>';
        }
        const special = (board.special_tiles || []).find((item) => Number(item.tile) === tile);
        if (special) {
            return '<span class="special-mark tile-effect-mark tile-effect-' + specialTileClass(special.type) + '">' + escapeHtml(specialTileLabel(special)) + '</span>';
        }

        return '';
    }

    function specialTileClass(type) {
        const value = String(type || '').toLowerCase();
        return ['bonus', 'trap', 'safe', 'mystery', 'duel'].includes(value) ? value : 'normal';
    }

    function specialTileLabel(tile) {
        if (tile.label) {
            return tile.label;
        }

        switch (String(tile.type || '').toUpperCase()) {
            case 'BONUS':
                return 'Bonus';
            case 'TRAP':
                return 'Trap';
            case 'SAFE':
                return 'Safe';
            case 'MYSTERY':
                return 'Misteri';
            case 'DUEL':
                return 'Duel';
            default:
                return 'Tile';
        }
    }

    function renderBoard(element, snapshot) {
        if (!element || !snapshot) {
            return;
        }

        applyBoardTheme(element, snapshot.board && snapshot.board.theme);

        const renderer = snapshot.mode_state && snapshot.mode_state.renderer || 'snakes_ladders_board';
        if (renderer !== 'snakes_ladders_board') {
            element.innerHTML = '<div class="mode-placeholder">' +
                '<strong>' + escapeHtml(snapshot.mode_state && snapshot.mode_state.label || 'Mode Game') + '</strong>' +
                '<span>' + escapeHtml(snapshot.mode_state && snapshot.mode_state.description || 'Renderer mode ini sedang disiapkan.') + '</span>' +
            '</div>';
            return;
        }

        const total = snapshot.board.tile_count || 100;
        const teamsByPosition = {};
        const currentTeamUuid = snapshot.room && snapshot.room.current_team_uuid;
        const recentMovement = latestMovement(snapshot);
        (snapshot.teams || []).forEach((team) => {
            const position = Number(team.position || 1);
            teamsByPosition[position] = teamsByPosition[position] || [];
            teamsByPosition[position].push(team);
        });

        const tiles = orderedTiles(total).map((tile) => {
            const pawns = (teamsByPosition[tile] || []).map((team) => (
                '<span class="pawn pawn-token avatar-' + avatarClass(team.avatar) + ' ' + (team.uuid === currentTeamUuid ? 'active-pawn' : '') + '" title="' + escapeHtml(team.name) + '" style="--team-color:' + escapeHtml(team.color) + '; background:' + escapeHtml(team.color) + '">' +
                '<span>' + escapeHtml(teamInitials(team.name)) + '</span>' +
                '</span>'
            )).join('');
            const special = tileSpecial(tile, snapshot.board);
            const classes = [
                'tile',
                tile === 1 ? 'tile-start' : '',
                tile === total ? 'tile-finish' : '',
                special ? 'tile-has-special' : '',
                (teamsByPosition[tile] || []).some((team) => team.uuid === currentTeamUuid) ? 'tile-current' : '',
                recentMovement && (recentMovement.from === tile || recentMovement.to === tile || recentMovement.landed === tile) ? 'tile-recent' : '',
            ].filter(Boolean).join(' ');

            return '<div class="' + classes + '" data-tile="' + tile + '">' +
                '<div class="tile-number">' + tile + '</div>' +
                (special ? '<div class="tile-special">' + special + '</div>' : '') +
                '<div class="tile-teams">' + pawns + '</div>' +
            '</div>';
        }).join('');

        element.innerHTML = '<div class="board-grid">' + tiles + '</div><svg class="board-path-layer" data-board-paths aria-hidden="true"></svg>';
        window.requestAnimationFrame(() => renderBoardPaths(element, snapshot));
    }

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

    function renderBoardPaths(boardElement, snapshot) {
        const svg = boardElement.querySelector('[data-board-paths]');
        if (!svg) {
            return;
        }

        const bounds = boardElement.getBoundingClientRect();
        if (bounds.width < 10 || bounds.height < 10) {
            svg.innerHTML = '';
            return;
        }

        svg.setAttribute('viewBox', '0 0 ' + bounds.width + ' ' + bounds.height);
        const ladders = (snapshot.board.ladders || []).map((item) => renderLadderPath(boardElement, bounds, item)).join('');
        const snakes = (snapshot.board.snakes || []).map((item) => renderSnakePath(boardElement, bounds, item)).join('');
        svg.innerHTML = '<defs>' +
            '<filter id="pathShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="3" stdDeviation="3" flood-color="#0f172a" flood-opacity=".28"/></filter>' +
            '</defs>' + ladders + snakes;
    }

    function tileCenter(boardElement, bounds, tileNumber) {
        const tile = boardElement.querySelector('[data-tile="' + Number(tileNumber) + '"]');
        if (!tile) {
            return null;
        }

        const rect = tile.getBoundingClientRect();
        return {
            x: rect.left - bounds.left + rect.width / 2,
            y: rect.top - bounds.top + rect.height / 2,
        };
    }

    function renderLadderPath(boardElement, bounds, ladder) {
        const from = tileCenter(boardElement, bounds, ladder.from);
        const to = tileCenter(boardElement, bounds, ladder.to);
        if (!from || !to) {
            return '';
        }

        const angle = Math.atan2(to.y - from.y, to.x - from.x);
        const offsetX = Math.sin(angle) * 10;
        const offsetY = -Math.cos(angle) * 10;
        const rungCount = 5;
        const rungs = Array.from({length: rungCount}, (_, index) => {
            const t = (index + 1) / (rungCount + 1);
            const cx = from.x + (to.x - from.x) * t;
            const cy = from.y + (to.y - from.y) * t;
            return '<line class="ladder-rung" x1="' + (cx - offsetX) + '" y1="' + (cy - offsetY) + '" x2="' + (cx + offsetX) + '" y2="' + (cy + offsetY) + '"></line>';
        }).join('');

        return '<g class="ladder-path" filter="url(#pathShadow)">' +
            '<line class="ladder-rail" x1="' + (from.x - offsetX) + '" y1="' + (from.y - offsetY) + '" x2="' + (to.x - offsetX) + '" y2="' + (to.y - offsetY) + '"></line>' +
            '<line class="ladder-rail" x1="' + (from.x + offsetX) + '" y1="' + (from.y + offsetY) + '" x2="' + (to.x + offsetX) + '" y2="' + (to.y + offsetY) + '"></line>' +
            rungs +
            '</g>';
    }

    function renderSnakePath(boardElement, bounds, snake) {
        const from = tileCenter(boardElement, bounds, snake.from);
        const to = tileCenter(boardElement, bounds, snake.to);
        if (!from || !to) {
            return '';
        }

        const midX = (from.x + to.x) / 2 + (from.y - to.y) * 0.18;
        const midY = (from.y + to.y) / 2 + (to.x - from.x) * 0.12;
        const d = 'M ' + from.x + ' ' + from.y + ' Q ' + midX + ' ' + midY + ' ' + to.x + ' ' + to.y;

        return '<g class="snake-path" filter="url(#pathShadow)">' +
            '<path d="' + d + '"></path>' +
            '<circle class="snake-head" cx="' + from.x + '" cy="' + from.y + '" r="9"></circle>' +
            '<circle class="snake-tail" cx="' + to.x + '" cy="' + to.y + '" r="5"></circle>' +
            '</g>';
    }

    function latestMovement(snapshot) {
        const events = snapshot.events || [];
        for (let index = events.length - 1; index >= 0; index--) {
            if (events[index].event === 'answer.resolved' && events[index].payload && events[index].payload.movement) {
                return events[index].payload.movement;
            }
        }

        return null;
    }

    function teamInitials(name) {
        const words = String(name || 'Tim').trim().split(/\s+/).filter(Boolean);
        if (words.length === 1) {
            return words[0].slice(0, 2).toUpperCase();
        }

        return (words[0][0] + words[1][0]).toUpperCase();
    }

    function avatarClass(avatar) {
        const value = String(avatar || 'robot').toLowerCase();
        return ['robot', 'explorer', 'rocket', 'knight', 'scientist', 'runner'].includes(value) ? value : 'robot';
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderLeaderboard(element, snapshot) {
        if (!element || !snapshot) {
            return;
        }

        element.innerHTML = (snapshot.leaderboard || []).map((team, index) => {
            const badges = [];
            const streak = Number(team.streak_count || 0);
            const shield = Number(team.active_effects && team.active_effects.safe_shield || 0);
            if (streak >= 2) {
                badges.push('Streak x' + streak);
            }
            if (shield > 0) {
                badges.push('Perisai ' + shield);
            }

            return '<div class="leader-row">' +
                '<span class="pawn pawn-token avatar-' + avatarClass(team.avatar) + '" style="--team-color:' + escapeHtml(team.color) + '; background:' + escapeHtml(team.color) + '"><span>' + escapeHtml(teamInitials(team.name)) + '</span></span>' +
                '<strong>' + (index + 1) + '. ' + escapeHtml(team.name) + (badges.length ? '<small>' + badges.map(escapeHtml).join(' / ') + '</small>' : '') + '</strong>' +
                '<span>' + team.score + ' / kotak ' + team.position + '</span>' +
                '</div>';
        }).join('') || '<p class="muted">Belum ada tim.</p>';
    }

    function renderEvents(element, snapshot) {
        if (!element || !snapshot) {
            return;
        }

        element.innerHTML = (snapshot.events || []).slice(-10).reverse().map((event) => {
            const label = eventLabel(event, snapshot);
            return '<div><strong>' + escapeHtml(label) + '</strong><br><span class="muted">v' + event.state_version + '</span></div>';
        }).join('');
    }

    function eventLabel(event, snapshot) {
        const payload = event.payload || {};
        switch (event.event) {
            case 'room.team_joined':
                return (payload.team && payload.team.name ? payload.team.name : 'Tim') + ' masuk lobby';
            case 'game.started':
                return 'Game dimulai. Giliran pertama: ' + teamNameByUuid(payload.current_team_uuid, snapshot);
            case 'turn_order.selected':
                return 'Giliran pertama ditentukan: ' + teamNameByUuid(payload.current_team_uuid, snapshot);
            case 'dice.rolled':
                return teamNameByUuid(payload.team_uuid, snapshot) + ' mendapat dadu ' + payload.dice_value;
            case 'question.started':
                return 'Pertanyaan untuk ' + teamNameByUuid(payload.team_uuid, snapshot);
            case 'answer.resolved':
                return answerResolvedLabel(payload);
            case 'tile.special_triggered':
                return specialEventLabel(payload.effect || {}, payload.team_uuid, snapshot);
            case 'turn.timeout':
                return 'Waktu habis untuk ' + teamNameByUuid(payload.team_uuid, snapshot) + '. Giliran berpindah.';
            case 'game.paused':
                return 'Game dijeda guru.';
            case 'game.resumed':
                return 'Game dilanjutkan guru.';
            case 'turn.skipped':
                return 'Guru melewati giliran ' + teamNameByUuid(payload.team_uuid, snapshot) + '.';
            case 'teacher.override':
                return 'Kontrol guru: ' + teacherActionLabel(payload.action);
            case 'game.finished':
                return 'Game selesai. Pemenang: ' + teamNameByUuid(payload.winner_team_uuid, snapshot);
            default:
                return event.event || 'Event';
        }
    }

    function teamNameByUuid(teamUuid, snapshot) {
        const team = (snapshot && snapshot.teams || []).find((item) => item.uuid === teamUuid);

        return team ? team.name : 'Tim';
    }

    function answerResolvedLabel(payload) {
        const movement = payload.movement || {};
        const bounce = movement.finish_bounced
            ? ' Melewati finish, pion memantul ke kotak ' + movement.landed + '.'
            : '';
        const suffix = movement.special === 'LADDER'
            ? ' Naik tangga ke kotak ' + movement.to + '.'
            : movement.special === 'SNAKE'
                ? ' Kena ular turun ke kotak ' + movement.to + '.'
                : movement.special === 'SAFE_BLOCK'
                    ? ' Perisai menahan ular.'
                    : movement.special === 'TRAP'
                        ? ' Kena trap, mundur ke kotak ' + movement.to + '.'
                        : movement.special === 'BONUS'
                            ? ' Mendapat bonus poin.'
                            : movement.special === 'SAFE'
                                ? ' Mendapat perisai aman.'
                                : movement.special === 'MYSTERY'
                                    ? ' Tile misteri aktif.'
                : '';

        return (payload.is_correct ? 'Jawaban benar.' : 'Jawaban belum tepat.') + bounce + suffix + scoreBreakdownLabel(payload.score_breakdown);
    }

    function scoreBreakdownLabel(breakdown) {
        if (!breakdown) {
            return '';
        }

        const parts = [];
        if (Number(breakdown.time_bonus || 0) > 0) {
            parts.push('cepat +' + Number(breakdown.time_bonus));
        }
        if (Number(breakdown.streak_bonus || 0) > 0) {
            parts.push('streak +' + Number(breakdown.streak_bonus));
        }
        if (Number(breakdown.near_finish_bonus || 0) > 0) {
            parts.push('finish +' + Number(breakdown.near_finish_bonus));
        }
        if (Number(breakdown.answer || 0) < 0) {
            parts.push('penalti ' + Number(breakdown.answer));
        }

        return parts.length ? ' Bonus: ' + parts.join(', ') + '.' : '';
    }

    function specialEventLabel(effect, teamUuid, snapshot) {
        const team = teamNameByUuid(teamUuid, snapshot);
        const type = String(effect.type || '').toUpperCase();
        if (type === 'BONUS') {
            return 'Bonus tile: +' + Number(effect.points || 0) + ' poin.';
        }
        if (type === 'TRAP') {
            return 'Trap tile: mundur ke kotak ' + Number(effect.to || 1) + '.';
        }
        if (type === 'SAFE') {
            return 'Perisai aman didapat.';
        }
        if (type === 'SAFE_BLOCK') {
            return 'Perisai menahan ular.';
        }
        if (type === 'MYSTERY') {
            return 'Tile misteri aktif untuk ' + team + '.';
        }
        if (type === 'DUEL') {
            return 'Tile duel aktif untuk ' + team + '.';
        }

        return 'Tile khusus aktif.';
    }

    function teacherActionLabel(action) {
        switch (action) {
            case 'pause':
                return 'pause';
            case 'resume':
                return 'resume';
            case 'skip_turn':
                return 'skip turn';
            case 'force_timeout':
                return 'force timeout';
            default:
                return action || 'override';
        }
    }

    function updateSummary(root, snapshot) {
        root.querySelectorAll('[data-room-status]').forEach((el) => el.textContent = snapshot.room.status);
        root.querySelectorAll('[data-state-version]').forEach((el) => el.textContent = snapshot.room.state_version);
        root.querySelectorAll('[data-current-team]').forEach((el) => {
            const current = (snapshot.teams || []).find((team) => team.uuid === snapshot.room.current_team_uuid);
            el.textContent = current ? current.name : '-';
        });
        updateCountdown(root, snapshot);
    }

    function updateCountdown(root, snapshot) {
        const countdown = countdownState(snapshot);
        root.querySelectorAll('[data-countdown]').forEach((el) => {
            el.textContent = countdown.label;
            el.classList.toggle('countdown-danger', countdown.remaining !== null && countdown.remaining <= 10);
        });
        root.querySelectorAll('[data-countdown-bar]').forEach((el) => {
            el.style.width = countdown.percent + '%';
            el.classList.toggle('countdown-danger', countdown.remaining !== null && countdown.remaining <= 10);
        });
    }

    function countdownState(snapshot) {
        const turn = snapshot.current_turn;
        if (!turn || turn.state !== 'QUESTION_ACTIVE' || !turn.deadline_at) {
            return {label: '-', remaining: null, percent: 0};
        }

        const deadline = Number(turn.deadline_epoch_ms || 0);
        if (!deadline) {
            return {label: '-', remaining: null, percent: 0};
        }
        const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        const total = Math.max(1, Number(snapshot.room.question_time_seconds || turn.question && turn.question.time_limit_seconds || 30));

        return {
            label: remaining > 0 ? remaining + ' detik' : 'Waktu habis',
            remaining,
            percent: Math.max(0, Math.min(100, (remaining / total) * 100)),
        };
    }

    function isClientTurnExpired(snapshot) {
        return countdownState(snapshot).remaining === 0;
    }

    function createRuntime(config) {
        let snapshot = config.snapshot;
        const root = document;

        function draw() {
            updateSummary(root, snapshot);
            renderBoard(document.querySelector('[data-board]'), snapshot);
            renderLeaderboard(document.querySelector('[data-leaderboard]'), snapshot);
            renderEvents(document.querySelector('[data-events]'), snapshot);
        }

        function refresh() {
            return jsonFetch('/api/v1/rooms/' + config.roomUuid + '/state')
                .then((next) => {
                    if (!snapshot || next.room.state_version >= snapshot.room.state_version) {
                        snapshot = next;
                        draw();
                        if (typeof config.onSnapshot === 'function') {
                            config.onSnapshot(snapshot);
                        }
                    }
                    return snapshot;
                })
                .catch((error) => setError(error.message));
        }

        function setError(message) {
            document.querySelectorAll('[data-error]').forEach((el) => {
                el.textContent = message || '';
                el.classList.toggle('hidden', !message);
            });
        }

        draw();
        window.setInterval(() => updateSummary(root, snapshot), 500);
        window.setInterval(refresh, config.interval || 2200);

        return {
            getSnapshot: () => snapshot,
            refresh,
            setError,
        };
    }

    function teacherControl(config) {
        const runtime = createRuntime(config);
        const startButton = document.querySelector('[data-start]');
        const pauseButton = document.querySelector('[data-pause]');
        const resumeButton = document.querySelector('[data-resume]');
        const skipTurnButton = document.querySelector('[data-skip-turn]');
        const forceTimeoutButton = document.querySelector('[data-force-timeout]');

        function drawTeacherControl() {
            const snapshot = runtime.getSnapshot();
            const turn = snapshot.current_turn;
            const canStart = snapshot.room.status === 'LOBBY';
            const isPlaying = snapshot.room.status === 'PLAYING';
            const isPaused = snapshot.room.status === 'PAUSED';
            const hasTurn = Boolean(turn);
            const hasActiveQuestion = isPlaying && turn && turn.state === 'QUESTION_ACTIVE';

            if (startButton) {
                startButton.disabled = !canStart;
                startButton.textContent = canStart ? 'Start' : statusText(snapshot.room.status);
            }
            if (pauseButton) {
                pauseButton.disabled = !isPlaying;
            }
            if (resumeButton) {
                resumeButton.disabled = !isPaused;
            }
            if (skipTurnButton) {
                skipTurnButton.disabled = !isPlaying || !hasTurn;
            }
            if (forceTimeoutButton) {
                forceTimeoutButton.disabled = !hasActiveQuestion;
            }
        }

        const originalRefresh = runtime.refresh;
        runtime.refresh = function () {
            return originalRefresh().then((snapshot) => {
                drawTeacherControl();
                return snapshot;
            });
        };

        if (startButton) {
            startButton.addEventListener('click', function () {
                teacherAction('start', startButton);
            });
        }
        [
            [pauseButton, 'pause'],
            [resumeButton, 'resume'],
            [skipTurnButton, 'skip-turn'],
            [forceTimeoutButton, 'force-timeout'],
        ].forEach(([button, action]) => {
            if (!button) {
                return;
            }
            button.addEventListener('click', function () {
                teacherAction(action, button);
            });
        });

        function teacherAction(action, button) {
            button.disabled = true;
            runtime.setError('');
            jsonFetch('/api/v1/rooms/' + config.roomUuid + '/' + action, {method: 'POST', body: '{}'})
                .then(runtime.refresh)
                .catch((error) => runtime.setError(error.message))
                .finally(drawTeacherControl);
        }

        drawTeacherControl();
    }

    function projector(config) {
        const seenEvents = new Set((config.snapshot.events || []).map((event) => event.event_id));
        const overlayQueue = [];
        let overlayBusy = false;

        createRuntime(Object.assign({interval: 1600}, config, {
            onSnapshot(snapshot) {
                (snapshot.events || []).forEach((event) => {
                    if (seenEvents.has(event.event_id)) {
                        return;
                    }
                    seenEvents.add(event.event_id);
                    const item = overlayForEvent(event, snapshot);
                    if (item) {
                        overlayQueue.push(item);
                    }
                    if (event.event === 'answer.resolved') {
                        animateMovementEvent(event, snapshot);
                    }
                });
                playOverlayQueue(overlayQueue, () => overlayBusy, (value) => {
                    overlayBusy = value;
                });
            },
        }));
    }

    function overlayForEvent(event, snapshot) {
        const payload = event.payload || {};
        switch (event.event) {
            case 'turn_order.selected':
                return {
                    tone: 'info',
                    title: 'Giliran Pertama',
                    body: teamNameByUuid(payload.current_team_uuid, snapshot),
                };
            case 'dice.rolled':
                return {
                    tone: 'dice',
                    title: teamNameByUuid(payload.team_uuid, snapshot),
                    body: 'Dadu ' + payload.dice_value,
                };
            case 'answer.resolved': {
                const movement = payload.movement || {};
                return {
                    tone: payload.is_correct ? 'success' : 'danger',
                    title: payload.is_correct ? 'Jawaban Benar' : 'Belum Tepat',
                    body: movement.special === 'LADDER'
                        ? 'Naik tangga ke kotak ' + movement.to
                        : movement.special === 'SNAKE'
                            ? 'Kena ular, turun ke kotak ' + movement.to
                            : movement.finish_bounced
                                ? 'Melewati finish, memantul ke ' + movement.to
                                : movement.special === 'TRAP'
                                    ? 'Trap aktif, mundur ke kotak ' + movement.to
                                : teamNameByUuid(payload.team_uuid, snapshot),
                };
            }
            case 'tile.special_triggered':
                return specialOverlay(payload.effect || {}, payload.team_uuid, snapshot);
            case 'mystery.target_chosen':
                return {
                    tone: 'dice',
                    title: 'Kotak Misteri',
                    body: teamNameByUuid(payload.team_uuid, snapshot) + ' memilih ' + (payload.target === 'SELF' ? 'hadiah untuk timnya' : 'menyerang ' + teamNameByUuid(payload.target, snapshot)),
                };
            case 'mystery.resolved':
                return {
                    tone: payload.outcome === 'REWARD_SELF' ? 'success' : 'danger',
                    title: payload.outcome === 'REWARD_SELF' ? 'Misteri: Hadiah!' : (payload.outcome === 'PUNISH_OPPONENT' ? 'Misteri: Kena Serang!' : 'Misteri: Boomerang!'),
                    body: teamNameByUuid(payload.affected_team_uuid, snapshot) + (payload.outcome === 'REWARD_SELF' ? ' dapat efek positif' : ' kena efek negatif'),
                };
            case 'turn.timeout':
                return {
                    tone: 'danger',
                    title: 'Waktu Habis',
                    body: teamNameByUuid(payload.team_uuid, snapshot),
                };
            case 'game.paused':
                return {
                    tone: 'info',
                    title: 'Game Dijeda',
                    body: 'Ikuti arahan guru',
                };
            case 'game.resumed':
                return {
                    tone: 'success',
                    title: 'Game Dilanjutkan',
                    body: teamNameByUuid(snapshot.room.current_team_uuid, snapshot),
                };
            case 'turn.skipped':
                return {
                    tone: 'info',
                    title: 'Giliran Dilewati',
                    body: teamNameByUuid(payload.next_team_uuid, snapshot),
                };
            case 'game.finished':
                return {
                    tone: 'winner',
                    title: 'Pemenang',
                    body: teamNameByUuid(payload.winner_team_uuid, snapshot),
                };
            default:
                return null;
        }
    }

    function specialOverlay(effect, teamUuid, snapshot) {
        const type = String(effect.type || '').toUpperCase();
        const team = teamNameByUuid(teamUuid, snapshot);
        if (type === 'BONUS') {
            return {tone: 'success', title: 'Bonus Tile', body: team + ' +' + Number(effect.points || 0)};
        }
        if (type === 'TRAP') {
            return {tone: 'danger', title: 'Trap Tile', body: team + ' mundur ke ' + Number(effect.to || 1)};
        }
        if (type === 'SAFE') {
            return {tone: 'info', title: 'Perisai Aman', body: team + ' punya pelindung'};
        }
        if (type === 'SAFE_BLOCK') {
            return {tone: 'success', title: 'Perisai Aktif', body: 'Ular berhasil ditahan'};
        }
        if (type === 'DUEL') {
            return {tone: 'dice', title: 'Duel Tile', body: 'Mode duel disiapkan'};
        }

        return {tone: 'info', title: 'Tile Khusus', body: team};
    }

    function animateMovementEvent(event, snapshot) {
        const movement = event.payload && event.payload.movement;
        if (!movement || Number(movement.from) === Number(movement.to)) {
            return;
        }

        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === event.payload.team_uuid);
        if (!board || !team) {
            return;
        }

        const tilePath = movementTilePath(movement, snapshot);
        const points = tilePath
            .map((tile) => viewportTileCenter(board, tile))
            .filter(Boolean);
        if (points.length < 2) {
            return;
        }

        const mover = document.createElement('div');
        mover.className = 'board-mover';
        mover.classList.add('avatar-' + avatarClass(team.avatar));
        mover.style.setProperty('--team-color', team.color);
        mover.style.background = team.color;
        mover.innerHTML = '<span>' + escapeHtml(teamInitials(team.name)) + '</span>';
        document.body.appendChild(mover);

        const keyframes = points.map((point) => ({
            left: point.x + 'px',
            top: point.y + 'px',
            transform: 'translate(-50%, -50%) scale(1)',
        }));
        const duration = Math.min(3200, Math.max(900, points.length * 220));
        const animation = mover.animate(keyframes, {
            duration,
            easing: 'cubic-bezier(.2,.72,.2,1)',
            fill: 'forwards',
        });

        addTileEffect(board, Number(movement.to), movement.special);
        animation.finished
            .catch(() => null)
            .finally(() => {
                mover.remove();
            });
    }

    function movementTilePath(movement, snapshot) {
        const from = Number(movement.from);
        const landed = Number(movement.landed || movement.to);
        const to = Number(movement.to);
        const max = Number(snapshot.room && snapshot.room.max_position || snapshot.board.tile_count || 100);
        const path = [from];

        if (movement.finish_bounced) {
            for (let tile = from + 1; tile <= max; tile++) {
                path.push(tile);
            }
            for (let tile = max - 1; tile >= landed; tile--) {
                path.push(tile);
            }
        } else if (landed >= from) {
            for (let tile = from + 1; tile <= landed; tile++) {
                path.push(tile);
            }
        } else {
            for (let tile = from - 1; tile >= landed; tile--) {
                path.push(tile);
            }
        }

        if (to !== landed) {
            path.push(to);
        }

        return path.filter((tile, index, items) => tile >= 1 && tile <= max && (index === 0 || tile !== items[index - 1]));
    }

    function viewportTileCenter(boardElement, tileNumber) {
        const tile = boardElement.querySelector('[data-tile="' + Number(tileNumber) + '"]');
        if (!tile) {
            return null;
        }

        const rect = tile.getBoundingClientRect();
        return {
            x: rect.left + rect.width / 2,
            y: rect.top + rect.height / 2,
        };
    }

    function addTileEffect(boardElement, tileNumber, special) {
        const tile = boardElement.querySelector('[data-tile="' + Number(tileNumber) + '"]');
        if (!tile) {
            return;
        }

        const effect = special === 'SNAKE' || special === 'TRAP'
            ? 'tile-shake'
            : special === 'LADDER' || special === 'BONUS' || special === 'SAFE' || special === 'SAFE_BLOCK' || special === 'MYSTERY' || special === 'DUEL'
                ? 'tile-glow'
                : 'tile-arrived';
        tile.classList.add(effect);
        window.setTimeout(() => tile.classList.remove(effect), 1400);
    }

    function playOverlayQueue(queue, isBusy, setBusy) {
        const overlay = document.querySelector('[data-event-overlay]');
        if (!overlay || isBusy() || queue.length === 0) {
            return;
        }

        const item = queue.shift();
        setBusy(true);
        overlay.className = 'projector-event-overlay overlay-' + item.tone;
        overlay.innerHTML = '<span>' + escapeHtml(item.title) + '</span><strong>' + escapeHtml(item.body) + '</strong>';

        window.setTimeout(() => {
            overlay.classList.add('hidden');
            setBusy(false);
            playOverlayQueue(queue, isBusy, setBusy);
        }, item.tone === 'winner' ? 4200 : 2400);
    }

    function controller(config) {
        const runtime = createRuntime(config);
        const rollButton = document.querySelector('[data-roll]');
        const dicePanel = document.querySelector('[data-dice-panel]');
        const diceDisplay = document.querySelector('[data-dice-display]');
        const diceCaption = document.querySelector('[data-dice-caption]');
        const rollReason = document.querySelector('[data-roll-reason]');
        const questionBox = document.querySelector('[data-question]');
        const optionList = document.querySelector('[data-options]');
        const mysteryChoiceBox = document.querySelector('[data-mystery-choice]');
        const mysterySelfButton = document.querySelector('[data-mystery-self]');
        const mysteryOpponents = document.querySelector('[data-mystery-opponents]');
        const turnInfo = document.querySelector('[data-turn-info]');
        let isRolling = false;
        let isAnswering = false;

        function drawController() {
            const snapshot = runtime.getSnapshot();
            const turn = snapshot.current_turn;
            const isMyTurn = turn && turn.team_uuid === config.teamUuid;
            const team = (snapshot.teams || []).find((item) => item.uuid === config.teamUuid);
            const current = (snapshot.teams || []).find((item) => item.uuid === snapshot.room.current_team_uuid);
            const canRoll = modeCan(snapshot, 'roll') && snapshot.room.status === 'PLAYING' && isMyTurn && turn && turn.state === 'ROLL_READY';
            const timeExpired = isClientTurnExpired(snapshot);
            const isMysteryChoice = Boolean(isMyTurn && turn && turn.state === 'MYSTERY_CHOICE_PENDING');
            const canAnswer = modeCan(snapshot, 'answer') && isMyTurn && turn
                && (turn.state === 'QUESTION_ACTIVE' || turn.state === 'MYSTERY_QUESTION_ACTIVE')
                && turn.question && !timeExpired;

            document.querySelectorAll('[data-team-name]').forEach((el) => el.textContent = team ? team.name : 'Tim');
            document.querySelectorAll('[data-team-score]').forEach((el) => el.textContent = team ? team.score : '0');
            document.querySelectorAll('[data-team-position]').forEach((el) => el.textContent = team ? team.position : '1');

            if (turnInfo) {
                if (snapshot.room.status === 'LOBBY') {
                    turnInfo.textContent = 'Menunggu guru memulai permainan';
                } else if (snapshot.room.status === 'PAUSED') {
                    turnInfo.textContent = 'Game dijeda guru';
                } else if (snapshot.room.status === 'FINISHED') {
                    turnInfo.textContent = 'Permainan selesai';
                } else {
                    turnInfo.textContent = isMyTurn ? 'Giliran Anda' : 'Menunggu giliran ' + (current ? current.name : 'tim lain');
                }
            }

            drawDiceState(snapshot, turn, {
                canRoll,
                canAnswer,
                timeExpired,
                currentTeamName: current ? current.name : 'tim lain',
            });

            if (rollButton) {
                rollButton.disabled = !canRoll || isRolling || isAnswering;
                if (snapshot.room.status === 'LOBBY') {
                    rollButton.textContent = 'Menunggu Start';
                } else if (snapshot.room.status === 'PAUSED') {
                    rollButton.textContent = 'Game Dijeda';
                } else if (isRolling) {
                    rollButton.textContent = 'Mengocok Dadu';
                } else if (!isMyTurn || !turn) {
                    rollButton.textContent = 'Belum Giliran';
                } else if (isMyTurn && turn && turn.state === 'QUESTION_ACTIVE' && timeExpired) {
                    rollButton.textContent = 'Waktu Habis';
                } else if (canAnswer) {
                    rollButton.textContent = 'Jawab Pertanyaan';
                } else {
                    rollButton.textContent = 'Lempar Dadu';
                }
            }

            const showQuestion = canAnswer || (isMyTurn && turn && (turn.state === 'QUESTION_ACTIVE' || turn.state === 'MYSTERY_QUESTION_ACTIVE') && turn.question);
            if (questionBox && optionList) {
                questionBox.classList.toggle('hidden', !showQuestion);
                optionList.innerHTML = showQuestion ? turn.question.options.map((option) => (
                    '<button class="answer-button" data-option-id="' + option.id + '"' + (isAnswering ? ' disabled' : '') + '>' +
                    '<strong>' + escapeHtml(option.label) + '</strong>' +
                    '<span>' + escapeHtml(option.body) + '</span>' +
                    mediaHtml(option.media, 'option-player-media') +
                    '</button>'
                )).join('') : '';
                optionList.querySelectorAll('button').forEach((button) => {
                    button.disabled = button.disabled || !canAnswer;
                });
                const stem = document.querySelector('[data-question-stem]');
                if (stem && turn && turn.question) {
                    stem.textContent = turn.question.stem;
                }
                const meta = document.querySelector('[data-question-meta]');
                if (meta) {
                    meta.textContent = turn && turn.question
                        ? questionTypeLabel(turn.question.type) + ' / ' + String(turn.question.difficulty || 'MEDIUM')
                        : '';
                }
                const media = document.querySelector('[data-question-media]');
                if (media) {
                    media.innerHTML = turn && turn.question ? mediaHtml(turn.question.media, 'question-player-media') : '';
                }
            }

            if (mysteryChoiceBox) {
                mysteryChoiceBox.classList.toggle('hidden', !isMysteryChoice);
                if (mysterySelfButton) {
                    mysterySelfButton.disabled = !isMysteryChoice;
                }
                if (isMysteryChoice && mysteryOpponents) {
                    mysteryOpponents.innerHTML = (snapshot.teams || [])
                        .filter((item) => item.uuid !== config.teamUuid)
                        .map((item) => '<button class="answer-button" type="button" data-mystery-target="' + item.uuid + '"><strong>Serang</strong><span>' + escapeHtml(item.name) + '</span></button>')
                        .join('');
                }
            }
        }

        function drawDiceState(snapshot, turn, state) {
            if (dicePanel) {
                dicePanel.classList.toggle('ready', Boolean(state.canRoll));
                dicePanel.classList.toggle('rolling', isRolling);
                dicePanel.classList.toggle('question-active', Boolean(state.canAnswer));
            }

            if (diceDisplay && !isRolling) {
                diceDisplay.textContent = turn && turn.dice_value ? turn.dice_value : '?';
            }

            if (diceCaption) {
                if (isRolling) {
                    diceCaption.textContent = 'Dadu sedang dikocok';
                } else if (state.canRoll) {
                    diceCaption.textContent = 'Siap lempar';
                } else if (state.timeExpired) {
                    diceCaption.textContent = 'Waktu habis';
                } else if (state.canAnswer) {
                    diceCaption.textContent = 'Dadu keluar: ' + turn.dice_value;
                } else if (snapshot.room.status === 'FINISHED') {
                    diceCaption.textContent = 'Permainan selesai';
                } else if (snapshot.room.status === 'PAUSED') {
                    diceCaption.textContent = 'Game dijeda';
                } else {
                    diceCaption.textContent = 'Menunggu giliran';
                }
            }

            if (rollReason) {
                rollReason.textContent = rollStateMessage(snapshot, turn, state);
            }
        }

        function rollStateMessage(snapshot, turn, state) {
            if (snapshot.room.status === 'LOBBY') {
                return 'Guru belum menekan Start.';
            }

            if (snapshot.room.status === 'FINISHED') {
                return 'Permainan sudah selesai.';
            }

            if (snapshot.room.status === 'PAUSED') {
                return 'Guru sedang menjeda permainan.';
            }

            if (!turn) {
                return 'Menunggu sistem menyiapkan giliran.';
            }

            if (state.canRoll) {
                return 'Tekan tombol untuk membuka pertanyaan.';
            }

            if (!modeCan(snapshot, 'roll')) {
                return 'Aksi untuk mode ini sedang disiapkan.';
            }

            if (state.timeExpired) {
                return 'Waktu habis. Sistem akan memindahkan giliran.';
            }

            if (state.canAnswer) {
                return 'Pilih jawaban agar pion bergerak.';
            }

            return 'Sekarang giliran ' + state.currentTeamName + '.';
        }

        const originalRefresh = runtime.refresh;
        runtime.refresh = function () {
            return originalRefresh().then((snapshot) => {
                drawController();
                return snapshot;
            });
        };

        if (rollButton) {
            rollButton.addEventListener('click', function () {
                if (rollButton.disabled || isRolling) {
                    return;
                }

                isRolling = true;
                rollButton.disabled = true;
                rollDiceAnimation(diceDisplay);
                drawController();
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/roll', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isRolling = false;
                        drawController();
                    });
            });
        }

        if (optionList) {
            optionList.addEventListener('click', function (event) {
                const button = event.target.closest('[data-option-id]');
                if (!button || button.disabled || isAnswering) {
                    return;
                }
                isAnswering = true;
                drawController();
                runtime.setError('');
                const activeTurn = runtime.getSnapshot().current_turn;
                const endpoint = activeTurn && activeTurn.state === 'MYSTERY_QUESTION_ACTIVE' ? '/mystery/answer' : '/answer';
                jsonFetch('/api/v1/rooms/' + config.roomUuid + endpoint, {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, option_id: button.dataset.optionId}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isAnswering = false;
                        drawController();
                    });
            });
        }

        if (mysterySelfButton) {
            mysterySelfButton.addEventListener('click', function () {
                if (mysterySelfButton.disabled) {
                    return;
                }
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/mystery/choose', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, target: 'SELF'}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message));
            });
        }

        if (mysteryOpponents) {
            mysteryOpponents.addEventListener('click', function (event) {
                const button = event.target.closest('[data-mystery-target]');
                if (!button) {
                    return;
                }
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/mystery/choose', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, target: button.dataset.mysteryTarget}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message));
            });
        }

        drawController();
        window.setInterval(drawController, 700);
    }

    function modeCan(snapshot, action) {
        const actions = snapshot && snapshot.mode_state && Array.isArray(snapshot.mode_state.actions)
            ? snapshot.mode_state.actions
            : ['roll', 'answer'];

        return actions.includes(action);
    }

    function rollDiceAnimation(element) {
        if (!element) {
            return;
        }

        let ticks = 0;
        const interval = window.setInterval(() => {
            element.textContent = String(Math.floor(Math.random() * 6) + 1);
            ticks += 1;
            if (ticks >= 12) {
                window.clearInterval(interval);
            }
        }, 80);
    }

    function mediaHtml(media, className) {
        const images = media && Array.isArray(media.images) ? media.images : [];
        if (images.length === 0) {
            return '';
        }

        return '<div class="' + className + '">' + images.map((image) => (
            '<img src="' + escapeHtml(image) + '" alt="">'
        )).join('') + '</div>';
    }

    function questionTypeLabel(type) {
        switch (String(type || '').toUpperCase()) {
            case 'TRUE_FALSE':
                return 'Benar/Salah';
            case 'MULTIPLE_CHOICE':
                return 'Pilihan Ganda';
            default:
                return 'Soal';
        }
    }

    function statusText(status) {
        switch (status) {
            case 'LOBBY':
                return 'Start';
            case 'PLAYING':
                return 'Sedang Bermain';
            case 'PAUSED':
                return 'Dijeda';
            case 'FINISHED':
                return 'Selesai';
            default:
                return status || 'Tidak Aktif';
        }
    }

    window.UlarTangga = {
        teacherControl,
        projector,
        controller,
    };
})();
