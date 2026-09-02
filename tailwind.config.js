import defaultTheme from 'tailwindcss/defaultTheme'

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                // Arabic first: Tajawal, with IBM Plex Sans Arabic behind it.
                sans: ['Tajawal', 'IBM Plex Sans Arabic', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                sanabel: {
                    50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 300: '#6ee7b7',
                    400: '#34d399', 500: '#10b981', 600: '#059669', 700: '#047857',
                    800: '#065f46', 900: '#064e3b',
                },
            },
        },
    },
    plugins: [],
}
