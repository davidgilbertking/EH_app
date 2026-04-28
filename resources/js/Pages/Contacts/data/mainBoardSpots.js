// Hotspot percentages tuned to public/maps/main.jpg (the EH base board photo).
// Refine in browser; left-top corner of the image is (0, 0).

// 9 big-city cards drawn on the board (San Francisco … Sydney).
// Contacts and Disaster now use separate arrays so coordinates can diverge.
export const citiesContacts = [
    { x: 9.5,  y: 32.5, label: 'San Francisco', city: 'san-francisco' },
    { x: 27, y: 33, label: 'Arkham',        city: 'arkham' },
    { x: 44, y: 27.7, label: 'London',        city: 'london' },
    { x: 50, y: 40, label: 'Rome',          city: 'rome' },
    { x: 60.5, y: 35, label: 'Istanbul',      city: 'istanbul' },
    { x: 93.2, y: 41, label: 'Tokyo',         city: 'tokyo' },
    { x: 84.7, y: 50, label: 'Shanghai',      city: 'shanghai' },
    { x: 26.5, y: 75, label: 'Buenos Aires',  city: 'buenos-aires' },
    { x: 91.3, y: 83.5, label: 'Sydney',        city: 'sydney' },
];

// Start as exact copy of contacts; safe to tune independently.
export const citiesDisaster = [
    { x: 9.5,  y: 32, label: 'San Francisco', city: 'san-francisco' },
    { x: 27, y: 33, label: 'Arkham',        city: 'arkham' },
    { x: 44, y: 27, label: 'London',        city: 'london' },
    { x: 50, y: 42, label: 'Rome',          city: 'rome' },
    { x: 60.5, y: 33, label: 'Istanbul',      city: 'istanbul' },
    { x: 93.2, y: 40, label: 'Tokyo',         city: 'tokyo' },
    { x: 84.7, y: 49.5, label: 'Shanghai',      city: 'shanghai' },
    { x: 26.5, y: 74.5, label: 'Buenos Aires',  city: 'buenos-aires' },
    { x: 91.3, y: 82.5, label: 'Sydney',        city: 'sydney' },
];

// Small black "city-space" circles = non-big-city contacts (spaces 1, 5, 7, 14,
// 15, 16, 17, 20 per the user's note). Coords are rough first-guess;
// fine-tune in the browser once main.jpg is uploaded.
export const citySpaces = [
    { x: 6, y: 23, label: 'Space 1',  slug: 'space-1' },
    { x: 18.5, y: 30, label: 'Space 5',  slug: 'space-5' },
    { x: 16, y: 40, label: 'Space 6',  slug: 'space-6' },
    { x: 20, y: 51, label: 'Space 7',  slug: 'space-7' },
    { x: 55, y: 27, label: 'Space 14', slug: 'space-14' },
    { x: 54.5, y: 80.5, label: 'Space 15', slug: 'space-15' },
    { x: 67, y: 27, label: 'Space 16', slug: 'space-16' },
    { x: 71, y: 52.5, label: 'Space 17', slug: 'space-17' },
    { x: 84, y: 65.5, label: 'Space 20', slug: 'space-20' },
];

// Green "wilderness" circles — 5 named + 5 numbered spaces.
export const wildernessSpaces = [
    { x: 16, y: 23, label: 'Space 4',              slug: 'space-4' },
    { x: 27.5, y: 63, label: 'The Amazon',           slug: 'amazon' },
    { x: 34.5, y: 17, label: 'Space 9',              slug: 'space-9' },
    { x: 57.5, y: 55, label: 'The Pyramids',         slug: 'pyramids' },
    { x: 42, y: 55, label: 'Space 10',             slug: 'space-10' },
    { x: 75.3, y: 31, label: 'Tunguska',             slug: 'tunguska' },
    { x: 56, y: 70, label: 'The Heart of Africa',  slug: 'heart-of-africa' },
    { x: 74.2, y: 45.7, label: 'The Himalayas',        slug: 'himalayas' },
    { x: 92, y: 26.5, label: 'Space 19',             slug: 'space-19' },
    { x: 88.3, y: 73, label: 'Space 21',             slug: 'space-21' },
];

// Blue "sea" circles — 7 numbered (2, 3, 8, 11, 12, 13, 18) + Antarctica.
export const seaSpaces = [
    { x: 4.8, y: 41.7, label: 'Space 2',   slug: 'space-2' },
    { x: 10.5, y: 78.3, label: 'Space 3',   slug: 'space-3' },
    { x: 26.8, y: 42.9, label: 'Space 8',   slug: 'space-8' },
    { x: 43.8, y: 78.7, label: 'Space 11',  slug: 'space-11' },
    { x: 39.1, y: 88, label: 'Space 12',  slug: 'space-12' },
    { x: 57.6, y: 13, label: 'Space 13',  slug: 'space-13' },
    { x: 69, y: 84, label: 'Space 18',  slug: 'space-18' },
    { x: 59.1, y: 93.5, label: 'Antarctica', slug: 'antarctica' },
];

// Kept for backward compat (old General/BigCity imports).
export const wilderness = wildernessSpaces.filter((w) => !w.slug.startsWith('space-'));
export const sea = [{ x: 35, y: 38, label: 'Sea' }];
