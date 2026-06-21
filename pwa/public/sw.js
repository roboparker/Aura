/*
 * Madori push service worker (#100). Renders Web Push payloads
 * ({ title, body, url, tag } from App\Push\PushPayload) as desktop
 * notifications, and focuses/opens the target URL when one is clicked.
 */
self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { title: "Madori", body: event.data ? event.data.text() : "" };
  }
  const title = data.title || "Madori";
  event.waitUntil(
    self.registration.showNotification(title, {
      body: data.body || "",
      icon: "/favicon.svg",
      badge: "/favicon.svg",
      tag: data.tag || undefined,
      data: { url: data.url || "/" },
    }),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || "/";
  event.waitUntil(
    self.clients
      .matchAll({ type: "window", includeUncontrolled: true })
      .then((clients) => {
        for (const client of clients) {
          if ("focus" in client && client.url === url) return client.focus();
        }
        if (self.clients.openWindow) return self.clients.openWindow(url);
      }),
  );
});
