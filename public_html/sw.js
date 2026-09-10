/* Co notes service worker.
 *
 * It exists so the app opens when the network does not - the shell comes from
 * cache, the board comes up empty with the offline dot already in the header,
 * and the existing retry path takes over when the connection returns.
 *
 * The one rule that matters: the API is never cached, and never served from
 * cache. Cards are shared and change under you; a stale card served from
 * disk would be a lie about what everyone else can see, and would defeat the
 * version checks that stop two people clobbering each other. The same goes for
 * /uploads/, which is other people's photos.
 *
 * Bump CACHE whenever the shell changes. `activate` deletes every older cache,
 * so a bump is the whole of a cache invalidation.
 *
 * VERSION must match the `?v=` on the app.css and app.js tags in index.html,
 * and both move together with CACHE. That is not decoration, it is the fix for
 * a real bug: index.html is network-first and the assets are cache-first, so a
 * deploy used to land new markup on top of the *previous* app.js for one whole
 * launch. The symptoms were a button that was visibly there and did nothing,
 * and old behaviour behind new UI - which reads as "the app is broken", not as
 * "the cache is one version behind". A versioned URL cannot be answered from a
 * cache that predates it, so the two can no longer disagree.
 * `php tools/doctor.php` fails if these drift apart.
 */

const VERSION = '20';
const CACHE = 'conotes-shell-v20';

const SHELL = [
  '/',
  '/index.html',
  '/assets/app.css?v=' + VERSION,
  '/assets/app.js?v=' + VERSION,
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/favicon-32.png',
];

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    // addAll fails the whole install if any one file 404s; these are all ours
    // and all committed, so that is the behaviour we want.
    await cache.addAll(SHELL);
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter(n => n !== CACHE).map(n => caches.delete(n)));
    await self.clients.claim();
  })());
});

/**
 * How each kind of notification reads.
 *
 * Two of these are somebody doing something and two are a date arriving, and
 * they want different sentences: "Sam flagged a card" names the person because
 * the person is the news, where "Due tomorrow" does not, because nobody did
 * anything - the date simply got close.
 *
 * `when` turns a wall-clock date into today/tomorrow where it can. The dates
 * are wall clock by design (see CLAUDE.md) so they are compared as local
 * calendar days, never converted through a timezone.
 */
function when(dateStr){
  if(!dateStr)return '';
  const d = new Date(dateStr + 'T00:00:00');
  const today = new Date(); today.setHours(0,0,0,0);
  const days = Math.round((d - today) / 86400000);
  if(days <= 0) return 'today';
  if(days === 1) return 'tomorrow';
  return d.toLocaleDateString(undefined, { weekday:'long', day:'numeric', month:'long' });
}

function title(item){
  const board = item.boardName;
  switch(item.kind){
    case 'event':
      return { head: item.by + ' added an event',
               body: item.title + ' · ' + when(item.date) + ' · ' + board };
    case 'due':
      return { head: 'Due ' + when(item.date),
               body: item.title + ' · ' + board };
    case 'soon':
      return { head: (item.time ? item.time + ' · ' : '') + when(item.date),
               body: item.title + ' · ' + board };
    default:
      return { head: item.by + ' flagged a card',
               body: item.title + ' · ' + board };
  }
}

/* ---------------------------------------------------------------- push ---
 *
 * The pushes this app sends carry no payload - see app/Push.php for why - so
 * the notification text is not in the message. What arrives is a signed "wake
 * up"; this asks the server what it was about.
 *
 * `userVisibleOnly` is part of the subscription contract: the browser expects
 * a notification for every push it delivers, and will show its own "site
 * updated in the background" one if we show nothing. So the fallback below is
 * not politeness, it is the contract.
 */
self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let items = [];

    try {
      const sub = await self.registration.pushManager.getSubscription();
      if (sub) {
        const res = await fetch(
          '/api/push/pending?endpoint=' + encodeURIComponent(sub.endpoint),
          { credentials: 'same-origin', cache: 'no-store' }
        );
        if (res.ok) {
          const data = await res.json();
          items = Array.isArray(data.items) ? data.items : [];
        }
      }
    } catch (offlineOrSignedOut) {
      // Falls through to the generic notification below.
    }

    if (!items.length) {
      await self.registration.showNotification('Co notes', {
        body: 'A card was flagged on one of your boards.',
        icon: '/icons/icon-192.png',
        badge: '/icons/favicon-32.png',
        tag: 'conotes-flag',
      });
      return;
    }

    // One notification per item, tagged by kind and id: if the same thing is
    // delivered twice the second replaces the first instead of stacking.
    await Promise.all(items.map(item => {
      const t = title(item);
      return self.registration.showNotification(t.head, {
        body: t.body,
        icon: '/icons/icon-192.png',
        badge: '/icons/favicon-32.png',
        tag: 'conotes-' + item.kind + '-' + item.id,
        timestamp: item.at ? Date.parse(item.at) : Date.now(),
        data: { url: '/b/' + item.boardSlug },
      });
    }));
  })());
});

/* Tapping a notification should land on the board it came from, and reuse an
 * open tab rather than piling up new ones. */
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of all) {
      if (new URL(client.url).origin === self.location.origin) {
        await client.focus();
        if ('navigate' in client) {
          try { await client.navigate(target); } catch (blocked) {}
        }
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/uploads/')) return;

  // A navigation is any URL the SPA shell answers - '/', '/b/<slug>',
  // '/join/<token>'. Network first, because index.html is the one file that
  // must not go stale; the cached shell is the offline fallback.
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req);
        const cache = await caches.open(CACHE);
        cache.put('/index.html', fresh.clone());
        return fresh;
      } catch (offline) {
        return (await caches.match('/index.html')) || Response.error();
      }
    })());
    return;
  }

  // Everything else here is our own static asset. The shell's own JS and CSS
  // carry `?v=` and are matched exactly, so a hit is by definition the right
  // version and cache-first is safe and instant. Anything carrying a *different*
  // version simply will not match, so it goes to the network - which is the
  // whole point, and why this is no longer background-refresh.
  //
  // The un-versioned leftovers (icons, the manifest) keep the old
  // serve-then-refresh behaviour; they are content-stable and none of them can
  // contradict the markup.
  event.respondWith((async () => {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(req);
    if (hit) return hit;

    try {
      const res = await fetch(req);
      if (res && res.ok) cache.put(req, res.clone());
      return res;
    } catch (offline) {
      // A versioned asset that is not cached and not reachable is fatal for
      // this load, but the navigation fallback above still opens the app.
      return Response.error();
    }
  })());
});
