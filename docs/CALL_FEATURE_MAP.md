# Call features: what people know from other call apps, and Meet

Owner 2026-11-08: Meet calls should have every feature people know from Teams, WhatsApp calls, Zoom and Google Meet, and
use call-screen style B (colour glass, docs/call-screens.html) everywhere. Our own design and wording; patterns, not copies.

Legend: HAVE = in Meet already, BUILD = to build, LATER = needs something outside this code (stated).
State 2026-11-08 (1.6.0): every row is HAVE. Calls on strict office networks still need the TURN relay (owner: a small VPS).

## Ringing and starting
| Feature | Meet |
|---|---|
| Voice and video calls, 1:1 | HAVE |
| Incoming screen with answer / decline, ringtone, vibration, lock-screen ring on Android | HAVE |
| Decline with a quick message ("Can't talk now", own text) | HAVE (1.5.0) |
| Outgoing screen (calling, ringing), cancel | HAVE |
| Missed calls, call history, call back | HAVE |
| Call link anyone can join (also guests without account) | HAVE (meetings: guest link) |
| Scheduled calls / meetings with calendar | HAVE |

## During a call
| Feature | Meet |
|---|---|
| Mute, camera on/off, speaker / earpiece / Bluetooth choice, flip camera | HAVE |
| Screen share, reactions, add people (1:1 grows into a group) | HAVE |
| Connection status | HAVE |
| Minimise: keep talking while using the rest of the app (small floating bar) | HAVE (1.5.0) |
| Picture-in-picture video outside the app | HAVE (1.5.0) |
| Switch a voice call to video (the other person is offered to turn on theirs) | HAVE (1.6.0) |
| Background blur (device blur when there is one, else the selfie segmenter shipped with Meet) | HAVE (1.6.0) |
| Noise reduction switch (off = every sound, for music) | HAVE (1.6.0) |
| Grid view / speaker view, pin someone, ring around whoever talks | HAVE (1.6.0) |
| In-call chat | HAVE (1.5.0: minimise keeps the call and opens the chat) |
| Live captions | HAVE in meetings and calls (1.6.0) |
| Recording | HAVE in meetings |
| Raise hand, waiting room, lock, mute all, remove, co-host, host transfer | HAVE in meetings |
| Breakout rooms, whiteboard, notes / AI summary | HAVE in meetings |
| Polls and questions (Q&A with likes, most liked on top) | HAVE (1.6.0; people who join later see polls made after they joined) |
| Second call while on a call (call waiting: end and answer / decline) | HAVE (1.6.0) |
| Low data mode (your video goes out small, the others send you less) | HAVE (1.6.0) |

## After the call
| Feature | Meet |
|---|---|
| Call ended screen: duration, call again, message | HAVE (1.5.0) |
| Recording / notes afterwards | HAVE in meetings |

## Look
All call screens in style B: colour glass background (indigo, purple, pink), glass buttons and bars, red end, green answer.
