import nextTypescript from "eslint-config-next/typescript";
import reactHooks from "eslint-plugin-react-hooks";
import nextPlugin from "@next/eslint-plugin-next";

export default [
  {
    ignores: [".next/**", "node_modules/**", "public/**"],
  },
  ...nextTypescript,
  {
    plugins: {
      "react-hooks": reactHooks,
      "@next/next": nextPlugin,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      ...nextPlugin.configs.recommended.rules,
      ...nextPlugin.configs["core-web-vitals"].rules,
      // Existing pages fetch data via useEffect when auth state resolves; the
      // new react-hooks v7 rule flags this pattern. Migrating to react-query
      // is tracked separately.
      "react-hooks/set-state-in-effect": "off",
    },
  },
];
