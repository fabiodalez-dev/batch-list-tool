import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * Tailwind v4 reads its theme from CSS (`@theme` directive) rather than this
 * file. We keep this config as authoritative documentation of the project
 * palette and for any tooling (IDE plugins, Prettier sort) that still
 * introspects it. Source of truth at runtime:
 *   - resources/css/filament/admin/theme.css (admin panel)
 *   - resources/css/app.css (public marketing entry)
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './app/Filament/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                // Cream — dominant warm neutral surface
                cream: {
                    50:  'oklch(98.5% 0.008 78)',
                    100: 'oklch(96.5% 0.015 78)',
                    200: 'oklch(93% 0.022 76)',
                    300: 'oklch(88% 0.028 74)',
                    400: 'oklch(78% 0.040 70)',
                    500: 'oklch(65% 0.050 65)',
                    600: 'oklch(52% 0.050 60)',
                    700: 'oklch(40% 0.050 55)',
                    800: 'oklch(30% 0.045 50)',
                    900: 'oklch(22% 0.040 48)',
                    950: 'oklch(15% 0.035 46)',
                },
                // Coffee — warm brown for text and structural accents
                coffee: {
                    50:  'oklch(96% 0.013 48)',
                    100: 'oklch(91% 0.025 48)',
                    200: 'oklch(82% 0.050 48)',
                    300: 'oklch(70% 0.070 48)',
                    400: 'oklch(56% 0.075 48)',
                    500: 'oklch(45% 0.075 48)',
                    600: 'oklch(36% 0.070 48)',
                    700: 'oklch(28% 0.060 48)',
                    800: 'oklch(22% 0.050 48)',
                    900: 'oklch(16% 0.040 48)',
                    950: 'oklch(10% 0.030 48)',
                },
                // Brand orange — warm primary accent used sparingly
                'brand-orange': {
                    50:  'oklch(97% 0.025 65)',
                    100: 'oklch(93% 0.050 60)',
                    200: 'oklch(86% 0.100 56)',
                    300: 'oklch(78% 0.130 54)',
                    400: 'oklch(72% 0.155 52)',
                    500: 'oklch(66% 0.175 50)',
                    600: 'oklch(58% 0.185 48)',
                    700: 'oklch(50% 0.170 46)',
                    800: 'oklch(40% 0.140 44)',
                    900: 'oklch(30% 0.100 42)',
                    950: 'oklch(20% 0.070 42)',
                },
            },
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['Fraunces', 'Times New Roman', 'Georgia', ...defaultTheme.fontFamily.serif],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },
        },
    },
    plugins: [],
};
