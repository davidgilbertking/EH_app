/**
 * Fixed palette of 8 fundamentally distinct hues used by Past.vue and
 * Future.vue. Hues are spaced roughly 45° apart on the colour wheel so no two
 * adjacent (or even near-adjacent) buttons can look similar.
 *
 * Order is stable: button N on Past and button N on Future always get the
 * same colour so the user can build muscle memory across sessions. The yellow
 * entry uses dark text because its background is light.
 */
export const PALETTE_8 = [
    // red       (~0°)
    'bg-red-700 hover:bg-red-600 text-red-50 border-red-500',
    // orange    (~25°)
    'bg-orange-600 hover:bg-orange-500 text-orange-50 border-orange-400',
    // yellow    (~55°) — light bg, needs dark text
    'bg-yellow-400 hover:bg-yellow-300 text-yellow-950 border-yellow-600',
    // green     (~140°)
    'bg-green-700 hover:bg-green-600 text-green-50 border-green-500',
    // cyan      (~190°)
    'bg-cyan-700 hover:bg-cyan-600 text-cyan-50 border-cyan-500',
    // blue      (~220°)
    'bg-blue-700 hover:bg-blue-600 text-blue-50 border-blue-500',
    // violet    (~270°)
    'bg-violet-700 hover:bg-violet-600 text-violet-50 border-violet-500',
    // pink      (~325°)
    'bg-pink-600 hover:bg-pink-500 text-pink-50 border-pink-400',
];
