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
  {
    // A bare <input type="checkbox"> paints the browser's default blue, which
    // reads as unfinished next to the app's teal primary, and it can't carry
    // the shared focus-ring or disabled conventions. Use components/ui/checkbox.
    // Scoped to app code — the primitive itself is allowed to use the native
    // element, and the dev-docs pages demonstrate it.
    files: ["components/**/*.tsx", "pages/**/*.tsx"],
    ignores: ["components/ui/**", "pages/dev/**"],
    rules: {
      "no-restricted-syntax": [
        "error",
        {
          selector:
            'JSXOpeningElement[name.name="input"] > JSXAttribute[name.name="type"][value.value="checkbox"]',
          message:
            "Use <Checkbox> from @/components/ui/checkbox instead of a raw <input type=\"checkbox\">.",
        },
      ],
    },
  },
];
