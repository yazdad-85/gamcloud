(function () {
    function csrfHeaders() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta || !meta.content) {
            return {};
        }

        return {'X-CSRF-TOKEN': meta.content};
    }

    function jsonFetch(url, options) {
        const method = (options && options.method) || 'GET';
        const idempotencyKey = method === 'POST' ? crypto.randomUUID() : undefined;
        const extraHeaders = Object.assign(
            {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            idempotencyKey ? {'Idempotency-Key': idempotencyKey} : {},
            method === 'POST' ? csrfHeaders() : {},
            (options && options.headers) || {}
        );

        return fetch(url, Object.assign({}, options || {}, {
            method,
            headers: extraHeaders,
        }))
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

    const displayPositions = new Map();

    function effectivePosition(team) {
        return displayPositions.has(team.uuid) ? displayPositions.get(team.uuid) : Number(team.position || 1);
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
            const position = effectivePosition(team);
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
                (!special && tile !== 1 && tile !== total && (tile % 3 === 0 || tile % 5 === 2)) ? 'tile-theme-icon' : '',
                (teamsByPosition[tile] || []).some((team) => team.uuid === currentTeamUuid) ? 'tile-current' : '',
                recentMovement && (recentMovement.from === tile || recentMovement.to === tile || recentMovement.landed === tile) ? 'tile-recent' : '',
            ].filter(Boolean).join(' ');

            return '<div class="' + classes + '" data-tile="' + tile + '">' +
                '<div class="tile-number">' + tile + '</div>' +
                (special ? '<div class="tile-special">' + special + '</div>' : '') +
                '<div class="tile-teams">' + pawns + '</div>' +
            '</div>';
        }).join('');

        element.innerHTML = '<div class="board-atmosphere" aria-hidden="true"></div>' +
            '<div class="board-grid">' + tiles + '</div>' +
            '<svg class="board-path-layer" data-board-paths aria-hidden="true"></svg>';
        window.requestAnimationFrame(() => renderBoardPaths(element, snapshot));
    }

    const THEME_ICON_SHAPES = {
        classic_arena: '<path d="M12 2 20 5v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5Z"/>',
        jungle_quest: '<path d="M12 2C4 6 4 14 4 20 10 20 20 14 20 4 16 4 14 3 12 2Z"/>',
        space_mission: '<path d="M12 2 14 10 22 12 14 14 12 22 10 14 2 12 10 10Z"/>',
        ocean_quest: '<path d="M2 15c3-4 5-4 8 0s5 4 8 0 5-4 4 0" fill="none" stroke="black" stroke-width="2.4" stroke-linecap="round"/>',
        city_challenge: '<path d="M3 21V10H8V21M10 21V4H15V21M17 21V13H21V21" fill="none" stroke="black" stroke-width="2"/>',
        lab_challenge: '<path d="M9 2h6v6l5 12c.8 1.8-.5 3-2.4 3H6.4C4.5 23 3.2 21.8 4 20l5-12Z"/>',
    };

    const THEME_ATMOSPHERE = {
        classic_arena: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 220" preserveAspectRatio="xMidYMax meet"><path fill="%23ffffff" fill-opacity=".18" d="M0 220V140l60-20 40 30 50-50 70 40 40-25 80 45 60-35 70 30 50-40 80 50 70-20 90 35V220Z"/><circle cx="680" cy="48" r="28" fill="%23ffffff" fill-opacity=".14"/></svg>',
        jungle_quest: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 220" preserveAspectRatio="xMidYMax meet"><path fill="%23ffffff" fill-opacity=".16" d="M0 220C40 120 90 90 140 140c40-70 90-90 140-40 50-80 120-70 160-10 55-75 130-60 170 10 40-55 110-50 150 20V220Z"/><ellipse cx="120" cy="70" rx="50" ry="24" fill="%23ffffff" fill-opacity=".1"/><ellipse cx="620" cy="60" rx="70" ry="28" fill="%23ffffff" fill-opacity=".1"/></svg>',
        space_mission: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 220" preserveAspectRatio="xMidYMax meet"><circle cx="80" cy="40" r="2" fill="%23ffffff" fill-opacity=".55"/><circle cx="160" cy="70" r="1.5" fill="%23ffffff" fill-opacity=".45"/><circle cx="260" cy="30" r="2.2" fill="%23ffffff" fill-opacity=".5"/><circle cx="420" cy="55" r="1.6" fill="%23ffffff" fill-opacity=".4"/><circle cx="520" cy="25" r="2" fill="%23ffffff" fill-opacity=".55"/><circle cx="650" cy="60" r="1.8" fill="%23ffffff" fill-opacity=".45"/><circle cx="740" cy="35" r="2.4" fill="%23ffffff" fill-opacity=".5"/><circle cx="700" cy="90" r="36" fill="%23ffffff" fill-opacity=".08"/><path fill="%23ffffff" fill-opacity=".12" d="M0 220c80-30 140-70 220-50s150 20 240-10 160 10 240 40 80 20 100 20V220Z"/></svg>',
        ocean_quest: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 220" preserveAspectRatio="xMidYMax meet"><path fill="none" stroke="%23ffffff" stroke-opacity=".28" stroke-width="6" d="M0 150c60-30 120-30 180 0s120 30 180 0 120-30 180 0 120 30 180 0 80-10 80-10"/><path fill="none" stroke="%23ffffff" stroke-opacity=".18" stroke-width="5" d="M0 180c70-24 130-24 200 0s140 24 210 0 140-24 210 0 120 10 180 10"/><circle cx="640" cy="50" r="34" fill="%23ffffff" fill-opacity=".12"/></svg>',
        city_challenge: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 220" preserveAspectRatio="xMidYMax meet"><g fill="%23ffffff" fill-opacity=".2"><rect x="30" y="90" width="70" height="130"/><rect x="110" y="50" width="55" height="170"/><rect x="175" y="75" width="80" height="145"/><rect x="270" y="40" width="48" height="180"/><rect x="330" y="85" width="95" height="135"/><rect x="440" y="55" width="60" height="165"/><rect x="515" y="95" width="75" height="125"/><rect x="605" y="35" width="52" height="185"/><rect x="670" y="70" width="90" height="150"/></g><g fill="%23fbbf24" fill-opacity=".35"><rect x="125" y="70" width="8" height="10"/><rect x="145" y="70" width="8" height="10"/><rect x="285" y="60" width="8" height="10"/><rect x="455" y="75" width="8" height="10"/><rect x="620" y="55" width="8" height="10"/><rect x="640" y="55" width="8" height="10"/></g><path fill="%2360a5fa" fill-opacity=".25" d="M175 75h80v20H175z"/><path fill="%23ffffff" fill-opacity=".15" d="M350 85h40v30h-40z"/><text x="360" y="108" fill="%23ffffff" fill-opacity=".35" font-size="14" font-family="sans-serif">EDU</text></svg>',
        lab_challenge: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 220" preserveAspectRatio="xMidYMax meet"><path fill="%23ffffff" fill-opacity=".14" d="M120 40h40v50l50 110H70l50-110z"/><path fill="%23ffffff" fill-opacity=".1" d="M360 30h50v60l60 120H300l60-120z"/><circle cx="620" cy="70" r="40" fill="%23ffffff" fill-opacity=".08"/><circle cx="700" cy="110" r="18" fill="%23ffffff" fill-opacity=".12"/><path fill="none" stroke="%23ffffff" stroke-opacity=".2" stroke-width="4" d="M40 190c80-20 140 20 220 0s150-20 230 0 150 20 230 0"/></svg>',
    };

    function themeIconMaskUrl(themeKey) {
        const shape = THEME_ICON_SHAPES[themeKey] || THEME_ICON_SHAPES.classic_arena;
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' + shape + '</svg>';

        return 'url("data:image/svg+xml,' + encodeURIComponent(svg) + '")';
    }

    function themeAtmosphereUrl(themeKey) {
        const svg = THEME_ATMOSPHERE[themeKey] || THEME_ATMOSPHERE.classic_arena;

        return 'url("data:image/svg+xml,' + svg + '")';
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
            '--board-icon': themeIconMaskUrl(theme && theme.key),
            '--board-atmosphere': themeAtmosphereUrl(theme && theme.key),
        }).forEach(([key, value]) => element.style.setProperty(key, value));

        // Pawn avatars also render in the leaderboard, which sits outside
        // .board in the DOM and would otherwise never see this variable.
        document.documentElement.style.setProperty('--board-accent', palette.accent || '#f97316');
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

        const dx = to.x - from.x;
        const dy = to.y - from.y;
        const length = Math.hypot(dx, dy) || 1;
        const nx = -dy / length;
        const ny = dx / length;
        const offset = Math.min(12, Math.max(7, length * 0.05));
        const rungCount = Math.max(3, Math.round(length / 28));
        const rungs = Array.from({length: rungCount}, (_, index) => {
            const t = (index + 1) / (rungCount + 1);
            const cx = from.x + dx * t;
            const cy = from.y + dy * t;
            return '<line class="ladder-rung" x1="' + (cx - nx * offset) + '" y1="' + (cy - ny * offset) +
                '" x2="' + (cx + nx * offset) + '" y2="' + (cy + ny * offset) + '"></line>';
        }).join('');

        return '<g class="ladder-path ladder-path-rich" filter="url(#pathShadow)">' +
            '<line class="ladder-rail ladder-rail-back" x1="' + (from.x - nx * offset) + '" y1="' + (from.y - ny * offset) +
            '" x2="' + (to.x - nx * offset) + '" y2="' + (to.y - ny * offset) + '"></line>' +
            '<line class="ladder-rail ladder-rail-back" x1="' + (from.x + nx * offset) + '" y1="' + (from.y + ny * offset) +
            '" x2="' + (to.x + nx * offset) + '" y2="' + (to.y + ny * offset) + '"></line>' +
            '<line class="ladder-rail" x1="' + (from.x - nx * offset) + '" y1="' + (from.y - ny * offset) +
            '" x2="' + (to.x - nx * offset) + '" y2="' + (to.y - ny * offset) + '"></line>' +
            '<line class="ladder-rail" x1="' + (from.x + nx * offset) + '" y1="' + (from.y + ny * offset) +
            '" x2="' + (to.x + nx * offset) + '" y2="' + (to.y + ny * offset) + '"></line>' +
            rungs +
            '</g>';
    }

    function renderSnakePath(boardElement, bounds, snake) {
        const from = tileCenter(boardElement, bounds, snake.from);
        const to = tileCenter(boardElement, bounds, snake.to);
        if (!from || !to) {
            return '';
        }

        const dx = to.x - from.x;
        const dy = to.y - from.y;
        const length = Math.hypot(dx, dy) || 1;
        const px = -dy / length;
        const py = dx / length;
        const bulge = Math.min(92, Math.max(28, length * 0.38));
        const c1x = from.x + dx * 0.28 + px * bulge;
        const c1y = from.y + dy * 0.28 + py * bulge;
        const c2x = from.x + dx * 0.72 - px * bulge * 0.85;
        const c2y = from.y + dy * 0.72 - py * bulge * 0.85;
        const d = 'M ' + from.x + ' ' + from.y +
            ' C ' + c1x + ' ' + c1y + ', ' + c2x + ' ' + c2y + ', ' + to.x + ' ' + to.y;
        const headAngle = Math.atan2(from.y - c1y, from.x - c1x) * 180 / Math.PI;
        const tailAngle = Math.atan2(to.y - c2y, to.x - c2x) * 180 / Math.PI;

        return '<g class="snake-path snake-path-rich" filter="url(#pathShadow)">' +
            '<path class="snake-body-outline" d="' + d + '"></path>' +
            '<path class="snake-body" d="' + d + '"></path>' +
            '<path class="snake-body-shine" d="' + d + '"></path>' +
            '<g class="snake-head-group" transform="translate(' + from.x + ' ' + from.y + ') rotate(' + headAngle + ')">' +
            '<ellipse class="snake-head" cx="2" cy="0" rx="15" ry="11"></ellipse>' +
            '<circle class="snake-eye" cx="7" cy="-4.2" r="2.4"></circle>' +
            '<circle class="snake-eye" cx="7" cy="4.2" r="2.4"></circle>' +
            '<circle class="snake-eye-dot" cx="7.8" cy="-4.2" r="1.05"></circle>' +
            '<circle class="snake-eye-dot" cx="7.8" cy="4.2" r="1.05"></circle>' +
            '<path class="snake-tongue" d="M14 0 L21 -3.5 M14 0 L21 3.5"></path>' +
            '</g>' +
            '<g class="snake-tail-group" transform="translate(' + to.x + ' ' + to.y + ') rotate(' + tailAngle + ')">' +
            '<path class="snake-tail" d="M0 0 L-10 -3.5 L-16 0 L-10 3.5 Z"></path>' +
            '</g>' +
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
        syncProjectorTension(countdown, snapshot);
    }

    function syncProjectorTension(countdown, snapshot) {
        if (!document.body.classList.contains('projector') || !window.GameFx || !GameFx.sound) {
            return;
        }
        const roomPlaying = snapshot && snapshot.room && snapshot.room.status === 'PLAYING';
        const active = roomPlaying
            && countdown.remaining !== null
            && countdown.remaining > 0
            && countdown.remaining <= 10;
        if (active) {
            GameFx.sound.startTension({remaining: countdown.remaining});
        } else {
            GameFx.sound.stopTension();
        }
    }

    function playProjectorCue(name) {
        if (!document.body.classList.contains('projector') || !window.GameFx || !GameFx.sound || !GameFx.sound.play) {
            return;
        }
        GameFx.sound.play(name);
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
            const tokenQuery = config.projectorToken
                ? ('?t=' + encodeURIComponent(config.projectorToken))
                : '';
            return jsonFetch('/api/v1/rooms/' + config.roomUuid + '/state' + tokenQuery)
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

    const SEQUENCED_EVENTS = new Set([
        'dice.rolled',
        'answer.resolved',
        'tile.special_triggered',
        'mystery.resolved',
        'snake.redemption_started',
        'snake.redemption_resolved',
        'ladder.challenge_started',
        'ladder.challenge_resolved',
        'game.finished',
    ]);

    function projector(config) {
        const seenEvents = new Set((config.snapshot.events || []).map((event) => event.event_id));
        const overlayQueue = [];
        let overlayBusy = false;
        let sequenceBusy = Promise.resolve();

        createRuntime(Object.assign({interval: 1600}, config, {
            onSnapshot(snapshot) {
                (snapshot.events || []).forEach((event) => {
                    if (seenEvents.has(event.event_id)) {
                        return;
                    }
                    seenEvents.add(event.event_id);

                    if (SEQUENCED_EVENTS.has(event.event)) {
                        sequenceBusy = sequenceBusy.then(() => runSequencedEvent(event, snapshot));
                        return;
                    }

                    const item = overlayForEvent(event, snapshot);
                    if (item) {
                        overlayQueue.push(item);
                    }
                });
                playOverlayQueue(overlayQueue, () => overlayBusy, (value) => {
                    overlayBusy = value;
                });
            },
        }));
    }

    function runSequencedEvent(event, snapshot) {
        switch (event.event) {
            case 'dice.rolled':
                return runDiceRolledSequence(event, snapshot);
            case 'answer.resolved':
                return runMovementSequence(event, snapshot, event.payload.team_uuid, event.payload.is_correct);
            case 'tile.special_triggered':
                return runTileEffectSequence(event, snapshot);
            case 'mystery.resolved':
                return runMovementSequence(event, snapshot, event.payload.affected_team_uuid, null);
            case 'snake.redemption_started':
                playProjectorCue('snake');
                return GameFx.banner({
                    tone: 'trap',
                    icon: '🐍',
                    title: 'Ular! Soal penyelamat',
                    body: teamNameByUuid(event.payload.team_uuid, snapshot),
                    durationMs: 1800,
                });
            case 'ladder.challenge_started':
                playProjectorCue('ladder');
                return GameFx.banner({
                    tone: 'bonus',
                    icon: '🪜',
                    title: 'Tangga! Soal klaim',
                    body: teamNameByUuid(event.payload.team_uuid, snapshot),
                    durationMs: 1800,
                });
            case 'snake.redemption_resolved':
            case 'ladder.challenge_resolved':
                playProjectorCue(event.event.indexOf('snake') === 0
                    ? (event.payload.is_correct ? 'correct' : 'snake')
                    : (event.payload.is_correct ? 'ladder' : 'wrong'));
                return runMovementSequence(event, snapshot, event.payload.team_uuid, event.payload.is_correct);
            case 'game.finished':
                return runWinnerSequence(event, snapshot);
            default:
                return Promise.resolve();
        }
    }

    function runDiceRolledSequence(event, snapshot) {
        const dieMount = document.querySelector('[data-projector-die]');
        const panel = document.querySelector('[data-projector-dice-panel]');
        const label = document.querySelector('[data-projector-die-label]');
        if (!dieMount || !panel) {
            return Promise.resolve();
        }

        if (label) {
            label.textContent = teamNameByUuid(event.payload.team_uuid, snapshot) + ' melempar dadu...';
        }
        panel.classList.remove('hidden');

        return GameFx.rollDie(dieMount, {
            resultPromise: Promise.resolve(event.payload.dice_value),
            minDurationMs: 1400,
        }).then(() => new Promise((resolve) => {
            window.setTimeout(() => {
                panel.classList.add('hidden');
                resolve();
            }, 700);
        }));
    }

    function runMovementSequence(event, snapshot, teamUuid, isCorrectOrNull) {
        const movement = event.payload && event.payload.movement;
        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === teamUuid);
        const hasWalk = Boolean(movement && board && team && Number(movement.from) !== Number(movement.to));
        const walk = hasWalk ? animateMovementEvent(event, snapshot, teamUuid) : Promise.resolve();

        return walk.then(() => {
            if (!team || !board) {
                return null;
            }
            if (isCorrectOrNull === null) {
                return runMysteryBanner(event, snapshot);
            }
            const point = viewportTileCenter(board, Number(movement ? movement.to : team.position));
            if (!point) {
                return null;
            }
            return isCorrectOrNull
                ? GameFx.celebrateCorrect(point.x, point.y, team.name)
                : GameFx.celebrateWrong(team.name);
        });
    }

    function runMysteryBanner(event, snapshot) {
        const overlay = overlayForEvent(event, snapshot);
        if (!overlay) {
            return Promise.resolve();
        }
        const tone = overlay.tone === 'success' ? 'correct' : 'wrong';
        const icon = overlay.tone === 'success' ? '🎁' : '💥';
        return GameFx.banner({tone, icon, title: overlay.title, body: overlay.body, durationMs: 2000});
    }

    function runTileEffectSequence(event, snapshot) {
        const board = document.querySelector('[data-board]');
        const payload = event.payload || {};
        const effect = payload.effect || {};
        const movement = payload.movement || {};
        const targetTile = Number(effect.to != null ? effect.to : movement.to);
        if (!board || !targetTile) {
            return Promise.resolve();
        }
        const point = viewportTileCenter(board, targetTile);
        if (!point) {
            return Promise.resolve();
        }
        const type = String(effect.type || '').toUpperCase();
        const overlay = specialOverlay(effect, payload.team_uuid, snapshot);
        return GameFx.tileEffect(point.x, point.y, type, overlay.body);
    }

    function runWinnerSequence(event, snapshot) {
        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === event.payload.winner_team_uuid);
        if (!board || !team) {
            return Promise.resolve();
        }
        const point = viewportTileCenter(board, Number(team.position));
        if (!point) {
            return Promise.resolve();
        }
        return GameFx.celebrateWinner(point.x, point.y, team.name);
    }

    function movementFeedbackText(payload) {
        const movement = payload.movement || {};
        if (movement.special === 'LADDER') {
            return {tone: 'success', body: 'Naik tangga ke kotak ' + movement.to};
        }
        if (movement.special === 'SNAKE') {
            return {tone: 'danger', body: 'Kena ular, turun ke kotak ' + movement.to};
        }
        if (movement.finish_bounced) {
            return {tone: 'info', body: 'Melewati finish, memantul ke ' + movement.to};
        }
        if (movement.special === 'TRAP') {
            return {tone: 'danger', body: 'Trap aktif, mundur ke kotak ' + movement.to};
        }
        return null;
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

    function animateMovementEvent(event, snapshot, teamUuid) {
        const movement = event.payload && event.payload.movement;
        if (!movement || Number(movement.from) === Number(movement.to)) {
            return Promise.resolve();
        }

        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === teamUuid);
        if (!board || !team) {
            return Promise.resolve();
        }

        const tilePath = movementTilePath(movement, snapshot);
        const points = tilePath
            .map((tile) => viewportTileCenter(board, tile))
            .filter(Boolean);
        if (points.length < 2) {
            return Promise.resolve();
        }

        displayPositions.set(team.uuid, Number(movement.from));
        renderBoard(board, snapshot);

        const mover = document.createElement('div');
        mover.className = 'board-mover';
        mover.classList.add('avatar-' + avatarClass(team.avatar));
        mover.style.setProperty('--team-color', team.color);
        mover.style.setProperty('--board-accent', (snapshot.board && snapshot.board.theme && snapshot.board.theme.palette && snapshot.board.theme.palette.accent) || '#f97316');
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

        return animation.finished
            .catch(() => null)
            .finally(() => {
                mover.remove();
                displayPositions.delete(team.uuid);
                renderBoard(board, snapshot);
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
            if (to < landed) {
                for (let tile = landed - 1; tile >= to; tile--) {
                    path.push(tile);
                }
            } else {
                for (let tile = landed + 1; tile <= to; tile++) {
                    path.push(tile);
                }
            }
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
        if (item.tone === 'winner') {
            playProjectorCue('winner');
        } else if (item.tone === 'success') {
            playProjectorCue('correct');
        } else if (item.tone === 'danger') {
            playProjectorCue('wrong');
        } else if (item.tone === 'dice') {
            playProjectorCue('mystery');
        }

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
        const teamAvatarBadge = document.querySelector('[data-team-avatar]');
        const teamAvatarInitials = document.querySelector('[data-team-avatar-initials]');
        const moveFeedback = document.querySelector('[data-move-feedback]');
        let isRolling = false;
        let isAnswering = false;
        let isChoosingMystery = false;
        let moveFeedbackTimer = null;
        const seenTeamEvents = new Set((config.snapshot.events || []).map((event) => event.event_id));

        function showMoveFeedback(text, tone) {
            if (!moveFeedback) {
                return;
            }
            moveFeedback.textContent = text;
            moveFeedback.className = 'move-feedback tone-' + tone;
            if (moveFeedbackTimer) {
                window.clearTimeout(moveFeedbackTimer);
            }
            moveFeedbackTimer = window.setTimeout(() => {
                moveFeedback.classList.add('hidden');
            }, 4500);
        }

        function checkMoveFeedback(snapshot) {
            (snapshot.events || []).forEach((event) => {
                if (seenTeamEvents.has(event.event_id)) {
                    return;
                }
                seenTeamEvents.add(event.event_id);
                const payload = event.payload || {};
                if (payload.team_uuid !== config.teamUuid) {
                    return;
                }
                if (event.event === 'answer.resolved') {
                    const feedback = movementFeedbackText(payload);
                    if (feedback) {
                        showMoveFeedback(feedback.body, feedback.tone);
                    }
                } else if (event.event === 'tile.special_triggered') {
                    const effectType = String((payload.effect || {}).type || '').toUpperCase();
                    if (effectType === 'BONUS' || effectType === 'SAFE' || effectType === 'SAFE_BLOCK') {
                        const overlay = specialOverlay(payload.effect || {}, payload.team_uuid, snapshot);
                        showMoveFeedback(overlay.body, overlay.tone);
                    }
                }
            });
        }

        function drawController() {
            const snapshot = runtime.getSnapshot();
            checkMoveFeedback(snapshot);
            const turn = snapshot.current_turn;
            const isMyTurn = turn && turn.team_uuid === config.teamUuid;
            const team = (snapshot.teams || []).find((item) => item.uuid === config.teamUuid);
            const current = (snapshot.teams || []).find((item) => item.uuid === snapshot.room.current_team_uuid);

            if (teamAvatarBadge) {
                teamAvatarBadge.className = 'team-avatar-badge' + (team ? ' avatar-' + avatarClass(team.avatar) : '');
                teamAvatarBadge.style.setProperty('--team-color', team ? team.color : '#64748b');
            }
            if (teamAvatarInitials) {
                teamAvatarInitials.textContent = team ? teamInitials(team.name) : '';
            }
            const canRoll = modeCan(snapshot, 'roll') && snapshot.room.status === 'PLAYING' && isMyTurn && turn && turn.state === 'ROLL_READY';
            const timeExpired = isClientTurnExpired(snapshot);
            const isMysteryChoice = Boolean(isMyTurn && turn && turn.state === 'MYSTERY_CHOICE_PENDING');
            const canAnswer = modeCan(snapshot, 'answer') && isMyTurn && turn
                && (turn.state === 'QUESTION_ACTIVE' || turn.state === 'MYSTERY_QUESTION_ACTIVE'
                    || turn.state === 'SNAKE_REDEMPTION_ACTIVE' || turn.state === 'LADDER_CHALLENGE_ACTIVE')
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

            const showQuestion = canAnswer || (isMyTurn && turn
                && (turn.state === 'QUESTION_ACTIVE' || turn.state === 'MYSTERY_QUESTION_ACTIVE'
                    || turn.state === 'SNAKE_REDEMPTION_ACTIVE' || turn.state === 'LADDER_CHALLENGE_ACTIVE')
                && turn.question);
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
                const questionTitle = document.querySelector('[data-question-title]');
                if (questionTitle && turn) {
                    if (turn.state === 'SNAKE_REDEMPTION_ACTIVE') {
                        questionTitle.textContent = 'Soal penyelamat ular (HARD)';
                    } else if (turn.state === 'LADDER_CHALLENGE_ACTIVE') {
                        questionTitle.textContent = 'Soal klaim tangga (HARD)';
                    } else {
                        questionTitle.textContent = 'Pertanyaan';
                    }
                }
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
                    mysterySelfButton.disabled = !isMysteryChoice || isChoosingMystery;
                }
                if (mysteryOpponents) {
                    mysteryOpponents.innerHTML = isMysteryChoice ? (snapshot.teams || [])
                        .filter((item) => item.uuid !== config.teamUuid)
                        .map((item) => '<button class="answer-button" type="button" data-mystery-target="' + item.uuid + '"' + (isChoosingMystery ? ' disabled' : '') + '><strong>Serang</strong><span>' + escapeHtml(item.name) + '</span></button>')
                        .join('') : '';
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
                GameFx.setDieResting(diceDisplay, turn && turn.dice_value ? Number(turn.dice_value) : null);
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
                drawController();
                runtime.setError('');

                const rollRequest = jsonFetch('/api/v1/rooms/' + config.roomUuid + '/roll', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid}),
                });
                const diceValuePromise = rollRequest.then((data) => Number(data.current_turn.dice_value));
                const cubeSettled = GameFx.rollDie(diceDisplay, {resultPromise: diceValuePromise, minDurationMs: 1200});

                Promise.all([
                    rollRequest.then(runtime.refresh).catch((error) => runtime.setError(error.message)),
                    cubeSettled,
                ]).finally(() => {
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
                let endpoint = '/answer';
                if (activeTurn && activeTurn.state === 'MYSTERY_QUESTION_ACTIVE') {
                    endpoint = '/mystery/answer';
                } else if (activeTurn && (activeTurn.state === 'SNAKE_REDEMPTION_ACTIVE' || activeTurn.state === 'LADDER_CHALLENGE_ACTIVE')) {
                    endpoint = '/board-challenge/answer';
                }
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
                if (mysterySelfButton.disabled || isChoosingMystery) {
                    return;
                }
                isChoosingMystery = true;
                drawController();
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/mystery/choose', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, target: 'SELF'}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isChoosingMystery = false;
                        drawController();
                    });
            });
        }

        if (mysteryOpponents) {
            mysteryOpponents.addEventListener('click', function (event) {
                const button = event.target.closest('[data-mystery-target]');
                if (!button || button.disabled || isChoosingMystery) {
                    return;
                }
                isChoosingMystery = true;
                drawController();
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/mystery/choose', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, target: button.dataset.mysteryTarget}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isChoosingMystery = false;
                        drawController();
                    });
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
