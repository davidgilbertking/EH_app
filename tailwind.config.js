import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        // Also scan plain JS modules; some colour tokens and Tailwind class
        // strings live in data files like Pages/Contacts/data/buttonPalette.js
        // — without this, JIT purges them and buttons render without a bg.
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Garamond is a serif — keep it as the default `font-sans`
                // family so existing utility classes (font-sans on body,
                // breadcrumbs, etc.) inherit it without rewrites elsewhere.
                sans: ['"EB Garamond"', ...defaultTheme.fontFamily.serif],
                serif: ['"EB Garamond"', ...defaultTheme.fontFamily.serif],
            },
        },
    },

    plugins: [forms],
};
