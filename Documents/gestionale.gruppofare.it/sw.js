// public_html/calendario/sw.js
self.addEventListener('push', event => {
    const data = event.data.json();
    const options = {
        body: data.body,
        icon: '../Loghi/LogoCRM.png',
        badge: '../Loghi/LogoCRM.png',
         { url: data.url },
        actions: [{ action: 'view', title: 'Apri' }]
    };
    event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(clients.openWindow(event.notification.data.url));
});
