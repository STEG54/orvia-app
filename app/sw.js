// Appli : notifications sur le téléphone. Le message est lu dans l'appli à la réception (rien de personnel ne transite par le service de notification).
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", e => e.waitUntil(self.clients.claim()));
self.addEventListener("push", e => {
  e.waitUntil((async () => {
    let n = {titre:"Nouveau message", texte:"Vous avez du nouveau dans votre appli.", vue:""};
    try { const r = await fetch("api.php?action=notif_derniere", {credentials:"include", cache:"no-store"}); if (r.ok) { const j = await r.json(); if (j.notif) n = j.notif; } } catch (x) {}
    await self.registration.showNotification(n.titre, {body:n.texte, icon:"assets/icon-192.png", badge:"assets/icon-192.png", data:{vue:n.vue || ""}, tag:"ag-" + (n.id || Date.now())});
  })());
});
self.addEventListener("notificationclick", e => {
  e.notification.close();
  const url = new URL("index.php" + (e.notification.data && e.notification.data.vue ? "#" + e.notification.data.vue : ""), self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({type:"window", includeUncontrolled:true}).then(L => {
    for (const c of L) if (c.url.startsWith(self.registration.scope) && "focus" in c) { c.navigate(url).catch(() => {}); return c.focus(); }
    return self.clients.openWindow(url);
  }));
});
