(function () {
    'use strict';

    var AudioCtx = window.AudioContext || window.webkitAudioContext;
    var audioCtx = null;

    function ensureAudioCtx() {
        if (!AudioCtx) {
            return null;
        }
        if (!audioCtx) {
            audioCtx = new AudioCtx();
        }
        return audioCtx;
    }

    var MUTE_STORAGE_KEY = 'edugame.projector.muted';
    var muted = false;
    var tensionTimer = null;
    var tensionPulseMs = 900;

    try {
        muted = window.localStorage.getItem(MUTE_STORAGE_KEY) === '1';
    } catch (err) {
        muted = false;
    }

    function setMuted(value) {
        muted = !!value;
        try {
            window.localStorage.setItem(MUTE_STORAGE_KEY, muted ? '1' : '0');
        } catch (err) {
            // ignore quota / private mode
        }
        if (muted) {
            stopTension();
        }
        return muted;
    }

    function isMuted() {
        return muted;
    }

    function unlockSound() {
        var ctx = ensureAudioCtx();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
    }

    function tone(freq, startOffset, duration, waveType, gainPeak) {
        if (muted) {
            return;
        }
        var ctx = ensureAudioCtx();
        if (!ctx || ctx.state !== 'running') {
            return;
        }
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = waveType || 'sine';
        osc.frequency.value = freq;
        var startAt = ctx.currentTime + startOffset;
        gain.gain.setValueAtTime(0.0001, startAt);
        gain.gain.linearRampToValueAtTime(gainPeak || 0.18, startAt + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, startAt + duration);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(startAt);
        osc.stop(startAt + duration + 0.02);
    }

    function mysterySweep() {
        if (muted) {
            return;
        }
        var ctx = ensureAudioCtx();
        if (!ctx || ctx.state !== 'running') {
            return;
        }
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        var startAt = ctx.currentTime;
        osc.frequency.setValueAtTime(300, startAt);
        osc.frequency.exponentialRampToValueAtTime(720, startAt + 0.5);
        gain.gain.setValueAtTime(0.0001, startAt);
        gain.gain.linearRampToValueAtTime(0.16, startAt + 0.08);
        gain.gain.exponentialRampToValueAtTime(0.001, startAt + 0.55);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(startAt);
        osc.stop(startAt + 0.6);
    }

    function ladderClimb() {
        [392, 493.88, 587.33, 784].forEach(function (freq, i) {
            tone(freq, i * 0.09, 0.2, 'triangle', 0.18);
        });
    }

    function snakeDrop() {
        [440, 330, 220, 140].forEach(function (freq, i) {
            tone(freq, i * 0.1, 0.24, 'sawtooth', 0.15);
        });
    }

    function tensionPulse() {
        if (muted) {
            return;
        }
        tone(180, 0, 0.12, 'square', 0.07);
        tone(240, 0.08, 0.1, 'square', 0.05);
    }

    function startTension(options) {
        if (muted) {
            return;
        }
        unlockSound();
        var remaining = options && typeof options.remaining === 'number' ? options.remaining : null;
        var nextMs = remaining !== null && remaining <= 4 ? 420 : remaining !== null && remaining <= 7 ? 620 : 900;
        if (tensionTimer && tensionPulseMs === nextMs) {
            return;
        }
        stopTension();
        tensionPulseMs = nextMs;
        tensionPulse();
        tensionTimer = window.setInterval(function () {
            if (muted) {
                stopTension();
                return;
            }
            tensionPulse();
        }, tensionPulseMs);
    }

    function stopTension() {
        if (tensionTimer) {
            window.clearInterval(tensionTimer);
            tensionTimer = null;
        }
    }

    var SOUND_LIBRARY = {
        dice: function () {
            for (var i = 0; i < 5; i++) {
                tone(180 + Math.random() * 90, i * 0.09, 0.06, 'square', 0.08);
            }
        },
        correct: function () {
            [523.25, 659.25, 783.99].forEach(function (freq, i) {
                tone(freq, i * 0.11, 0.22, 'triangle', 0.2);
            });
        },
        wrong: function () {
            tone(160, 0, 0.35, 'sawtooth', 0.18);
        },
        bonus: function () {
            [660, 990].forEach(function (freq, i) {
                tone(freq, i * 0.09, 0.16, 'square', 0.16);
            });
        },
        points: function () {
            SOUND_LIBRARY.bonus();
        },
        trap: function () {
            [400, 260].forEach(function (freq, i) {
                tone(freq, i * 0.1, 0.22, 'sawtooth', 0.16);
            });
        },
        snake: function () {
            snakeDrop();
        },
        ladder: function () {
            ladderClimb();
        },
        mystery: function () {
            mysterySweep();
        },
        safe: function () {
            tone(880, 0, 0.3, 'sine', 0.14);
        },
        shield: function () {
            tone(740, 0, 0.12, 'triangle', 0.16);
            tone(980, 0.1, 0.22, 'sine', 0.14);
        },
        winner: function () {
            [523.25, 659.25, 783.99, 1046.5].forEach(function (freq, i) {
                tone(freq, i * 0.14, 0.3, 'triangle', 0.22);
            });
        },
    };

    var SOUND_ALIASES = {
        points: 'points',
        shield: 'shield',
        ladder: 'ladder',
        snake: 'snake',
    };

    function playSoundSafe(name) {
        if (muted) {
            return;
        }
        var key = SOUND_ALIASES[name] || name;
        var fn = SOUND_LIBRARY[key];
        if (fn) {
            unlockSound();
            fn();
        }
    }

    var FACE_NAMES = {1: 'front', 2: 'right', 3: 'top', 4: 'bottom', 5: 'left', 6: 'back'};

    var DIE_FACE_ROTATIONS = {
        1: {x: 0, y: 0},
        2: {x: 0, y: -90},
        3: {x: -90, y: 0},
        4: {x: 90, y: 0},
        5: {x: 0, y: 90},
        6: {x: 0, y: 180},
    };

    var dieStates = new WeakMap();

    function buildDieFaceHtml(value) {
        var pips = '';
        for (var i = 0; i < 9; i++) {
            pips += '<span class="fx-die-pip"></span>';
        }
        return '<div class="fx-die-face fx-die-face-' + FACE_NAMES[value] + '" data-value="' + value + '">' + pips + '</div>';
    }

    function mountDie(containerEl) {
        if (dieStates.has(containerEl)) {
            return dieStates.get(containerEl);
        }

        containerEl.innerHTML = '<div class="fx-die-scene"><div class="fx-die-cube">' +
            [1, 2, 3, 4, 5, 6].map(buildDieFaceHtml).join('') +
            '</div></div>';

        var state = {
            cube: containerEl.querySelector('.fx-die-cube'),
            rx: 0,
            ry: 0,
            raf: null,
            rolling: false,
            currentValue: null,
        };
        dieStates.set(containerEl, state);

        return state;
    }

    function getDieState(containerEl) {
        return dieStates.get(containerEl) || mountDie(containerEl);
    }

    function tumbleTick(state) {
        state.rx = (state.rx + 41) % 360;
        state.ry = (state.ry + 29) % 360;
        state.cube.classList.remove('settling');
        state.cube.style.transform = 'rotateX(' + state.rx + 'deg) rotateY(' + state.ry + 'deg)';
        state.raf = window.requestAnimationFrame(function () {
            tumbleTick(state);
        });
    }

    function settleOnValue(state, value) {
        var target = DIE_FACE_ROTATIONS[value] || DIE_FACE_ROTATIONS[1];
        state.cube.classList.add('settling');
        state.cube.style.transform = 'rotateX(' + (target.x + 720) + 'deg) rotateY(' + (target.y + 720) + 'deg)';
        state.rx = ((target.x % 360) + 360) % 360;
        state.ry = ((target.y % 360) + 360) % 360;
        state.currentValue = value;
    }

    function setDieResting(containerEl, value) {
        var state = getDieState(containerEl);
        if (state.rolling) {
            return;
        }
        var target = value ? Number(value) : (state.currentValue || 1);
        if (state.currentValue === target) {
            return;
        }
        settleOnValue(state, target);
    }

    function rollDie(containerEl, options) {
        var state = getDieState(containerEl);
        if (state.rolling) {
            return state.rollPromise;
        }

        var minDurationMs = (options && options.minDurationMs) || 1200;
        var resultPromise = Promise.resolve(options && options.resultPromise).then(function (value) {
            return value == null ? (state.currentValue || 1) : value;
        });

        state.rolling = true;
        window.cancelAnimationFrame(state.raf);
        tumbleTick(state);

        var waitMinDuration = new Promise(function (resolve) {
            window.setTimeout(resolve, minDurationMs);
        });

        state.rollPromise = Promise.all([resultPromise.catch(function () { return null; }), waitMinDuration]).then(function (results) {
            var value = results[0];
            window.cancelAnimationFrame(state.raf);
            var finalValue = value == null ? (state.currentValue || 1) : Number(value);
            settleOnValue(state, finalValue);
            if (value != null) {
                playSoundSafe('dice');
            }
            return new Promise(function (resolve) {
                window.setTimeout(function () {
                    state.rolling = false;
                    state.rollPromise = null;
                    resolve(finalValue);
                }, 560);
            });
        });

        return state.rollPromise;
    }

    var CONFETTI_COLORS = ['#f97316', '#22c55e', '#facc15', '#38bdf8', '#f472b6', '#a78bfa'];

    var ICONS = {
        check: '<svg viewBox="0 0 24 24" width="40" height="40"><path fill="currentColor" d="M9 16.17 4.83 12l-1.42 1.41L9 19l12-12-1.41-1.41z"/></svg>',
        cross: '<svg viewBox="0 0 24 24" width="40" height="40"><path fill="currentColor" d="m12 10.59 4.95-4.95 1.41 1.41L13.41 12l4.95 4.95-1.41 1.41L12 13.41l-4.95 4.95-1.41-1.41L10.59 12 5.64 7.05l1.41-1.41z"/></svg>',
        shield: '<svg viewBox="0 0 24 24" width="36" height="36"><path fill="currentColor" d="M12 2 20 5v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5Z"/></svg>',
        bonus: '★',
        trap: '⚠',
        mystery: '?',
        winner: '🏆',
    };

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function fxBannerEl() {
        var el = document.querySelector('[data-fx-banner]');
        if (!el) {
            el = document.createElement('div');
            el.setAttribute('data-fx-banner', '');
            el.className = 'fx-banner hidden';
            document.body.appendChild(el);
        }
        return el;
    }

    var fxBannerToken = 0;

    function showFxBanner(opts) {
        var el = fxBannerEl();
        var token = ++fxBannerToken;
        el.className = 'fx-banner fx-banner-' + opts.tone;
        el.innerHTML = '<span class="fx-banner-icon">' + opts.icon + '</span>' +
            '<strong>' + escapeHtml(opts.title) + '</strong>' +
            (opts.body ? '<span class="fx-banner-body">' + escapeHtml(opts.body) + '</span>' : '');

        return new Promise(function (resolve) {
            window.setTimeout(function () {
                if (token === fxBannerToken) {
                    el.classList.add('hidden');
                }
                resolve();
            }, opts.durationMs || 2000);
        });
    }

    function confettiBurst(x, y, count) {
        var pieceCount = count || 32;
        for (var i = 0; i < pieceCount; i++) {
            (function () {
                var piece = document.createElement('div');
                piece.className = 'fx-confetti-piece';
                piece.style.left = x + 'px';
                piece.style.top = y + 'px';
                piece.style.background = CONFETTI_COLORS[i % CONFETTI_COLORS.length];
                document.body.appendChild(piece);

                var angle = Math.random() * Math.PI * 2;
                var distance = 90 + Math.random() * 140;
                var dx = Math.cos(angle) * distance;
                var dy = Math.sin(angle) * distance - 40;
                var rotate = Math.random() * 720 - 360;
                var duration = 900 + Math.random() * 500;

                var animation = piece.animate([
                    {transform: 'translate(-50%, -50%) translate(0, 0) rotate(0deg)', opacity: 1},
                    {transform: 'translate(-50%, -50%) translate(' + dx + 'px, ' + (dy + 180) + 'px) rotate(' + rotate + 'deg)', opacity: 0},
                ], {duration: duration, easing: 'cubic-bezier(.25,.65,.4,1)'});

                animation.finished.catch(function () { return null; }).finally(function () {
                    piece.remove();
                });
            })();
        }

        return new Promise(function (resolve) {
            window.setTimeout(resolve, 1500);
        });
    }

    function burstIcon(x, y, icon, tone) {
        var el = document.createElement('div');
        el.className = 'fx-tile-icon fx-tile-icon-' + tone;
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        el.innerHTML = icon;
        document.body.appendChild(el);

        var animation = el.animate([
            {transform: 'translate(-50%, -50%) scale(.4)', opacity: 0},
            {transform: 'translate(-50%, -65%) scale(1.15)', opacity: 1, offset: .35},
            {transform: 'translate(-50%, -80%) scale(1)', opacity: 0},
        ], {duration: 1000, easing: 'ease-out'});

        return animation.finished.catch(function () { return null; }).finally(function () {
            el.remove();
        });
    }

    function flashScreen(tone) {
        var el = document.createElement('div');
        el.className = 'fx-screen-flash fx-screen-flash-' + tone;
        document.body.appendChild(el);

        var animation = el.animate([
            {opacity: 0}, {opacity: 1, offset: .15}, {opacity: 0},
        ], {duration: 500, easing: 'ease-out'});

        animation.finished.catch(function () { return null; }).finally(function () {
            el.remove();
        });
    }

    var TILE_EFFECT_STYLE = {
        BONUS: {icon: ICONS.bonus, tone: 'bonus', sound: 'points'},
        TRAP: {icon: ICONS.trap, tone: 'trap', sound: 'trap'},
        MYSTERY: {icon: ICONS.mystery, tone: 'mystery', sound: 'mystery'},
        SAFE: {icon: ICONS.shield, tone: 'safe', sound: 'shield'},
        SAFE_BLOCK: {icon: ICONS.shield, tone: 'safe', sound: 'shield'},
    };

    function tileEffect(x, y, type, label) {
        var style = TILE_EFFECT_STYLE[String(type || '').toUpperCase()];
        if (!style) {
            return Promise.resolve();
        }
        playSoundSafe(style.sound);
        return burstIcon(x, y, style.icon, style.tone).then(function () {
            return showFxBanner({
                tone: style.tone,
                icon: style.icon,
                title: label,
                durationMs: 1400,
            });
        });
    }

    function celebrateCorrect(x, y, teamName) {
        playSoundSafe('correct');
        var confettiDone = confettiBurst(x, y, 36);
        return showFxBanner({
            tone: 'correct',
            icon: ICONS.check,
            title: 'Jawaban Benar',
            body: teamName,
            durationMs: 2200,
        }).then(function () {
            return confettiDone;
        });
    }

    function celebrateWrong(teamName) {
        playSoundSafe('wrong');
        flashScreen('danger');
        return showFxBanner({
            tone: 'wrong',
            icon: ICONS.cross,
            title: 'Belum Tepat',
            body: teamName,
            durationMs: 1500,
        });
    }

    function celebrateWinner(x, y, teamName) {
        playSoundSafe('winner');
        var confettiDone = Promise.all([
            confettiBurst(x, y, 50),
            confettiBurst(window.innerWidth / 2, window.innerHeight * 0.25, 50),
        ]);
        return showFxBanner({
            tone: 'winner',
            icon: ICONS.winner,
            title: 'Pemenang',
            body: teamName,
            durationMs: 4000,
        }).then(function () {
            return confettiDone;
        });
    }

    window.GameFx = {
        sound: {
            unlock: unlockSound,
            play: playSoundSafe,
            startTension: startTension,
            stopTension: stopTension,
            setMuted: setMuted,
            isMuted: isMuted,
        },
        mountDie: mountDie,
        setDieResting: setDieResting,
        rollDie: rollDie,
        confettiBurst: confettiBurst,
        banner: showFxBanner,
        tileEffect: tileEffect,
        celebrateCorrect: celebrateCorrect,
        celebrateWrong: celebrateWrong,
        celebrateWinner: celebrateWinner,
    };
})();
