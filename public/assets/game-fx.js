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

    function unlockSound() {
        var ctx = ensureAudioCtx();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
    }

    function tone(freq, startOffset, duration, waveType, gainPeak) {
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
        trap: function () {
            [400, 260].forEach(function (freq, i) {
                tone(freq, i * 0.1, 0.22, 'sawtooth', 0.16);
            });
        },
        mystery: function () {
            mysterySweep();
        },
        safe: function () {
            tone(880, 0, 0.3, 'sine', 0.14);
        },
        winner: function () {
            [523.25, 659.25, 783.99, 1046.5].forEach(function (freq, i) {
                tone(freq, i * 0.14, 0.3, 'triangle', 0.22);
            });
        },
    };

    function playSoundSafe(name) {
        var fn = SOUND_LIBRARY[name];
        if (fn) {
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

    window.GameFx = {
        sound: {
            unlock: unlockSound,
        },
        mountDie: mountDie,
        setDieResting: setDieResting,
        rollDie: rollDie,
    };
})();
