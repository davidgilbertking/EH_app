/**
 * Map UI navigation paths to audio-folder branches.
 * Branch match rule: exact folder or any nested descendant.
 */
const HREF_BRANCHES = {
    '/encounters': ['contacts'],
    '/encounters/general': ['contacts/general'],
    '/encounters/general/city': ['contacts/general/city'],
    '/encounters/general/wilderness': ['contacts/general/wilderness'],
    '/encounters/general/sea': ['contacts/general/sea'],
    '/encounters/restriction': ['contacts/obstruction'],
    '/encounters/named-cities': ['contacts/big-city'],
    '/encounters/other-world': ['contacts/other-world'],
    '/encounters/other-world/past': ['contacts/other-world/past'],
    '/encounters/other-world/future': ['contacts/other-world/future'],
    '/encounters/defeated': ['contacts/defeated'],
    '/encounters/quest': ['contacts/expedition'],
    '/encounters/quest/expedition': ['contacts/expedition/expeditions'],
    '/encounters/side-boards': ['contacts/antarctica', 'contacts/egypt', 'contacts/dreamlands'],
    '/encounters/side-boards/antarctica': ['contacts/antarctica'],
    '/encounters/side-boards/egypt': ['contacts/egypt'],
    '/encounters/side-boards/dreamlands': ['contacts/dreamlands'],

    '/other': ['special', 'ancient'],
    '/other/disaster': ['special/disaster'],
    '/other/disaster/city': ['special/disaster/city'],
    '/other/disaster/weather': ['special/disaster/weather'],
    '/other/disaster/location': ['special/disaster/location'],
    '/other/investigators': ['special/characters'],
    '/other/ancient-ones': ['ancient'],
};

function normalizeFolder(value) {
    if (!value || typeof value !== 'string') return null;
    return value.trim().replace(/^\/+|\/+$/g, '');
}

function normalizePath(value) {
    if (!value || typeof value !== 'string') return '/';
    const noQuery = value.split('?')[0] || '/';
    const trimmed = noQuery.trim();
    if (!trimmed) return '/';
    const prefixed = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
    if (prefixed === '/') return '/';
    return prefixed.replace(/\/+$/g, '');
}

function isFolderMatch(folder, branch) {
    return folder === branch || folder.startsWith(`${branch}/`);
}

export function getActiveFolder(engineState) {
    if (!engineState) return null;
    return engineState.playingFolder || (engineState.isPaused ? engineState.pausedFolder : null) || null;
}

export function isFolderInBranches(folderSlug, branches = []) {
    const folder = normalizeFolder(folderSlug);
    if (!folder) return false;
    for (const branch of branches) {
        const normalized = normalizeFolder(branch);
        if (!normalized) continue;
        if (isFolderMatch(folder, normalized)) return true;
    }
    return false;
}

export function branchesForHref(href) {
    const path = normalizePath(href);
    return HREF_BRANCHES[path] ?? [];
}

export function isHrefBranchActive(href, folderSlug) {
    return isFolderInBranches(folderSlug, branchesForHref(href));
}
