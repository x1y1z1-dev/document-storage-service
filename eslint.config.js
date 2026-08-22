import globals from 'globals';

export default [
    {
        files: ['resources/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.jquery,
            },
        },
        rules: {
            // ── Possible errors ────────────────────────────────────────────
            'no-undef':             'error',   // catch typos / missing imports
            'no-unused-vars':       ['error', { vars: 'all', args: 'after-used', ignoreRestSiblings: true }],
            'no-unreachable':       'error',
            'no-constant-condition':'error',
            'no-duplicate-case':    'error',
            'no-dupe-keys':         'error',
            'no-empty':             ['error', { allowEmptyCatch: false }],

            // ── Best practices ─────────────────────────────────────────────
            'eqeqeq':               ['error', 'always', { null: 'ignore' }], // ban == except null checks
            'no-eval':              'error',
            'no-implied-eval':      'error',
            'no-alert':             'warn',
            'no-console':           'warn',    // keep as warn — remove debug logs before shipping
            'no-var':               'error',   // enforce let/const
            'prefer-const':         ['error', { destructuring: 'all' }],
            'curly':                ['error', 'all'],  // always use braces
            'dot-notation':         'error',
            'no-multi-spaces':      'error',
            'no-return-assign':     'error',
            'no-self-compare':      'error',
            'no-throw-literal':     'error',
            'radix':                'error',   // parseInt always needs a radix

            // ── Style (auto-fixable) ───────────────────────────────────────
            'semi':                 ['error', 'always'],
            'quotes':               ['error', 'single', { avoidEscape: true }],
            'indent':               ['error', 4, { SwitchCase: 1 }],
            'comma-dangle':         ['error', 'always-multiline'],
            'no-trailing-spaces':   'error',
            'eol-last':             ['error', 'always'],
            'space-before-function-paren': ['error', { anonymous: 'always', named: 'never', asyncArrow: 'always' }],
            'keyword-spacing':      ['error', { before: true, after: true }],
            'space-infix-ops':      'error',
            'object-curly-spacing': ['error', 'always'],
        },
    },
    {
        // Ignore build output and vendor assets
        ignores: ['public/build/**', 'node_modules/**'],
    },
];
