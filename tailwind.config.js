/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
        './app/Filament/**/*.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                // The identity typeface, with the guide's fallback behind it.
                sans: ['IBM Plex Sans Arabic', 'Noto Sans Arabic', 'Segoe UI', 'system-ui', 'sans-serif'],
            },
            colors: {
                /*
                 | The identity palette. `brand` is the official primary green
                 | (#2E7D32) as its 600 step, with a ramp built around it for
                 | states and surfaces; `gold` is the supporting accent.
                 */
                brand: {
                    50: '#EFF5EF',
                    100: '#D7E7D8',
                    200: '#AFCFB1',
                    300: '#84B487',
                    400: '#5A9A5E',
                    500: '#3D8942',
                    600: '#2E7D32',
                    700: '#256528',
                    800: '#1C4C1E',
                    900: '#133415',
                    950: '#0B1F0C',
                },
                gold: {
                    50: '#FBF7EA',
                    100: '#F4EAC6',
                    200: '#E9D48D',
                    300: '#DCBD55',
                    400: '#D2AE36',
                    500: '#C9A227',
                    600: '#A5841F',
                    700: '#7C6317',
                    800: '#54430F',
                    900: '#2F2508',
                },
                ink: {
                    DEFAULT: '#263238',
                    muted: '#5A6B73',
                },
                surface: '#F7F9F4',
            },
            borderRadius: {
                xl: '0.875rem',
                '2xl': '1.25rem',
            },
        },
    },
    plugins: [],
}
