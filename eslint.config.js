import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            'react-hooks': reactHooks,
        },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        // Section 12 requires real RTL layouts, not right-aligned text. Physical
        // direction utilities silently break under dir="rtl" — `ml-auto` keeps pushing
        // from the left, so an element meant to sit at the far end collapses into its
        // neighbour. Use the logical equivalents instead:
        //
        //   ml-* → ms-*      pl-* → ps-*      text-left  → text-start
        //   mr-* → me-*      pr-* → pe-*      text-right → text-end
        //   border-l → border-s               border-r   → border-e
        //
        // This is enforced from the foundation phase rather than audited at the end,
        // because retrofitting direction-awareness across a built interface is the
        // expensive path the specification explicitly warns against.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [
            // Vendored shadcn/Radix primitives. These still carry physical properties
            // and are tracked as known RTL debt; they need per-component visual
            // verification against Radix positioning rather than a blind rewrite.
            'resources/js/components/ui/**',
            // Laravel's marketing page, to be replaced wholesale.
            'resources/js/pages/welcome.tsx',
        ],
        rules: {
            'no-restricted-syntax': [
                'error',
                {
                    selector:
                        "JSXAttribute[name.name='className'] Literal[value=/(^|\\s)(ml|mr|pl|pr)-(auto|px|[0-9.]+)(\\s|$)/]",
                    message: 'Use logical spacing (ms-/me-/ps-/pe-) instead of physical (ml-/mr-/pl-/pr-) so the layout works in RTL.',
                },
                {
                    selector: "JSXAttribute[name.name='className'] Literal[value=/(^|\\s)text-(left|right)(\\s|$)/]",
                    message: 'Use text-start / text-end instead of text-left / text-right so the layout works in RTL.',
                },
                {
                    selector: "JSXAttribute[name.name='className'] Literal[value=/(^|\\s)border-(l|r)(\\s|$)/]",
                    message: 'Use border-s / border-e instead of border-l / border-r so the layout works in RTL.',
                },
            ],
        },
    },
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'tailwind.config.js'],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
