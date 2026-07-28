import js from "@eslint/js";
import globals from "globals";

export default [
    {
        ignores: [
            "**/*.min.js",
            ".stubs/**",
            "vendor/**",
        ],
    },
    js.configs.recommended,
    {
        files: ["assets/summer-camp/**/*.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "script",
            globals: {
                ...globals.browser,
                flatpickr: "readonly",
                wp: "readonly",
            },
        },
    },
    {
        files: ["assets/form-builder/**/*.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "script",
            globals: {
                ...globals.browser,
                CSS: "readonly",
            },
        },
    },
];
