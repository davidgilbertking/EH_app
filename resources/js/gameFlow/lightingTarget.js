import catalog from '../../../config/lighting-presets.json' with { type: 'json' };

const prefixes = Object.entries(catalog.bindings.prefix).sort(([a], [b]) => b.length - a.length);
const preserve = new Set(catalog.bindings.preserve);

export function isEncounterLightingTarget(target) {
    return catalog.profiles[target?.profile]?.transitionGroup === 'encounter';
}

/** Folder mappings are shared with the server's preset catalog. */
export function resolveLightingTarget(folderSlug) {
    if (preserve.has(folderSlug)) return null;
    let profile = Object.hasOwn(catalog.bindings.exact, folderSlug) ? catalog.bindings.exact[folderSlug] : null;
    if (!profile) {
        profile = prefixes.find(([prefix]) => folderSlug?.startsWith(`${prefix.replace(/\/$/, '')}/`))?.[1];
    }
    profile ||= catalog.fallback;
    return { kind: catalog.profiles[profile].kind, profile };
}
