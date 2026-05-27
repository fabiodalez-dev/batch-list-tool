/* Tailwind v4 is wired through @tailwindcss/vite in vite.config.js.
 * The main `tailwindcss` package no longer ships a PostCSS plugin,
 * so we only keep autoprefixer here as a sane default. */
export default {
    plugins: {
        autoprefixer: {},
    },
};
