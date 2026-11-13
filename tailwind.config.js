import defaultTheme from "tailwindcss/defaultTheme";
import forms from "@tailwindcss/forms";

// Versi padat dari skala jarak Tailwind (bawaan: 3 = 0.75rem, 4 = 1rem, 6 = 1.5rem, 8 = 2rem).
const compactSpacing = {
    "3": "0.625rem",
    "4": "0.8125rem",
    "5": "0.875rem",
    "6": "1rem",
    "7": "1.125rem",
    "8": "1.25rem",
    "9": "1.375rem",
    "10": "1.5rem",
    "11": "1.75rem",
    "12": "2rem",
    "14": "2.25rem",
    "16": "2.5rem",
    "20": "3rem",
    "24": "3.5rem",
    "2.5": "0.5625rem",
    "3.5": "0.75rem"
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
        "./storage/framework/views/*.php",
        "./resources/views/**/*.blade.php",
        "./resources/js/**/*.jsx",
    ],
    darkMode: "class",
    theme: {
        extend: {
            fontFamily: {
                sans: [
                    "Inter",
                    "Plus Jakarta Sans",
                    ...defaultTheme.fontFamily.sans,
                ],
                mono: [
                    "JetBrains Mono",
                    "Fira Code",
                    ...defaultTheme.fontFamily.mono,
                ],
            },
            colors: {
                // Primary - biru tua Koperasi (selaras halaman login)
                primary: {
                    50: "#eef3fa",
                    100: "#d8e3f3",
                    200: "#b3c8e7",
                    300: "#84a5d6",
                    400: "#5a84c2",
                    500: "#2f63a8",
                    600: "#244f8d",
                    700: "#1c3f73",
                    800: "#15305a",
                    900: "#0f2444",
                    950: "#0b1f3a",
                },
                // Accent - emas Koperasi
                accent: {
                    50: "#fdf8eb",
                    100: "#f9edcc",
                    200: "#f2dc9c",
                    300: "#e9c970",
                    400: "#dfb755",
                    500: "#d4a843",
                    600: "#b78b2c",
                    700: "#916c23",
                    800: "#6e5220",
                    900: "#513d1a",
                    950: "#2e210b",
                },
                // Abu-abu kebiruan (slate & gray): mode gelap bernuansa biru tua seperti halaman login
                slate: {
                    50: "#f5f7fb",
                    100: "#ebeff6",
                    200: "#d9e0eb",
                    300: "#bcc7d8",
                    400: "#8b9ab2",
                    500: "#64748f",
                    600: "#4a5973",
                    700: "#33435f",
                    800: "#1e2f4d",
                    900: "#132744",
                    950: "#0b1f3a",
                },
                gray: {
                    50: "#f5f7fb",
                    100: "#ebeff6",
                    200: "#d9e0eb",
                    300: "#bcc7d8",
                    400: "#8b9ab2",
                    500: "#64748f",
                    600: "#4a5973",
                    700: "#33435f",
                    800: "#1e2f4d",
                    900: "#132744",
                    950: "#0b1f3a",
                },
                // Success - Emerald
                success: {
                    50: "#ecfdf5",
                    100: "#d1fae5",
                    200: "#a7f3d0",
                    300: "#6ee7b7",
                    400: "#34d399",
                    500: "#10b981",
                    600: "#059669",
                    700: "#047857",
                    800: "#065f46",
                    900: "#064e3b",
                    950: "#022c22",
                },
                // Warning - Amber
                warning: {
                    50: "#fffbeb",
                    100: "#fef3c7",
                    200: "#fde68a",
                    300: "#fcd34d",
                    400: "#fbbf24",
                    500: "#f59e0b",
                    600: "#d97706",
                    700: "#b45309",
                    800: "#92400e",
                    900: "#78350f",
                    950: "#451a03",
                },
                // Danger - Rose
                danger: {
                    50: "#fff1f2",
                    100: "#ffe4e6",
                    200: "#fecdd3",
                    300: "#fda4af",
                    400: "#fb7185",
                    500: "#f43f5e",
                    600: "#e11d48",
                    700: "#be123c",
                    800: "#9f1239",
                    900: "#881337",
                    950: "#4c0519",
                },
            },
            spacing: {
                18: "4.5rem",
                88: "22rem",
                100: "25rem",
                112: "28rem",
                128: "32rem",
            },
            // Jarak antar elemen dipadatkan di semua halaman (padding, margin, gap, space-x/y); ukuran w-/h- tetap.
            padding: compactSpacing,
            margin: compactSpacing,
            gap: compactSpacing,
            space: compactSpacing,
            minHeight: {
                touch: "2.75rem", // 44px - minimum touch target
                "touch-lg": "3rem", // 48px - comfortable touch target
            },
            minWidth: {
                touch: "2.75rem",
                "touch-lg": "3rem",
            },
            borderRadius: {
                "4xl": "2rem",
            },
            boxShadow: {
                glow: "0 0 20px rgba(212, 168, 67, 0.3)",
                "glow-lg": "0 0 40px rgba(212, 168, 67, 0.4)",
                "inner-lg": "inset 0 4px 6px -1px rgb(0 0 0 / 0.1)",
            },
            animation: {
                "slide-in": "slideIn 0.2s ease-out",
                "slide-up": "slideUp 0.2s ease-out",
                "fade-in": "fadeIn 0.15s ease-out",
                "pulse-subtle":
                    "pulseSubtle 2s cubic-bezier(0.4, 0, 0.6, 1) infinite",
                "bounce-subtle":
                    "bounceSubtle 0.5s cubic-bezier(0.34, 1.56, 0.64, 1)",
                "cart-add": "cartAdd 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)",
            },
            keyframes: {
                slideIn: {
                    "0%": { transform: "translateX(100%)", opacity: "0" },
                    "100%": { transform: "translateX(0)", opacity: "1" },
                },
                slideUp: {
                    "0%": { transform: "translateY(10px)", opacity: "0" },
                    "100%": { transform: "translateY(0)", opacity: "1" },
                },
                fadeIn: {
                    "0%": { opacity: "0" },
                    "100%": { opacity: "1" },
                },
                pulseSubtle: {
                    "0%, 100%": { opacity: "1" },
                    "50%": { opacity: "0.7" },
                },
                bounceSubtle: {
                    "0%": { transform: "scale(1)" },
                    "50%": { transform: "scale(1.05)" },
                    "100%": { transform: "scale(1)" },
                },
                cartAdd: {
                    "0%": { transform: "scale(0.8)", opacity: "0" },
                    "50%": { transform: "scale(1.1)" },
                    "100%": { transform: "scale(1)", opacity: "1" },
                },
            },
            backdropBlur: {
                xs: "2px",
            },
            transitionDuration: {
                250: "250ms",
            },
        },
    },
    plugins: [forms],
};
