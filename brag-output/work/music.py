# Synth soundtrack: 120 BPM, A minor (Am F C G), 52s, every hit on the beat grid from t=0.
import numpy as np
from scipy.io import wavfile
from scipy.signal import butter, sosfilt

SR, DUR, BPM = 44100, 52.0, 120
BEAT = 60 / BPM
N = int(SR * DUR)
L = np.zeros(N); R = np.zeros(N)
hz = lambda m: 440 * 2 ** ((m - 69) / 12)
CH = [[57, 60, 64], [53, 57, 60], [48, 52, 55], [55, 59, 62]]  # Am F C G
lp = lambda x, f: sosfilt(butter(2, f, 'low', fs=SR, output='sos'), x)
hp = lambda x, f: sosfilt(butter(2, f, 'high', fs=SR, output='sos'), x)

def add(sig, t, g=1.0, pan=0.0):
    i = int(round(t * SR)); j = min(N, i + len(sig))
    if i >= N: return
    L[i:j] += sig[:j - i] * g * (1 - pan) ** .5 if pan > 0 else sig[:j - i] * g
    R[i:j] += sig[:j - i] * g * (1 + pan) ** .5 if pan < 0 else sig[:j - i] * g

def env(n, a, d):  # attack seconds, exponential decay time-constant
    t = np.arange(n) / SR
    return np.minimum(1, t / max(a, 1e-4)) * np.exp(-t / d)

# pad: detuned saws per bar, lowpassed, slow attack/release
for bar in range(26):
    t0 = bar * 2.0; n = int(2.3 * SR); t = np.arange(n) / SR
    s = np.zeros(n)
    for m in CH[bar % 4]:
        for det in (-0.08, 0.08):
            f = hz(m) * 2 ** (det / 12)
            s += 2 * ((t * f) % 1) - 1
    e = np.minimum(1, t / .35) * np.clip((2.3 - t) / .4, 0, 1)
    add(lp(s * e, 1400 if 6 <= bar < 24 else 900), t0, .035)

drums = lambda t: 4 <= t < 50
for k in range(int(DUR / BEAT)):
    t = k * BEAT; bar = int(t // 2); root = hz(CH[bar % 4][0] - 24)
    if drums(t) and not (11.5 <= t < 12):  # one-beat drop before the feature tour
        n = int(.35 * SR); tt = np.arange(n) / SR
        add(np.sin(2 * np.pi * np.cumsum(50 + 90 * np.exp(-tt * 30)) / SR) * env(n, .002, .12), t, .55)
    if 8 <= t < 50:  # bass on quarters
        n = int(.45 * SR); tt = np.arange(n) / SR
        add(lp(np.sin(2 * np.pi * root * tt) + .3 * np.sin(4 * np.pi * root * tt), 600) * env(n, .01, .2), t, .22)
    if 12 <= t < 48:  # offbeat hat
        n = int(.06 * SR)
        add(hp(np.random.default_rng(k).standard_normal(n), 7000) * env(n, .001, .015), t + BEAT / 2, .05, .3)

# pluck arpeggio, 8ths, scenes 4–8
for k in range(int(12 / .25), int(48 / .25)):
    t = k * .25; ch = CH[int(t // 2) % 4]; m = ch[k % 3] + 12 + (12 if k % 8 == 7 else 0)
    n = int(.4 * SR); tt = np.arange(n) / SR
    add(lp(np.sign(np.sin(2 * np.pi * hz(m) * tt)) * .5 + np.sin(2 * np.pi * hz(m) * tt), 2500) * env(n, .003, .09), t, .028, .4 if k % 2 else -.4)

def bell(t, notes, g):
    for i, m in enumerate(notes):
        n = int(1.6 * SR); tt = np.arange(n) / SR; f = hz(m)
        add((np.sin(2 * np.pi * f * tt) + .25 * np.sin(2 * np.pi * 2.76 * f * tt)) * env(n, .003, .5), t + i * .125, g)

def tick(t, g=.05):
    n = int(.08 * SR); tt = np.arange(n) / SR
    add(np.sin(2 * np.pi * hz(88) * tt) * env(n, .001, .02), t, g)

bell(1.0, [76, 81], .09)                       # "Paid." confirm, E5→A5
for s in (4, 8, 12, 20, 28, 36, 44): bell(s, [81], .05)
bell(48, [69, 76, 81], .08)                     # outro resolve
q = lambda t: round(t / .25) * .25              # snap UI ticks to the 8th-note grid
for t in [13.5, 13.75, 14.0, 17.75, 21.5, 23.25, 24.5, 29, 29.5, 30, 30.75, 37, 37.5, 38, 38.25, 40, 40.5, 41, 44.75, 45, 45.25, 45.5]:
    tick(q(t))
for t in (18.5, 21.75, 33.25, 39, 46.25): bell(q(t), [88], .035)  # success moments

mix = np.stack([L, R], 1)
fade = np.clip((DUR - np.arange(N) / SR) / 1.6, 0, 1)
mix *= fade[:, None]
mix = np.tanh(mix / np.abs(mix).max() * 1.4) * .89
wavfile.write('music.wav', SR, (mix * 32767).astype(np.int16))
print('peak', np.abs(mix).max())
