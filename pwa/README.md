# Progressive Web App

Contains a [Next.js](https://nextjs.org/) project bootstrapped with [pnpm](https://pnpm.io/) and [`create-next-app`](https://github.com/vercel/next.js/tree/canary/packages/create-next-app).

The `admin` page contains an API Platform Admin project (refer to its [documentation](https://api-platform.com/docs/admin)).

You can also generate your web app here by using the API Platform Client Generator (refer to its [documentation](https://api-platform.com/docs/client-generator/nextjs/)).

## UI components

This app uses [shadcn/ui](https://ui.shadcn.com/) with Tailwind CSS v4. The primitive components are checked into `components/ui/` so they can be edited freely. To add a new primitive (e.g. a select, tooltip, or dialog), run:

```bash
npx shadcn@latest add <component>
```

`components.json` maps the `@/*` import alias to the `pwa/` root. Design tokens (CSS variables) live in `styles/globals.css` — the brand cyan is wired up as `--primary`. The `cn()` helper in `lib/utils.ts` merges Tailwind classes safely.

Formik forms use the `FormikField` helper in `components/ui/formik-field.tsx`, which wraps shadcn's `Input` + `Label` + `ErrorMessage` so individual pages stay terse.
