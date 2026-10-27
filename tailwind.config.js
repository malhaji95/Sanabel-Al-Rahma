/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    /*
     | T-167 — the four progress colours, kept out of the sweep.
     |
     | Tailwind deletes any rule in `@layer components` whose class name it
     | cannot find in the files it scans. These four are written as
     | `meter-fill-{{ ProgressBand::of($percent) }}`, so the name only exists
     | once the page is rendered and the scan never sees it: the association's
     | red, amber, light green and deep green were compiled out of every build
     | and every bar on the site drew in the one default colour. The markup was
     | right and the stylesheet was right; the build threw the colours away.
     |
     | Named here, so a band added later is added here with it.
     */
    safelist: [
        'meter-fill-low',
        'meter-fill-mid',
        'meter-fill-high',
        'meter-fill-complete',
    ],
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
