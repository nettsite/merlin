import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ink: {
                    DEFAULT: '#1A1A1A',
                    soft: '#4D4D4D',
                    muted: '#8A8580',
                },
                accent: {
                    DEFAULT: '#C8772E',
                    on: '#FFFFFF',
                    ink: '#9A4F12',
                    soft: '#FCEFDF',
                    border: '#F2D9B7',
                },
                surface: {
                    DEFAULT: '#FFFFFF',
                    alt: '#FAF8F4',
                },
                line: '#EAE6DF',
                success: '#3B7A4E',
                warning: '#B45309',
                danger: '#B91C1C',
                info: '#1E40AF',
            },
        },
    },

    plugins: [forms],
};
