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

    window.GameFx = {
        sound: {
            unlock: unlockSound,
        },
    };
})();
