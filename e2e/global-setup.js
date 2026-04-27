// @ts-check
const { request } = require("@playwright/test");

const BASE_URL = "https://localhost";

// Routes the auth/redirect-heavy specs land on. In CI the PWA runs `next dev`
// and compiles each route on first hit; cold compilation has caused 5s
// `toHaveURL(...)` assertions to time out under parallel load (4 workers, all
// racing the same compiler). Warm them once before any worker starts so every
// worker sees an already-compiled route.
const ROUTES_TO_WARM = [
  "/signin",
  "/signup",
  "/account",
  "/admin",
  "/tasks",
  "/projects",
  "/groups",
  "/tags",
  "/forgot-password",
  "/reset-password",
];

module.exports = async () => {
  const ctx = await request.newContext({ ignoreHTTPSErrors: true });
  try {
    await Promise.all(
      ROUTES_TO_WARM.map((path) =>
        ctx
          .get(`${BASE_URL}${path}`, { headers: { Accept: "text/html" } })
          .catch(() => undefined),
      ),
    );
  } finally {
    await ctx.dispose();
  }
};
