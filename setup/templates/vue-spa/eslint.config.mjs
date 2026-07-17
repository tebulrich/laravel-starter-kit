import eslint from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import vueTs from '@vue/eslint-config-typescript';

export default tseslint.config(
    eslint.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    ...vueTs(),
    {
        ignores: ['dist/**', 'node_modules/**'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
);
