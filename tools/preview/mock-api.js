/* Offline preview harness.
 *
 * Stubs window.fetch with an in-memory implementation of the Co notes API, so the
 * real public_html/assets/app.js can be opened straight from disk - no PHP, no
 * MySQL, no OAuth. Useful for checking the UI and for reproducing sync,
 * conflict, profile and theme behaviour without a server.
 *
 * Photo uploads are handled too: the chosen file is turned into a data: URL, so
 * picking a photo really does change the avatars on screen.
 *
 * This file is a development aid. It is not part of the deployed app.
 */
(function () {
"use strict";

const now = () => new Date().toISOString();

const THEMES = [
  { id: 'lime',   label: 'Lime'   },
  { id: 'amber',  label: 'Amber'  },
  { id: 'teal',   label: 'Teal'   },
  { id: 'sky',    label: 'Sky'    },
  { id: 'violet', label: 'Violet' },
  { id: 'rose',   label: 'Rose'   },
];

/* --- master profiles ----------------------------------------------------- */

const users = {
  u_me: {
    id: 'u_me', displayName: 'Dharanish', username: 'dharanish',
    color: '#2E7D74', email: 'dharanishpk18@gmail.com',
    avatar: null, hasPhoto: false, defaultTheme: 'lime', provider: 'google',
  },
  u_sam:  { id: 'u_sam',  displayName: 'Sam Okafor', username: 'sokafor', color: '#B0603A', avatar: null },
  u_rina: { id: 'u_rina', displayName: 'Rina Devi',  username: 'rina',    color: '#7A5AA8', avatar: null },
};

/* --- per-board profiles: boardId -> userId -> overrides ------------------- */

const boardProfiles = {
  b1: {
    u_me:   { displayName: null, color: null, avatar: null },
    u_sam:  { displayName: null, color: null, avatar: null },
    u_rina: { displayName: null, color: null, avatar: null },
  },
  b2: {
    // A deliberate example: a different name on the second board.
    u_me: { displayName: 'D.', color: '#A8862E', avatar: null },
  },
};

let cursor = 12;
let noteSeq = 3;
let eventSeq = 2;

/** Today and a few days out, so the seeded calendar is never in the past. */
function dayOffset(n){
  const d = new Date(); d.setDate(d.getDate() + n);
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0')
       + '-' + String(d.getDate()).padStart(2,'0');
}
let activitySeq = 12;

/** Two minutes, same as Notes::EDIT_COALESCE_SECONDS on the real backend. */
const EDIT_COALESCE_MS = 120000;

/**
 * Append to a board's activity feed, mirroring Notes::recordEdit(): a run of
 * 'edited' rows by the same person on the same card inside the coalesce
 * window slides the existing row forward instead of adding another, so a burst
 * of keystrokes reads as one entry.
 */
function recordActivity(bid, action, noteId, title, userId, force) {
  const list = state.activity[bid];
  if (!force && action === 'edited') {
    const recent = list.find(a => a.kind === 'note' && a.refId === noteId && a.userId === userId
      && a.action === 'edited' && (Date.now() - new Date(a.at).getTime()) < EDIT_COALESCE_MS);
    if (recent) { recent.at = now(); return; }
  }
  list.unshift({ id: ++activitySeq, kind: 'note', action, at: now(), refId: noteId, title, userId });
}

/**
 * Same idea for events. No coalescing here, matching the real backend: an
 * event is saved once from a modal with an explicit Save button, not typed
 * into with a debounce, so there is no keystroke burst to collapse.
 */
function recordEventActivity(bid, action, eventId, title, userId) {
  state.activity[bid].unshift({ id: ++activitySeq, kind: 'event', action, at: now(), refId: eventId, title, userId });
}

/** How a person appears on a board: override first, master second. */
function person(userId, boardId) {
  const master = users[userId] || { displayName: 'Someone', color: '#4B5240' };
  const ov = (boardProfiles[boardId] || {})[userId] || {};
  return {
    id: userId,
    name: ov.displayName || master.displayName,
    color: ov.color || master.color,
    username: master.username || null,
    avatar: ov.avatar || master.avatar || null,
  };
}

const state = {
  signedIn: true,
  // 87 chars, the length of a real VAPID public key. Set to null to see what
  // the app does on a server with no keypair: the Notifications row hides.
  pushKey: 'B' + 'MockVapidKeyForThePreviewHarnessOnly'.padEnd(86, 'x'),
  pushSubscribed: false,
  pushPending: [],
  boards: [
    // The theme is the board's, shared by everyone on it - b2 is a different
    // colour for every member, not just for me.
    { id: 'b1', slug: 'studio-notes-a1b2c3', name: 'Studio notes', theme: 'lime',   role: 'owner' },
    { id: 'b2', slug: 'reading-list-d4e5f6', name: 'Reading list', theme: 'violet', role: 'member' },
  ],
  memberIds: { b1: ['u_me', 'u_sam', 'u_rina'], b2: ['u_me'] },
  roles: { b1: { u_me: 'owner', u_sam: 'member', u_rina: 'member' }, b2: { u_me: 'member' } },
  notes: {
    b1: [
      {
        id: '01HZZZZZZZZZZZZZZZZZZZZZN1',
        title: 'Kitchen rebuild — what we agreed',
        content: 'Counter depth stays at 640mm. Sam is chasing the fabricator for a revised quote by Thursday. If it comes back over budget we drop the island overhang, not the appliance spec.',
        pinned: true, version: 4,
        createdAt: new Date(Date.now() - 86400000 * 3).toISOString(),
        updatedAt: new Date(Date.now() - 1000 * 60 * 12).toISOString(),
        updatedById: 'u_sam',
        createdById: 'u_rina',
        deletedAt: null,
      },
      {
        id: '01HZZZZZZZZZZZZZZZZZZZZZN2',
        title: 'Supplier shortlist',
        content: 'Three quotes in, two still outstanding. Nothing decided yet.',
        pinned: false, version: 2,
        createdAt: new Date(Date.now() - 86400000 * 5).toISOString(),
        updatedAt: new Date(Date.now() - 1000 * 60 * 90).toISOString(),
        updatedById: 'u_rina',
        createdById: 'u_rina',
        deletedAt: null,
      },
      {
        id: '01HZZZZZZZZZZZZZZZZZZZZZN3',
        title: 'Questions for the site visit',
        content: 'Where does the soil stack actually run? Is the party wall load bearing above the return?',
        pinned: false, version: 1,
        createdAt: new Date(Date.now() - 86400000 * 6).toISOString(),
        updatedAt: new Date(Date.now() - 86400000 * 2).toISOString(),
        updatedById: 'u_me',
        createdById: 'u_me',
        deletedAt: null,
      },
    ],
    b2: [],
  },
  trash: {
    b1: [{
      id: '01HZZZZZZZZZZZZZZZZZZZZZD1',
      title: 'Old paint samples', content: 'Superseded.',
      pinned: false, version: 3,
      createdAt: new Date(Date.now() - 86400000 * 9).toISOString(),
      updatedAt: new Date(Date.now() - 86400000 * 4).toISOString(),
      deletedAt: new Date(Date.now() - 86400000 * 1).toISOString(),
      updatedById: 'u_me',
    }],
    b2: [],
  },
  calendar: {
    b1: [
      { id: '01HZZZZZZZZZZZZZZZZZZZZZE1', title: 'Site visit with the fabricator',
        detail: 'Bring the revised counter drawings', date: dayOffset(1), time: '15:00',
        version: 1, updatedById: 'u_sam' },
      { id: '01HZZZZZZZZZZZZZZZZZZZZZE2', title: 'Kitchen delivery window',
        detail: '', date: dayOffset(4), time: null, version: 1, updatedById: 'u_rina' },
    ],
    b2: [],
  },
  activity: {
    b1: [
      { id: 12, kind: 'note', action: 'edited',  at: new Date(Date.now() - 1000 * 60 * 12).toISOString(), refId: '01HZZZZZZZZZZZZZZZZZZZZZN1', title: 'Kitchen rebuild — what we agreed', userId: 'u_sam' },
      { id: 11, kind: 'note', action: 'flagged', at: new Date(Date.now() - 1000 * 60 * 40).toISOString(), refId: '01HZZZZZZZZZZZZZZZZZZZZZN1', title: 'Kitchen rebuild — what we agreed', userId: 'u_me' },
      { id: 10, kind: 'note', action: 'edited',  at: new Date(Date.now() - 1000 * 60 * 90).toISOString(), refId: '01HZZZZZZZZZZZZZZZZZZZZZN2', title: 'Supplier shortlist', userId: 'u_rina' },
      { id: 9,  kind: 'note', action: 'created', at: new Date(Date.now() - 86400000 * 5).toISOString(), refId: '01HZZZZZZZZZZZZZZZZZZZZZN2', title: 'Supplier shortlist', userId: 'u_rina' },
    ],
    b2: [],
  },
};

/* Serialise a stored note into the shape the API returns, resolving people
 * against the board they are on. */
function noteOut(n, boardId) {
  return {
    id: n.id, title: n.title, content: n.content, pinned: n.pinned,
    dueDate: n.dueDate || null, version: n.version,
    createdAt: n.createdAt, updatedAt: n.updatedAt, deletedAt: n.deletedAt || null,
    updatedBy: n.updatedById ? person(n.updatedById, boardId) : null,
    createdBy: n.createdById ? person(n.createdById, boardId) : null,
  };
}

function eventOut(e, boardId) {
  return {
    id: e.id, title: e.title, detail: e.detail || '',
    date: e.date, time: e.time || null, allDay: !e.time,
    version: e.version,
    createdAt: now(), updatedAt: now(),
    updatedBy: e.updatedById ? person(e.updatedById, boardId) : null,
  };
}

function membersOut(boardId) {
  return state.memberIds[boardId].map(id => {
    const p = person(id, boardId);
    p.role = (state.roles[boardId] || {})[id] || 'member';
    p.joinedAt = now();
    return p;
  });
}

function profileOut(boardId, userId) {
  const ov = (boardProfiles[boardId] || {})[userId] || {};
  const master = users[userId];
  return {
    role: (state.roles[boardId] || {})[userId] || 'member',
    overrides: {
      displayName: ov.displayName || null,
      hasPhoto: !!ov.avatar,
    },
    effective: {
      name: ov.displayName || master.displayName,
      color: ov.color || master.color,
      avatar: ov.avatar || master.avatar || null,
    },
  };
}

/* Flip these from the console to exercise the harder paths:
 *   __mock.signOut()        -> sign-in modal
 *   __mock.forceConflict()  -> next save returns 409
 *   __mock.goOffline()      -> next request fails at the network layer
 *   __mock.remoteEdit()     -> another person edits a card, arrives via sync
 *   __mock.remoteTheme()    -> another person restyles the board, for everyone
 */
let forceConflict = false;
let networkDown = false;

const events = [];

window.__mock = {
  signOut(){ state.signedIn = false; location.reload(); },

  /* Queue what GET /api/push/pending will hand back, so the four notification
     wordings can be read without waiting on a real push service. The service
     worker is what normally calls that endpoint; from a page you can just do
     `await (await fetch('/api/push/pending')).json()` after this. */
  pushFlag(){
    state.pushPending.push({ kind:'flagged', id:'n1', title:'Kitchen rebuild',
      boardName:'Studio notes', boardSlug:'studio-notes-a1b2c3',
      by:'Sam Okafor', at:new Date().toISOString() });
    console.log('queued a flagged-card notification');
  },
  pushEvent(){
    state.pushPending.push({ kind:'event', id:'e1', title:'Site visit',
      date:new Date(Date.now()+864e5).toISOString().slice(0,10),
      boardName:'Studio notes', boardSlug:'studio-notes-a1b2c3',
      by:'Rina Devi', at:new Date().toISOString() });
    console.log('queued an event-added notification');
  },
  pushDue(){
    state.pushPending.push({ kind:'due', id:'n2', title:'Call the tiler',
      date:new Date().toISOString().slice(0,10),
      boardName:'Studio notes', boardSlug:'studio-notes-a1b2c3',
      by:null, at:new Date().toISOString() });
    console.log('queued a due-date reminder');
  },
  pushSoon(){
    state.pushPending.push({ kind:'soon', id:'e2', title:'Fabricator call',
      date:new Date().toISOString().slice(0,10), time:'15:00',
      boardName:'Studio notes', boardSlug:'studio-notes-a1b2c3',
      by:null, at:new Date().toISOString() });
    console.log('queued an event-coming-up reminder');
  },
  noPushKey(){ state.pushKey = null; console.log('reload to see the app with push unconfigured'); },

  forceConflict(){ forceConflict = true; console.log('next save will 409'); },
  goOffline(){ networkDown = true; console.log('network is down'); },
  goOnline(){ networkDown = false; console.log('network is up'); },
  remoteEdit(){
    const n = state.notes.b1[1];
    n.title = 'Supplier shortlist (Sam edited this remotely)';
    n.version += 1;
    n.updatedAt = now();
    n.updatedById = 'u_sam';
    cursor += 1;
    events.push({ id: cursor, type: 'note.updated', noteId: n.id });
    console.log('remote edit queued at cursor', cursor);
  },
  /** Someone else drops an event on the board calendar, via the delta feed. */
  remoteEvent(title){
    const d = new Date(); d.setDate(d.getDate() + 2);
    const date = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0')
               + '-' + String(d.getDate()).padStart(2,'0');
    const id = '01HZZZZZZZZZZZZZZZZZZZZZE' + (++eventSeq + 3);
    const t  = title || 'Sam booked the surveyor';
    state.calendar.b1.push({ id, title: t, detail: '', date, time: '09:30', version: 1, updatedById: 'u_sam' });
    recordEventActivity('b1', 'created', id, t, 'u_sam');
    cursor += 1;
    events.push({ id: cursor, type: 'event.created' });
    console.log('event added on', date, 'at cursor', cursor);
  },
  /** Someone else on the board picks a theme; it arrives via the delta feed. */
  remoteTheme(id){
    const next = id || (state.boards[0].theme === 'lime' ? 'rose' : 'lime');
    if (!THEMES.some(t => t.id === next)) { console.log('unknown theme', next); return; }
    state.boards[0].theme = next;
    cursor += 1;
    events.push({ id: cursor, type: 'board.theme' });
    console.log('board 1 themed', next, 'at cursor', cursor);
  },
  /** The board's owner renames it elsewhere; it arrives via the delta feed. */
  remoteRename(name){
    state.boards[0].name = name || (state.boards[0].name === 'Studio notes' ? 'Studio notes — Q3' : 'Studio notes');
    cursor += 1;
    events.push({ id: cursor, type: 'board.renamed' });
    console.log('board 1 renamed', state.boards[0].name, 'at cursor', cursor);
  },
  /** Give someone else a board name and photo, to see overrides from outside. */
  disguiseSam(){
    boardProfiles.b1.u_sam.displayName = 'Sam (site)';
    boardProfiles.b1.u_sam.color = '#A8425F';
    cursor += 1;
    events.push({ id: cursor, type: 'member.updated' });
    console.log('Sam renamed on board 1');
  },
  state, users, boardProfiles,
};

function boardBySlug(slug){ return state.boards.find(b => b.slug === slug) || null; }

/* Mirrors Notes::flagDue(): a card whose due date has arrived flags itself, on
 * the *server's* date. Once per card, not once per poll - the real one marks
 * it with a userless note_edits row and so does this, which is also what makes
 * "came due" show up in Activity. Unflagging afterwards therefore sticks. */
function flagDue(boardId){
  const today = new Date().toISOString().slice(0, 10);
  const marked = id => (state.activity[boardId] || [])
    .some(a => a.kind === 'note' && a.refId === id && a.action === 'due');

  (state.notes[boardId] || []).forEach(n => {
    if (n.pinned || !n.dueDate || n.dueDate > today || marked(n.id)) return;
    n.pinned = true;
    n.version += 1;
    recordActivity(boardId, 'due', n.id, n.title, null, true);
    cursor += 1;
    events.push({ id: cursor, type: 'note.updated', noteId: n.id });
    console.log('[mock] came due, flagged:', n.title, n.dueDate);
  });
}

function json(body, status){
  return Promise.resolve(new Response(JSON.stringify(body), {
    status: status || 200,
    headers: { 'Content-Type': 'application/json' },
  }));
}

/**
 * Matches Http::noContent() on the real backend: status 204 with no body at
 * all. The Fetch spec forbids a body on 204/205/304 - the Response
 * constructor throws a TypeError if you give it one - so this must stay
 * separate from json(), never json(something, 204).
 */
function noContent(){
  return Promise.resolve(new Response(null, { status: 204 }));
}

function fail(status, code, message, extra){
  return json(Object.assign({ error: { code, message } }, extra || {}), status);
}

function boardList(){
  // Membership-gated, like Boards::forUser(): a board u_me has left drops out
  // the moment state.memberIds no longer names them, not from a stale flag.
  return state.boards
    .filter(b => state.memberIds[b.id].includes('u_me'))
    .map(b => ({
      id: b.id, slug: b.slug, name: b.name, role: (state.roles[b.id] || {}).u_me || 'member',
      theme: b.theme,
      noteCount: state.notes[b.id].length,
      memberCount: state.memberIds[b.id].length,
      lastActivity: state.notes[b.id][0] ? state.notes[b.id][0].updatedAt : null,
    }));
}

/** Read an uploaded File into a data: URL so the preview can actually show it. */
function readAsDataUrl(file){
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('could not read file'));
    reader.readAsDataURL(file);
  });
}

window.fetch = function (url, options) {
  const opts = options || {};
  const method = (opts.method || 'GET').toUpperCase();
  const path = String(url).replace(/^\/api/, '');
  const [route, queryString] = path.split('?');
  const query = new URLSearchParams(queryString || '');
  const isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  const body = (opts.body && !isForm) ? JSON.parse(opts.body) : null;
  const seg = route.split('/').filter(Boolean);

  if (networkDown) return Promise.reject(new TypeError('Failed to fetch'));

  // Slight latency, so the saving indicator is actually visible.
  return new Promise(resolve => setTimeout(resolve, 60)).then(async () => {

    /* --- session and master profile --- */
    if (seg[0] === 'session') {
      if (seg.length === 1) {
        if (method === 'DELETE') { state.signedIn = false; return noContent(); }
        if (method === 'PATCH') {
          if (body.username === 'taken') {
            return fail(409, 'username_taken', 'That username is already taken.');
          }
          if (body.displayName !== undefined) users.u_me.displayName = body.displayName;
          if (body.username !== undefined) users.u_me.username = body.username;
          if (body.defaultTheme !== undefined) users.u_me.defaultTheme = body.defaultTheme;
          return json({ user: users.u_me });
        }
        return json({
          user: state.signedIn ? users.u_me : null,
          providers: ['google', 'github'],
          themes: THEMES,
          // A VAPID public key is 65 bytes base64url - 87 characters. The
          // client only measures it and hands it to subscribe(), so a
          // well-formed fake is enough to make the Notifications setting
          // appear here. Without it push.key is null, the row hides, and the
          // harness silently cannot test a feature that works in production.
          pushKey: state.pushKey,
        });
      }

      if (seg[1] === 'photo') {
        if (method === 'POST') {
          const file = opts.body.get('photo');
          if (!file) return fail(400, 'no_file', 'No photo was uploaded.');
          users.u_me.avatar = await readAsDataUrl(file);
          users.u_me.hasPhoto = true;
          return json({ user: users.u_me });
        }
        if (method === 'DELETE') {
          users.u_me.avatar = null;
          users.u_me.hasPhoto = false;
          return json({ user: users.u_me });
        }
      }
    }

    if (!state.signedIn) return fail(401, 'unauthenticated', 'Sign in to continue.');

    /* --- boards --- */
    /* --- push --- */
    if (seg[0] === 'push') {
      if (!state.signedIn) return fail(401, 'unauthenticated', 'Sign in to continue.');

      if (seg[1] === 'subscribe' && method === 'POST') {
        if (!body.endpoint) {
          return fail(400, 'bad_subscription', 'A push endpoint is required.');
        }
        state.pushSubscribed = true;
        console.log('[mock] push subscribed:', body.endpoint.slice(0, 48) + '…');
        return json({ ok: true });
      }
      if (seg[1] === 'unsubscribe' && method === 'POST') {
        state.pushSubscribed = false;
        console.log('[mock] push unsubscribed');
        return json({ ok: true });
      }
      // What the service worker asks after a payload-less push. __mock.pending()
      // is what puts something in here; otherwise it is empty, exactly as the
      // real endpoint is once its watermark has moved.
      if (seg[1] === 'pending' && method === 'GET') {
        const items = state.pushPending;
        state.pushPending = [];
        return json({ items });
      }
      return fail(404, 'not_found', 'Unknown push route.');
    }

    if (seg[0] === 'boards' && seg.length === 1) {
      if (method === 'POST') {
        const id = 'b' + (state.boards.length + 1);
        const slug = body.name.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-aaa111';
        state.boards.push({ id, slug, name: body.name, theme: users.u_me.defaultTheme, role: 'owner' });
        state.notes[id] = []; state.trash[id] = []; state.activity[id] = [];
        state.calendar[id] = [];
        state.memberIds[id] = ['u_me'];
        state.roles[id] = { u_me: 'owner' };
        boardProfiles[id] = { u_me: { displayName: null, color: null, avatar: null } };
        return json({ board: { id, slug, name: body.name, theme: users.u_me.defaultTheme, role: 'owner' } }, 201);
      }
      return json({ boards: boardList() });
    }

    if (seg[0] === 'boards' && seg.length >= 2) {
      const board = boardBySlug(seg[1]);
      if (!board) return fail(404, 'not_found', 'That board does not exist, or you are not a member of it.');
      const bid = board.id;

      if (seg.length === 2 && method === 'GET') {
        flagDue(bid);
        return json({
          board,
          profile: profileOut(bid, 'u_me'),
          notes: state.notes[bid].map(n => noteOut(n, bid)),
          members: membersOut(bid),
          cursor,
        });
      }

      /* --- per-board profile --- */
      if (seg[2] === 'profile' && seg.length === 3) {
        if (method === 'GET') return json({ profile: profileOut(bid, 'u_me') });
        if (method === 'PATCH') {
          const ov = boardProfiles[bid].u_me;
          if (body.displayName !== undefined) {
            ov.displayName = body.displayName;
            ov.color = body.displayName ? '#A8862E' : null;
          }
          cursor += 1;
          return json({ profile: profileOut(bid, 'u_me'), members: membersOut(bid), cursor });
        }
      }

      if (seg[2] === 'profile' && seg[3] === 'photo') {
        const ov = boardProfiles[bid].u_me;
        if (method === 'POST') {
          const file = opts.body.get('photo');
          if (!file) return fail(400, 'no_file', 'No photo was uploaded.');
          ov.avatar = await readAsDataUrl(file);
        } else if (method === 'DELETE') {
          ov.avatar = null;
        }
        cursor += 1;
        return json({ profile: profileOut(bid, 'u_me'), members: membersOut(bid), cursor });
      }

      /* --- the board calendar: shared, any member may add to it --- */
      if (seg[2] === 'calendar' && seg.length === 3) {
        if (method === 'GET') {
          const from = query.get('from') || '0000-01-01';
          const to   = query.get('to')   || '9999-12-31';
          const events = (state.calendar[bid] || [])
            .filter(e => e.date >= from && e.date <= to)
            .sort((a, b) => a.date.localeCompare(b.date)
                          || String(a.time || '').localeCompare(String(b.time || '')))
            .map(e => eventOut(e, bid));
          const cards = state.notes[bid]
            .filter(n => n.dueDate && n.dueDate >= from && n.dueDate <= to)
            .map(n => ({ id: n.id, title: n.title, pinned: n.pinned, date: n.dueDate }));
          return json({ events, cards });
        }

        if (method === 'POST') {
          if (!body.title || !String(body.title).trim()) {
            return fail(400, 'empty_title', 'An event needs a title.');
          }
          if (!/^\d{4}-\d{2}-\d{2}$/.test(String(body.date || ''))) {
            return fail(400, 'invalid_date', 'That date is not a valid date.');
          }
          const ev = {
            id: '01HZZZZZZZZZZZZZZZZZZZZZE' + (++eventSeq + 3),
            title: String(body.title).trim(), detail: body.detail || '',
            date: body.date, time: body.time || null, version: 1, updatedById: 'u_me',
          };
          (state.calendar[bid] = state.calendar[bid] || []).push(ev);
          recordEventActivity(bid, 'created', ev.id, ev.title, 'u_me');
          cursor += 1;
          events.push({ id: cursor, type: 'event.created' });
          return json({ event: eventOut(ev, bid), cursor }, 201);
        }
      }

      /* --- rename: shared like the theme, but owners only. Mirrors
             Boards::rename() - the slug deliberately does not move. --- */
      if (seg.length === 2 && method === 'PATCH') {
        if (body.name === undefined) return fail(400, 'empty_patch', 'Nothing to update.');
        if (((state.roles[bid] || {}).u_me) !== 'owner') {
          return fail(403, 'forbidden', 'Only the board owner can rename the board.');
        }
        const name = String(body.name).trim();
        if (!name) return fail(400, 'invalid_board_name', 'Board name cannot be empty.');
        board.name = name.slice(0, 80);
        cursor += 1;
        events.push({ id: cursor, type: 'board.renamed' });
        return json({ board, cursor });
      }

      /* --- the board's theme: shared, any member may set it --- */
      if (seg[2] === 'theme' && seg.length === 3 && method === 'PATCH') {
        if (body.theme === undefined) return fail(400, 'empty_patch', 'Nothing to update.');
        if (!THEMES.some(t => t.id === body.theme)) {
          return fail(400, 'invalid_theme', 'Unknown theme.');
        }
        board.theme = body.theme;
        cursor += 1;
        events.push({ id: cursor, type: 'board.theme' });
        return json({ theme: board.theme, cursor });
      }

      if (seg[2] === 'notes' && method === 'POST') {
        const note = {
          id: '01HZZZZZZZZZZZZZZZZZZZZZN' + (++noteSeq + 3),
          title: '', content: '', pinned: false, version: 1,
          createdAt: now(), updatedAt: now(), deletedAt: null,
          updatedById: 'u_me', createdById: 'u_me',
        };
        state.notes[bid].unshift(note);
        recordActivity(bid, 'created', note.id, note.title, 'u_me', true);
        cursor += 1;
        return json({ note: noteOut(note, bid), cursor }, 201);
      }

      if (seg[2] === 'changes') {
        flagDue(bid);
        const since = parseInt(query.get('since') || '0', 10);
        const pending = events.filter(e => e.id > since);
        if (pending.some(e => e.type === 'member.updated' || e.type === 'board.reset')) {
          return json({ cursor, resync: true, changed: [], deleted: [] });
        }
        const changed = pending
          .filter(e => e.type === 'note.updated')
          .map(e => state.notes[bid].find(n => n.id === e.noteId))
          .filter(Boolean)
          .map(n => noteOut(n, bid));
        const themed  = pending.some(e => e.type === 'board.theme');
        const renamed = pending.some(e => e.type === 'board.renamed');
        const moved   = pending.some(e => String(e.type).indexOf('event.') === 0);
        return json({ cursor, changed, deleted: [], members: null,
                      theme: themed ? board.theme : null,
                      name: renamed ? board.name : null, calendar: moved });
      }

      if (seg[2] === 'activity') {
        return json({
          items: state.activity[bid].map(a => ({
            kind: a.kind, action: a.action, at: a.at, refId: a.refId,
            title: a.title, deleted: false, user: person(a.userId, bid),
          })),
          nextCursor: null,
        });
      }

      if (seg[2] === 'trash') {
        return json({ notes: state.trash[bid].map(n => noteOut(n, bid)) });
      }
      if (seg[2] === 'members') return json({ members: membersOut(bid) });

      if (seg[2] === 'invites' && method === 'POST') {
        return json({ invite: { token: 'AAAAAAAAAAAAAAAAAAAAAA', url: location.origin + '/join/AAAAAAAAAAAAAAAAAAAAAA', expiresAt: now() } }, 201);
      }
      if (seg[2] === 'reset' && method === 'POST') {
        state.trash[bid] = state.notes[bid].map(n => Object.assign({}, n, { deletedAt: now() })).concat(state.trash[bid]);
        state.notes[bid] = [];
        cursor += 1;
        return json({ deleted: state.trash[bid].length, cursor });
      }
      if (seg.length === 2 && method === 'DELETE') {
        // Mirrors Boards::delete(): owners only, and soft - the board simply
        // stops being reachable. boardList() filters on membership, so dropping
        // the membership rows is enough to make it vanish for this client.
        if (((state.roles[bid] || {}).u_me) !== 'owner') {
          return fail(403, 'forbidden', 'Only the board owner can delete the board.');
        }
        state.boards = state.boards.filter(b => b.id !== bid);
        delete state.memberIds[bid];
        delete state.roles[bid];
        return noContent();
      }

      if (seg[2] === 'leave' && method === 'POST') {
        // Mirrors Boards::leave(): the last owner cannot leave, otherwise the
        // membership row goes away and a later GET /boards excludes this board.
        const myRole = (state.roles[bid] || {}).u_me;
        if (myRole === 'owner') {
          const owners = state.memberIds[bid].filter(id => (state.roles[bid] || {})[id] === 'owner');
          if (owners.length <= 1) {
            return fail(400, 'last_owner',
              'You are the only owner. Make someone else an owner first, or reset the board.');
          }
        }
        state.memberIds[bid] = state.memberIds[bid].filter(id => id !== 'u_me');
        delete state.roles[bid].u_me;
        return noContent();
      }
    }

    /* --- notes --- */
    if (seg[0] === 'calendar' && seg.length === 2) {
      const owner = state.boards.find(b => (state.calendar[b.id] || []).some(e => e.id === seg[1]));
      if (!owner) return fail(404, 'not_found', 'That event has been deleted.');
      const bid = owner.id;
      const list = state.calendar[bid];
      const idx = list.findIndex(e => e.id === seg[1]);
      const ev = list[idx];

      if (method === 'PATCH') {
        if (body.version !== ev.version) {
          return fail(409, 'version_conflict', 'This event changed while you were editing it.',
            { current: eventOut(ev, bid) });
        }
        if (body.title !== undefined) {
          if (!String(body.title).trim()) return fail(400, 'empty_title', 'An event needs a title.');
          ev.title = String(body.title).trim();
        }
        if (body.date   !== undefined) ev.date = body.date;
        if (body.time   !== undefined) ev.time = body.time || null;
        if (body.detail !== undefined) ev.detail = body.detail;
        ev.version += 1;
        ev.updatedById = 'u_me';
        recordEventActivity(bid, 'updated', ev.id, ev.title, 'u_me');
        cursor += 1;
        events.push({ id: cursor, type: 'event.updated' });
        return json({ event: eventOut(ev, bid), cursor });
      }

      if (method === 'DELETE') {
        list.splice(idx, 1);
        recordEventActivity(bid, 'deleted', ev.id, ev.title, 'u_me');
        cursor += 1;
        events.push({ id: cursor, type: 'event.deleted' });
        return json({ ok: true, cursor });
      }
    }

    if (seg[0] === 'notes' && seg.length >= 2) {
      const owner = state.boards.find(b => state.notes[b.id].some(n => n.id === seg[1]))
                 || state.boards.find(b => state.trash[b.id].some(n => n.id === seg[1]));
      if (!owner) return fail(404, 'not_found', 'That card has been deleted.');
      const bid = owner.id;

      const list = state.notes[bid];
      const idx = list.findIndex(n => n.id === seg[1]);

      if (method === 'PATCH') {
        const note = list[idx];
        if (forceConflict) {
          forceConflict = false;
          note.version += 1;
          note.title += ' [saved by Sam]';
          note.content += '\n\nSam added this line while you were typing.';
          note.updatedAt = now();
          note.updatedById = 'u_sam';
          return fail(409, 'version_conflict', 'This card changed while you were editing it.',
            { current: noteOut(note, bid) });
        }
        if (body.version !== note.version) {
          return fail(409, 'version_conflict', 'This card changed while you were editing it.',
            { current: noteOut(note, bid) });
        }
        // Mirrors Notes::update(): 'flagged'/'unflagged' only when the request
        // body has no title/content key at all, not merely unchanged values.
        // The current client always sends all three, so in practice this client
        // never triggers that branch - matching the real server exactly.
        const pinnedOnly = ('pinned' in body) && !('title' in body) && !('content' in body);
        const action = pinnedOnly ? (body.pinned ? 'flagged' : 'unflagged') : 'edited';

        note.title = body.title;
        note.content = body.content;
        note.pinned = body.pinned;
        if (body.dueDate !== undefined) {
          const nextDue = body.dueDate || null;
          // A new date is a new alarm - and, as in Notes::clearDueMarkIfMoved(),
          // the test is that the date *moved*: every save carries dueDate, so
          // keying off its presence would clear the mark on every keystroke.
          if (nextDue !== note.dueDate) {
            state.activity[bid] = state.activity[bid]
              .filter(a => !(a.kind === 'note' && a.refId === note.id && a.action === 'due'));
          }
          note.dueDate = nextDue;
        }
        note.version += 1;
        note.updatedAt = now();
        note.updatedById = 'u_me';
        recordActivity(bid, action, note.id, note.title, 'u_me', action !== 'edited');
        cursor += 1;
        return json({ note: noteOut(note, bid), cursor });
      }

      if (method === 'DELETE') {
        const [removed] = list.splice(idx, 1);
        removed.deletedAt = now();
        state.trash[bid].unshift(removed);
        recordActivity(bid, 'deleted', removed.id, removed.title, 'u_me', true);
        cursor += 1;
        return json({ ok: true, cursor });
      }

      if (seg[2] === 'restore' && method === 'POST') {
        const ti = state.trash[bid].findIndex(n => n.id === seg[1]);
        if (ti < 0) return fail(404, 'not_found', 'That card is not in the trash.');
        const [note] = state.trash[bid].splice(ti, 1);
        note.deletedAt = null; note.version += 1; note.updatedAt = now();
        list.unshift(note);
        recordActivity(bid, 'restored', note.id, note.title, 'u_me', true);
        cursor += 1;
        return json({ note: noteOut(note, bid), cursor });
      }
    }

    if (seg[0] === 'invites') {
      if (seg.length === 2) return json({ board: { slug: 'studio-notes-a1b2c3', name: 'Studio notes', memberCount: 3 }, signedIn: state.signedIn });
      if (seg[2] === 'accept') return json({ slug: 'studio-notes-a1b2c3' });
    }

    return fail(404, 'not_found', 'No such endpoint.');
  });
};

console.log('[mock] Co notes API stubbed. Try __mock.forceConflict(), __mock.remoteEdit(), __mock.disguiseSam(), __mock.goOffline().');
})();
