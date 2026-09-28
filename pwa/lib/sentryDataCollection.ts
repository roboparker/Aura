// Sentry `dataCollection` baseline shared by the browser, server and edge
// inits. Sentry v11 replaced `sendDefaultPii` with `dataCollection`, and an
// UNSET `dataCollection` collects cookies, request/response bodies, user info,
// DB query data and more by default. We ran v10 with `sendDefaultPii: false`,
// so this pins the v10-equivalent restrictive baseline from Sentry's migration
// guide — keep it restrictive unless the privacy posture in
// docs/developer/monitoring.md changes deliberately.
const DENY_IDENTIFYING = { deny: ["forwarded", "-ip", "remote-", "via", "-user"] };

export const sentryDataCollection = {
  userInfo: false,
  cookies: false,
  httpHeaders: {
    request: DENY_IDENTIFYING,
    response: DENY_IDENTIFYING,
  },
  httpBodies: [],
  urlQueryParams: DENY_IDENTIFYING,
  genAI: { inputs: false, outputs: false },
  databaseQueryData: false,
  queues: false,
  graphQL: { document: false, variables: false },
};
