const SYMBOLS = Object.freeze({
    omen: Object.freeze({ id: 'omen', scale: 0.846 }),
    reckoning: Object.freeze({ id: 'reckoning', scale: 1 }),
    rumor: Object.freeze({ id: 'rumor', scale: 1.75 }),
    eldritch: Object.freeze({ id: 'eldritch', scale: 0.775 }),
    gate: Object.freeze({ id: 'gate', scale: 1.05 }),
    clue: Object.freeze({ id: 'clue', scale: 1.15 }),
    monsterSurge: Object.freeze({ id: 'monster-surge', scale: 1 }),
});

const at = (symbol, center) => Object.freeze({ ...symbol, center });

export const MYTHOS_CARD_BUTTONS = Object.freeze([
    Object.freeze({
        id: 'green', label: 'Green', image: '/images/mythos/green.png', viewBox: '0 80 2172 565',
        accent: '119 179 134',
        symbols: Object.freeze([at(SYMBOLS.omen, 25), at(SYMBOLS.monsterSurge, 50), at(SYMBOLS.clue, 75)]),
        clip: Object.freeze({ x: 2, y: 81, width: 2168, height: 562, rx: 145, ry: 125 }),
    }),
    Object.freeze({
        id: 'yellow', label: 'Yellow', image: '/images/mythos/yellow.png', viewBox: '0 36 2172 644',
        accent: '235 185 71',
        symbols: Object.freeze([at(SYMBOLS.omen, 23), at(SYMBOLS.reckoning, 50), at(SYMBOLS.gate, 75)]),
        clip: Object.freeze({ x: 5, y: 37, width: 2162, height: 640, rx: 130, ry: 140 }),
    }),
    Object.freeze({
        id: 'blue', label: 'Blue', image: '/images/mythos/blue.png', viewBox: '0 78 2172 568',
        accent: '113 178 224',
        symbols: Object.freeze([at(SYMBOLS.clue, 20), at(SYMBOLS.rumor, 50), at(SYMBOLS.eldritch, 79)]),
        clip: Object.freeze({ x: 1, y: 79, width: 2170, height: 566, rx: 125, ry: 112 }),
    }),
]);
