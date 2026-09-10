/* Co notes - shared notes board.
 *
 * The prototype kept the whole board in a browser key-value shim and wrote it
 * back wholesale. This talks to the API instead: per-note writes guarded by a
 * version, and a delta feed for live updates.
 *
 * All network access goes through the api() helper and the functions under
 * "the seam" below. Nothing else in this file calls fetch.
 */
(function () {
"use strict";

/* ---------------------------------------------------------------- config */

const PALETTE = ['#5E7A2E','#2E7D74','#B0603A','#7A5AA8','#A8862E','#3F6FA8','#A8425F','#4A7A4E'];

const SYNC_ACTIVE = 1000;   // delta poll while the tab is in front
const SYNC_HIDDEN = 15000;  // ...and while it is not
const DEBOUNCE    = 650;    // typing -> save
const RETRY_MAX   = 30000;  // offline backoff ceiling

/* ----------------------------------------------------------------- state */

let me       = null;        // master profile { id, displayName, username, email, avatar, defaultTheme }
let profile  = null;        // my profile on the open board { role, overrides, effective }
let themes   = [];          // theme catalogue from the server
let board    = null;        // { id, slug, name, theme, role }
let boards   = [];          // boards I am a member of
let notes    = {};          // id -> note
let members  = [];          // people on this board
let cursor   = 0;           // last board_event id we have applied
let push = { key: null, state: 'unsupported' };  // notifications; see the push section

let tab = 'notes', filter = 'all', activeId = null;
let query = '';            // the search box; filters the open board in memory
let saveTimer = null, pending = null, saving = false, queued = null;
let editSeq = 0;            // bumped per keystroke; see flushSave()
let conflict = null;        // { id, mine, theirs }
let retryDelay = 1000, retryTimer = null;
let syncTimer = null, offline = false;
let activity = { items: [], nextCursor: null, loaded: false };
let trash = { items: [], loaded: false };
let calendar = { events: [], cards: [], loaded: false, month: null, selected: null };

/* --------------------------------------------------------------- helpers */

const $ = id => document.getElementById(id);

const view   = $('view'),   chipsEl = $('chips'), sheet = $('sheet'), scrim = $('scrim');
const eTitle = $('e-title'), eContent = $('e-content'), eMeta = $('e-meta'),
      eTags  = $('e-tags'), live = $('live'),
      eRead  = $('e-read'),  eTools = $('e-tools'),
      eEditor= $('e-editor'), eMirror = $('e-mirror'),
      eMenu  = $('e-menu'),  eMenuPop = $('e-menu-pop'),
      mFlagTxt = $('m-flag-txt'), mDateTxt = $('m-date-txt');

function colorFor(name){let h=0;for(let i=0;i<name.length;i++)h=(h*31+name.charCodeAt(i))>>>0;return PALETTE[h%PALETTE.length];}
function initials(n){
  // First letter or digit of the first two words. Skipping punctuation matters
  // now that people choose their own board names - "Sam (site)" should read
  // SS, not "S(".
  const parts=String(n||'').trim().split(/\s+/)
    .map(w=>(w.match(/[\p{L}\p{N}]/u)||[])[0])
    .filter(Boolean);
  return parts.length?parts.slice(0,2).join('').toUpperCase():'?';
}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function ago(iso){
  if(!iso)return'';
  const m=Math.floor((Date.now()-new Date(iso).getTime())/60000);
  if(m<1)return'just now'; if(m<60)return m+'m ago';
  const h=Math.floor(m/60); if(h<24)return h+'h ago';
  const d=Math.floor(h/24); if(d<7)return d+'d ago';
  return new Date(iso).toLocaleDateString(undefined,{month:'short',day:'numeric'});
}
function clock(iso){return iso?new Date(iso).toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}):'';}
/**
 * A person's avatar: their photo if they have one, otherwise the initials disc
 * the app has always drawn. Takes the person object the API returns
 * ({ name, color, avatar }), so a board-specific name and photo flow through
 * every place a person appears without any call site knowing about it.
 */
function avatarHTML(person,size){
  const p=person||{};
  const s=size||42;
  const color=esc(p.color||'#4B5240');
  const inner=p.avatar
    ? `<img src="${esc(p.avatar)}" alt="" loading="lazy" onerror="this.remove()">`
    : esc(initials(p.name));
  return `<div class="avatar" style="width:${s}px;height:${s}px;background:${color};font-size:${Math.round(s*.36)}px">${inner}</div>`;
}

/** The signed-in user as a person object, for the header and profile screens. */
function meAsPerson(){
  if(!me)return{name:'?',color:'#4B5240',avatar:null};
  // On a board, my board profile is who I am; off one, my master profile is.
  return profile
    ? {id:me.id,name:profile.effective.name,color:profile.effective.color,avatar:profile.effective.avatar}
    : {id:me.id,name:me.displayName,color:me.color,avatar:me.avatar};
}
function setLive(state,msg){live.className='live-dot'+(state?' '+state:'');live.title=msg||'';}

/* localStorage remembers the last board opened. It is a convenience, not
 * state - and it throws outright in private windows and embedded contexts,
 * so every access is guarded. */
const store={
  get(k){ try{return localStorage.getItem(k);}catch(blocked){return null;} },
  set(k,v){ try{localStorage.setItem(k,v);}catch(blocked){} },
  remove(k){ try{localStorage.removeItem(k);}catch(blocked){} },
};

/* Keeping the address bar in step with the open board is cosmetic. If the
 * browser refuses the update, the board still opens. */
function setUrl(path){ try{history.replaceState(null,'',path);}catch(refused){} }

/* ---------------------------------------------------------------- themes */

/* Swatch colours for the picker: each theme is a *pair*, and the disc shows
 * both, because picking a theme by its first colour alone would be picking
 * half of it. The themes themselves live in app.css under
 * :root[data-theme="..."] as --acc / --acc-2; these are only what the picker
 * paints with, because a disc cannot ask the stylesheet what a theme is.
 * Keep the pairs in step with that block. */
const THEME_SWATCH = {
  lime:   ['#C8F04A','#5FE3A1'],
  amber:  ['#F0C24A','#F0894A'],
  teal:   ['#4AE8CE','#4AA8E8'],
  sky:    ['#7FC0FF','#A88FFF'],
  violet: ['#C0A2FF','#FF9BD0'],
  rose:   ['#FF9BB0','#FFC46B'],
};

/** The CSS a swatch is painted with - the same 135deg pair as --acc-grad. */
function swatchPaint(id){
  const pair=THEME_SWATCH[id];
  if(!pair)return '#888';
  return `linear-gradient(135deg, ${pair[0]} 0%, ${pair[1]} 100%)`;
}
const DEFAULT_THEME = 'lime';

/**
 * Paint a theme. Setting one attribute on <html> swaps the whole accent set,
 * because every accent value in the stylesheet is a token.
 */
function applyTheme(id){
  const theme = (id && THEME_SWATCH[id]) ? id : DEFAULT_THEME;
  document.documentElement.setAttribute('data-theme',theme);
  syncThemeColor();
  return theme;
}

/**
 * Keep the browser and OS chrome on the same ground the app is standing on.
 * Read from the stylesheet rather than a second table of colours here, so a
 * theme stays defined in exactly one place - and so light mode gets it right
 * without knowing anything about themes.
 */
function syncThemeColor(){
  const meta=$('meta-theme-color'); if(!meta)return;
  const ground=getComputedStyle(document.documentElement).getPropertyValue('--bg-1').trim();
  if(ground)meta.setAttribute('content',ground);
}

/**
 * The theme in force: the open board's. It is a property of the board and is
 * shared by everyone on it, so there is nothing personal left to fold in here.
 * The master default only stands in before a board is open.
 */
function currentTheme(){
  if(board&&board.theme)return board.theme;
  if(me&&me.defaultTheme)return me.defaultTheme;
  return DEFAULT_THEME;
}

function syncTheme(){ applyTheme(currentTheme()); }

/* ------------------------------------------------------------ light mode */

/**
 * Light or dark is a property of the *viewer*, not of the board. The accent
 * belongs to the board and is shared with everyone on it; the ground it sits
 * on is not, so two people on one board can read the same lime cards on
 * different grounds. It never leaves this device - there is nothing here for
 * the server or the delta feed to carry.
 *
 * Dark is the absence of the attribute rather than data-mode="dark", so the
 * default markup stays exactly what the prototype signed off.
 */
const MODES={dark:'Dark',light:'Light'};
let mode=store.get('conotes-mode')==='light'?'light':'dark';

function applyMode(id){
  mode=MODES[id]?id:'dark';
  if(mode==='light')document.documentElement.setAttribute('data-mode','light');
  else document.documentElement.removeAttribute('data-mode');
  store.set('conotes-mode',mode);
  syncThemeColor();
  return mode;
}

// Before anything is drawn, including the boot screen - a light-mode user
// should never see a dark flash on the way in.
applyMode(mode);

/* ------------------------------------------------------- board layout */

/**
 * One column or two. Like light and dark this is the viewer's, not the
 * board's: it changes nothing anyone else sees, so it lives in localStorage
 * and never reaches the server.
 *
 * Two columns is plain CSS columns rather than a measured masonry. The
 * browser balances the two, which is what makes it look packed; the trade is
 * that cards read down one column and then the other, not left-right.
 */
let cardsLayout=store.get('conotes-cards-layout')==='grid'?'grid':'list';

const LAYOUT_ICON={
  // Each button shows what it will switch you *to*.
  list:'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/></svg>',
  grid:'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4" width="17" height="6" rx="2"/><rect x="3.5" y="14" width="17" height="6" rx="2"/></svg>',
};

function syncLayoutButton(){
  const btn=$('btn-layout'); if(!btn)return;
  // Only the Cards tab has cards to lay out.
  btn.classList.toggle('hidden',tab!=='notes'||!board);
  btn.innerHTML=LAYOUT_ICON[cardsLayout];
  btn.setAttribute('aria-label',cardsLayout==='grid'?'Switch to one column':'Switch to two columns');
}

function toggleCardsLayout(){
  cardsLayout=cardsLayout==='grid'?'list':'grid';
  store.set('conotes-cards-layout',cardsLayout);
  syncLayoutButton();
  if(tab==='notes')renderNotes();
}

let toastTimer=null;
function toast(msg,ms){
  const el=$('toast'); el.textContent=msg; el.classList.add('show');
  clearTimeout(toastTimer); toastTimer=setTimeout(()=>el.classList.remove('show'),ms||2400);
}

/* ---------------------------------------------------------------- push ---
 *
 * One notification, one trigger: somebody flagged a card on a board you are on.
 * Everything else the app already shows you live, and a buzz per keystroke on a
 * shared board would be unusable.
 *
 * `state` is what the settings row renders from:
 *   unsupported  - no service worker, no Push API, or the server has no VAPID
 *                  key. The row is not drawn at all.
 *   needs-install- iOS in a Safari tab. The feature is real and the phone can
 *                  do it, but only from the Home Screen, so this shows how to
 *                  get there rather than hiding and looking like nothing.
 *   blocked      - the browser permission is denied. Only the user can undo
 *                  that, in site settings; the app must not nag.
 *   off / on     - subscribed or not on *this* browser.
 */

/* Web Push on iOS needs the app on the Home Screen; a Safari tab cannot get
 * one. Worth saying out loud, or the switch looks broken. Coarse on purpose -
 * it only decides whether to show a sentence. */
function isIosLikely(){
  return /iPad|iPhone|iPod/.test(navigator.userAgent)
      || (navigator.platform==='MacIntel' && navigator.maxTouchPoints>1);
}

/** Running from the Home Screen / as an installed app rather than in a tab. */
function isStandalone(){
  return window.navigator.standalone === true
      || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
}

function pushSupported(){
  return !!push && !!push.key
      && 'serviceWorker' in navigator
      && 'PushManager' in window
      && 'Notification' in window;
}

async function refreshPushState(){
  // Nothing to offer if the server has no keypair.
  if(!push||!push.key||!('serviceWorker' in navigator)){ push.state='unsupported'; return; }

  // Checked before the API sniff, not after. iOS gives a Safari tab no Push
  // API at all, so the sniff alone would report "unsupported" and the row
  // would quietly disappear on exactly the devices that need the explanation.
  if(isIosLikely()&&!isStandalone()){ push.state='needs-install'; return; }

  if(!('PushManager' in window)||!('Notification' in window)){ push.state='unsupported'; return; }
  if(Notification.permission==='denied'){ push.state='blocked'; return; }

  // `serviceWorker.ready` resolves only once a worker is activated *and*
  // controlling the page. On a first visit, and for a moment after every
  // version bump while the new worker installs, that is not yet true - and if
  // registration failed outright it never becomes true and this awaits
  // forever. Left unbounded it froze `state` at its initial 'unsupported',
  // which is the one value that hides the row entirely, so the setting simply
  // was not there and a reload appeared to fix it at random.
  //
  // On timeout the honest answer is 'off', not 'unsupported': we have a server
  // key and the browser has PushManager, so push *is* possible here - the only
  // thing we could not determine is whether this browser is already
  // subscribed. Showing the switch and letting enablePush() report a real
  // error beats hiding the feature and saying nothing.
  let reg=null;
  try{
    reg=await Promise.race([
      navigator.serviceWorker.ready,
      new Promise(resolve=>setTimeout(()=>resolve(null),PUSH_READY_TIMEOUT)),
    ]);
  }catch(noWorker){ push.state='unsupported'; return; }

  if(!reg){ push.state='off'; return; }

  try{
    const sub=await reg.pushManager.getSubscription();
    push.state=sub?'on':'off';
  }catch(noWorker){ push.state='unsupported'; }
}

/* How long to wait for the service worker before deciding without it. */
const PUSH_READY_TIMEOUT=4000;

/**
 * Re-resolve the push state and repaint the Profile tab if it moved.
 *
 * `refreshPushState()` used to run once, at boot, and the tab bar never called
 * it again - so whatever boot raced its way to was the answer for the rest of
 * the session. Opening Profile a minute later, by which time the worker was
 * long since active, still rendered the stale 'unsupported' and drew no row.
 *
 * Guarded against re-entry, and only repaints when the state actually changed,
 * so calling it on every visit to the tab is free.
 */
let pushSyncing=false;
async function syncPushRow(){
  if(pushSyncing)return;
  pushSyncing=true;
  const before=push.state;
  try{ await refreshPushState(); }
  finally{ pushSyncing=false; }
  if(push.state!==before&&tab==='you')renderView();
}

/** The word in the collapsed settings row. */
function pushSummary(){
  if(push.state==='on')return 'On';
  if(push.state==='blocked')return 'Blocked';
  if(push.state==='needs-install')return 'Home Screen';
  return 'Off';
}

/** The VAPID key travels as base64url; subscribe() wants raw bytes. */
function urlBase64ToUint8Array(base64){
  const pad='='.repeat((4-base64.length%4)%4);
  const raw=atob((base64+pad).replace(/-/g,'+').replace(/_/g,'/'));
  const out=new Uint8Array(raw.length);
  for(let i=0;i<raw.length;i++)out[i]=raw.charCodeAt(i);
  return out;
}

async function enablePush(){
  if(!pushSupported())return;

  // Must be called from a user gesture, which is why this hangs off a button
  // and never off page load.
  const permission=await Notification.requestPermission();
  if(permission!=='granted'){
    push.state=permission==='denied'?'blocked':'off';
    renderView();
    if(permission==='denied')toast('Notifications are blocked in your browser settings');
    return;
  }

  try{
    const reg=await navigator.serviceWorker.ready;
    const sub=await reg.pushManager.subscribe({
      userVisibleOnly:true,
      applicationServerKey:urlBase64ToUint8Array(push.key),
    });
    const raw=sub.toJSON();
    await api('POST','/push/subscribe',{
      endpoint:sub.endpoint,
      p256dh:(raw.keys&&raw.keys.p256dh)||'',
      auth:(raw.keys&&raw.keys.auth)||'',
    });
    push.state='on';
    toast('Notifications on for this browser');
  }catch(e){
    push.state='off';
    handleApiError(e,'Could not turn notifications on');
  }
  renderView();
}

async function disablePush(){
  try{
    const reg=await navigator.serviceWorker.ready;
    const sub=await reg.pushManager.getSubscription();
    if(sub){
      await api('POST','/push/unsubscribe',{endpoint:sub.endpoint});
      await sub.unsubscribe();
    }
    push.state='off';
    toast('Notifications off for this browser');
  }catch(e){
    handleApiError(e,'Could not turn notifications off');
  }
  renderView();
}

/* -------------------------------------------------------------- the seam */

/**
 * Every request the app makes. Rejects with an Error carrying:
 *   .status   HTTP status, or 0 when the network itself failed
 *   .code     server error code, or 'offline'
 *   .payload  the full response body (a 409 carries `current`)
 */
async function api(method, path, body) {
  let res;
  try {
    res = await fetch('/api' + path, {
      method,
      credentials: 'same-origin',
      headers: body ? { 'Content-Type': 'application/json' } : undefined,
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (networkError) {
    const e = new Error('You are offline.');
    e.status = 0; e.code = 'offline';
    throw e;
  }

  if (res.status === 204) { markOnline(); return null; }

  let data = null;
  try { data = await res.json(); } catch (parseError) { data = null; }

  if (!res.ok) {
    const info = (data && data.error) || {};
    const e = new Error(info.message || 'Something went wrong.');
    e.status = res.status;
    e.code = info.code || 'error';
    e.payload = data;
    throw e;
  }

  markOnline();
  return data;
}

/**
 * Photo upload. Multipart rather than JSON, so it does not go through api() -
 * but it reports failures in exactly the same shape, so callers cannot tell.
 */
async function upload(path, file){
  const form=new FormData();
  form.append('photo',file);

  let res;
  try{
    res=await fetch('/api'+path,{method:'POST',credentials:'same-origin',body:form});
  }catch(networkError){
    const e=new Error('You are offline.'); e.status=0; e.code='offline'; throw e;
  }

  let data=null;
  try{ data=await res.json(); }catch(parseError){ data=null; }

  if(!res.ok){
    const info=(data&&data.error)||{};
    const e=new Error(info.message||'That photo could not be uploaded.');
    e.status=res.status; e.code=info.code||'error'; e.payload=data;
    throw e;
  }

  markOnline();
  return data;
}

function markOnline(){ if(offline){ offline=false; setLive('','Connected'); } }
function markOffline(){ if(!offline){ offline=true; setLive('offline','Offline — retrying'); } }

/* The four functions CLAUDE.md named, now backed by the API.
 * writeBoard() is deliberately gone: the unit of write is a note, not a board.
 * That is the whole point of the backend. */

async function fetchBoard(slug) {
  const data = await api('GET', '/boards/' + encodeURIComponent(slug));
  return data;
}

async function loadMe() {
  const data = await api('GET', '/session');
  return data;
}

/** Master profile edit. Any subset of { displayName, username, defaultTheme }. */
async function saveMe(patch) {
  const data = await api('PATCH', '/session', patch);
  return data.user;
}

/** Board profile edit. `null` on a field clears the override. */
async function saveBoardProfile(patch) {
  const data = await api('PATCH', '/boards/' + encodeURIComponent(board.slug) + '/profile', patch);
  return data;
}

/**
 * The board's theme. Shared, unlike everything else on this screen: this
 * repaints the app for every other person on the board at their next poll.
 */
async function saveBoardTheme(theme) {
  return api('PATCH', '/boards/' + encodeURIComponent(board.slug) + '/theme', { theme });
}

/**
 * The board's name. Shared like the theme, but owners only - the name is how
 * the board announces itself to everyone else on it. The slug is untouched, so
 * links and invites already sent keep working.
 */
async function saveBoardName(name) {
  return api('PATCH', '/boards/' + encodeURIComponent(board.slug), { name });
}

/* The board calendar. Everything here is wall-clock: 'YYYY-MM-DD' and 'HH:MM'
 * strings, never Date objects over the wire, so two members in different zones
 * never disagree about which day something is on. */
async function fetchCalendar(from, to) {
  return api('GET', '/boards/' + encodeURIComponent(board.slug)
    + '/calendar?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to));
}

async function createEvent(patch) {
  return api('POST', '/boards/' + encodeURIComponent(board.slug) + '/calendar', patch);
}

async function patchEvent(id, patch) {
  return api('PATCH', '/calendar/' + encodeURIComponent(id), patch);
}

async function removeEvent(id) {
  return api('DELETE', '/calendar/' + encodeURIComponent(id));
}

/** Delete a whole board. Owners only; the server enforces that too. */
async function deleteBoard(slug) {
  return api('DELETE', '/boards/' + encodeURIComponent(slug));
}

async function loadBoards() {
  const data = await api('GET', '/boards');
  return data.boards;
}

async function createNote(slug) {
  const data = await api('POST', '/boards/' + encodeURIComponent(slug) + '/notes',
    { title: '', content: '', pinned: false });
  return data;
}

async function patchNote(id, patch) {
  return api('PATCH', '/notes/' + encodeURIComponent(id), patch);
}

async function deleteNote(id) {
  return api('DELETE', '/notes/' + encodeURIComponent(id));
}

/* ------------------------------------------------------------ selections */

function allNotes(){
  // `order` is gone. The board has always sorted by recency; now it says so.
  return Object.values(notes).sort((a,b)=>new Date(b.updatedAt)-new Date(a.updatedAt));
}
function isMine(n){ return !!(me && n.updatedBy && n.updatedBy.id === me.id); }
/**
 * Search runs entirely in the browser, over the cards already loaded for the
 * open board - the API hands the client every live card up front, so a server
 * round trip would be inventing a second source of truth for something already
 * in memory. It also means search keeps working while offline.
 *
 * The markers are stripped before matching so a search for "bold" finds a card
 * containing **bold** rather than missing it on the asterisks.
 */
function matchesQuery(n){
  if(!query)return true;
  const q=query.toLowerCase();
  return String(n.title||'').toLowerCase().includes(q)
      || stripMd(n.content||'').toLowerCase().includes(q);
}

function visible(){
  const all=allNotes().filter(matchesQuery);
  if(filter==='flagged')return all.filter(n=>n.pinned);
  if(filter==='mine')return all.filter(isMine);
  return all;
}

/* -------------------------------------------------------------- calendar */

/* Wall-clock date helpers. Everything is a 'YYYY-MM-DD' string; no Date object
 * is ever sent or stored, because the moment a date becomes a timestamp it
 * acquires a timezone and two people on one board start disagreeing about which
 * day an event is on. Date is used only to do arithmetic on local parts. */

const MONTHS = ['January','February','March','April','May','June',
                'July','August','September','October','November','December'];
const DOW    = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

function ymd(d){
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0')
       + '-' + String(d.getDate()).padStart(2,'0');
}
function today(){ return ymd(new Date()); }
function parseYmd(s){
  const p=String(s||'').split('-');
  return new Date(Number(p[0]), Number(p[1])-1, Number(p[2]));
}
function monthOf(dateStr){ return String(dateStr||'').slice(0,7); }

/** Monday-first grid covering the whole of `month` ('YYYY-MM'), six rows. */
function monthGrid(month){
  const first=parseYmd(month+'-01');
  const lead=(first.getDay()+6)%7;         // JS weeks start Sunday; ours start Monday
  const start=new Date(first.getFullYear(),first.getMonth(),1-lead);
  const cells=[];
  for(let i=0;i<42;i++){
    const d=new Date(start.getFullYear(),start.getMonth(),start.getDate()+i);
    cells.push({ date:ymd(d), inMonth:monthOf(ymd(d))===month });
  }
  return cells;
}

function shiftMonth(month,by){
  const d=parseYmd(month+'-01');
  const n=new Date(d.getFullYear(),d.getMonth()+by,1);
  return monthOf(ymd(n));
}

function prettyDate(dateStr){
  const d=parseYmd(dateStr);
  return DOW[(d.getDay()+6)%7] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()];
}

/** 24h 'HH:MM' to the same clock format the rest of the app shows. */
function prettyTime(t){
  if(!t)return 'All day';
  const p=t.split(':'), h=Number(p[0]), m=p[1];
  const ampm=h<12?'AM':'PM', hh=h%12===0?12:h%12;
  return hh+':'+m+' '+ampm;
}

/** Everything on one day: events first, then cards falling due. */
function itemsOn(dateStr){
  const evs=calendar.events.filter(e=>e.date===dateStr);
  const cds=calendar.cards.filter(c=>c.date===dateStr);
  return { events:evs, cards:cds, total:evs.length+cds.length };
}

async function loadCalendar(){
  if(!board)return;
  const month=calendar.month||monthOf(today());
  // Pull the whole grid, not just the month, so the leading and trailing days
  // of the six-row view are not mysteriously empty.
  const cells=monthGrid(month);
  try{
    const data=await fetchCalendar(cells[0].date,cells[cells.length-1].date);
    calendar.events=data.events||[];
    calendar.cards =data.cards||[];
    calendar.loaded=true;
    if(tab==='calendar')renderCalendar();
  }catch(e){ handleApiError(e,'Could not load the calendar'); }
}

function renderCalendar(){
  if(!calendar.month)calendar.month=monthOf(today());
  if(!calendar.selected)calendar.selected=today();

  if(!calendar.loaded){
    view.innerHTML='<div class="empty"><h3>Loading the calendar…</h3>'
      + '<p>Everyone on this board sees the same one.</p></div>';
    loadCalendar();
    return;
  }

  const month=calendar.month, cells=monthGrid(month), now=today();
  const label=MONTHS[Number(month.slice(5,7))-1]+' '+month.slice(0,4);

  view.innerHTML=`
    <div class="panel cal-panel">
      <div class="cal-head">
        <button class="cal-nav" id="cal-prev" aria-label="Previous month">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <div class="cal-title">${esc(label)}</div>
        <button class="cal-nav" id="cal-next" aria-label="Next month">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>

      <div class="cal-dow">${DOW.map(d=>`<span>${d[0]}</span>`).join('')}</div>

      <div class="cal-grid">
        ${cells.map(c=>{
          const on=itemsOn(c.date);
          const cls=['cal-cell'];
          if(!c.inMonth)cls.push('out');
          if(c.date===now)cls.push('today');
          if(c.date===calendar.selected)cls.push('sel');
          const evDots=on.events.slice(0,3).map(()=>'<i class="dot ev"></i>').join('');
          const cdDots=on.cards.slice(0,3-Math.min(on.events.length,3)).map(()=>'<i class="dot cd"></i>').join('');
          return `<button class="${cls.join(' ')}" data-day="${esc(c.date)}">
            <span class="n">${parseYmd(c.date).getDate()}</span>
            ${on.total?`<span class="dots">${evDots}${cdDots}</span>`:''}
          </button>`;
        }).join('')}
      </div>
    </div>

    <div class="sect-label">${esc(prettyDate(calendar.selected))}${calendar.selected===now?' · today':''}</div>
    <div class="panel">${dayListHTML(calendar.selected)}</div>

    <button class="acc-btn" id="cal-add" style="width:100%;justify-content:center;margin-top:4px">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Add an event</button>
    <div class="note-small">Events belong to the board, like cards. Everyone on
      ${esc(board?board.name:'this board')} sees them, and anyone can add one.</div>`;

  $('cal-prev').onclick=()=>gotoMonth(shiftMonth(month,-1));
  $('cal-next').onclick=()=>gotoMonth(shiftMonth(month, 1));
  $('cal-add').onclick=()=>openEventEditor(null);

  view.querySelectorAll('[data-day]').forEach(b=>b.onclick=()=>{
    calendar.selected=b.dataset.day;
    // A day outside this month means the user is reaching into the next one;
    // follow them there rather than selecting a cell they cannot see.
    if(monthOf(b.dataset.day)!==calendar.month){ gotoMonth(monthOf(b.dataset.day)); return; }
    renderCalendar();
  });

  view.querySelectorAll('[data-event]').forEach(b=>b.onclick=()=>{
    const ev=calendar.events.find(e=>e.id===b.dataset.event);
    if(ev)openEventEditor(ev);
  });
  view.querySelectorAll('[data-card]').forEach(b=>b.onclick=()=>{
    const id=b.dataset.card;
    if(notes[id]){ tab='notes'; syncTabs(); renderChips(); renderView(); openCard(id); }
    else toast('That card is not on this board any more');
  });
}

function dayListHTML(dateStr){
  const on=itemsOn(dateStr);
  if(!on.total){
    // .note-small carries a margin-top for when it sits under something; on
    // its own in a panel that margin is what pushes it off centre.
    return '<div class="note-small day-empty">Nothing on this day yet.</div>';
  }

  const chev='<span class="row-chev"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5l7 7-7 7"/></svg></span>';

  const events=on.events.map(e=>`
    <button class="row flat" data-event="${esc(e.id)}">
      <div class="cal-when${e.allDay?' all':''}">${esc(e.allDay?'All day':prettyTime(e.time))}</div>
      <div class="row-body">
        <div class="row-title"><span>${esc(e.title)}</span></div>
        ${e.detail?`<div class="row-meta">${esc(e.detail.replace(/\s+/g,' ').trim().slice(0,80))}</div>`:''}
      </div>${chev}
    </button>`).join('');

  const cards=on.cards.map(c=>`
    <button class="row flat" data-card="${esc(c.id)}">
      <div class="cal-when card"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H17l3 3v12.5A2.5 2.5 0 0 1 17.5 21h-11A2.5 2.5 0 0 1 4 18.5z"/><path d="M8 12h8M8 16h5"/></svg></div>
      <div class="row-body">
        <div class="row-title">${c.pinned?'<span class="pin"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h13l-2.5 4.5L18 12H6"/><path d="M6 3v18"/></svg></span>':''}<span>${esc(c.title&&c.title.trim()?c.title:'Untitled card')}</span></div>
        <div class="row-meta">Card due this day</div>
      </div>${chev}
    </button>`).join('');

  return events+cards;
}

function gotoMonth(month){
  calendar.month=month;
  calendar.loaded=false;
  renderCalendar();
}

/* -------------------------------------------------- calendar: the editor */

function openEventEditor(ev){
  const card=$('modal-card');
  card.className='modal-card tall';
  const isNew=!ev;

  card.innerHTML=`
    <h1>${isNew?'New <span class="soft">event</span>':'Edit <span class="soft">event</span>'}</h1>
    <p>Everyone on ${esc(board?board.name:'this board')} sees this.</p>

    <label class="field-label" for="ev-title">Title</label>
    <input class="field" id="ev-title" maxlength="120" placeholder="Site visit"
           value="${esc(ev?ev.title:'')}" autocomplete="off">

    <label class="field-label" for="ev-date">Date</label>
    <input class="field" id="ev-date" type="date" value="${esc(ev?ev.date:calendar.selected)}">

    <label class="field-label" for="ev-time">Time</label>
    <input class="field" id="ev-time" type="time" value="${esc(ev&&ev.time?ev.time:'')}">
    <div class="field-note">Leave the time empty for an all-day entry. Times are
      the board's clock, so everyone sees the same one wherever they are.</div>

    <label class="field-label" for="ev-detail">Detail</label>
    <input class="field" id="ev-detail" maxlength="200" placeholder="Optional"
           value="${esc(ev?ev.detail:'')}" autocomplete="off">

    <button class="acc-btn" id="ev-save" style="width:100%;justify-content:center;margin-top:14px">
      ${isNew?'Add to the calendar':'Save'}</button>
    ${isNew?'':'<button class="ghost-btn" id="ev-del" style="margin-top:10px">Delete this event</button>'}
    <button class="mini-btn" id="ev-cancel" style="width:100%;margin-top:10px">Cancel</button>`;

  $('modal').classList.remove('hidden');
  setTimeout(()=>{const t=$('ev-title'); if(t)t.focus();},80);

  $('ev-cancel').onclick=closeModal;

  $('ev-save').onclick=async()=>{
    const patch={
      title:  $('ev-title').value.trim(),
      date:   $('ev-date').value,
      time:   $('ev-time').value||null,
      detail: $('ev-detail').value.trim(),
    };
    if(!patch.title){ toast('An event needs a title'); return; }
    if(!patch.date){  toast('An event needs a date');  return; }

    try{
      if(isNew){
        const data=await createEvent(patch);
        cursor=Math.max(cursor,data.cursor||0);
      }else{
        patch.version=ev.version;
        const data=await patchEvent(ev.id,patch);
        cursor=Math.max(cursor,data.cursor||0);
      }
      closeModal();
      calendar.selected=patch.date;
      calendar.month=monthOf(patch.date);
      calendar.loaded=false;
      activity.loaded=false;
      renderCalendar();
      toast(isNew?'Added to the calendar':'Event saved');
    }catch(e){
      // An event is four words - there is nothing worth merging, so a clash
      // reloads and says so rather than opening the conflict sheet a card gets.
      if(e.status===409){
        closeModal();
        calendar.loaded=false; renderCalendar();
        toast('Someone else changed that event - reloaded');
        return;
      }
      handleApiError(e,'Could not save the event');
    }
  };

  const del=$('ev-del');
  if(del)del.onclick=async()=>{
    try{
      const data=await removeEvent(ev.id);
      cursor=Math.max(cursor,(data&&data.cursor)||0);
      closeModal();
      calendar.loaded=false; activity.loaded=false;
      renderCalendar();
      toast('Event deleted');
    }catch(e){ handleApiError(e,'Could not delete the event'); }
  };
}

/* ------------------------------------------------------------ rich text */

/* A deliberately small markdown subset, held as plain text in `content`:
 *
 *   **bold**    __underline__    *italic* / _italic_    - [ ] / - [x] task
 *
 * Text, not HTML, on purpose. The board is shared, so storing HTML would mean
 * sanitising whatever one member types before showing it to everyone else - an
 * XSS surface this does not need. Plain text also means the server stays
 * exactly as it is: Validate::clean() only strips control characters, so every
 * marker below survives the round trip untouched.
 *
 * Everything is escaped BEFORE the markers are applied, so the only tags in the
 * output are the ones written here.
 */

const TASK_RE = /^(\s*)- \[([ xX])\]\s?([\s\S]*)$/;

/* A heading is one level, not six. A card is a short shared note - `# ` is
   enough to give it a title line, and a hierarchy inside something this
   small would be a thing to maintain rather than a thing to use. */
const HEAD_RE = /^#\s+([\s\S]*)$/;

/* A bullet is `- `, and a task is `- [ ] ` - so the bullet pattern has to
   refuse a line that is really a task, whatever order they are tested in.
   That negative lookahead is the whole of it; without it every checklist
   item would also read as a bullet whose text happened to start `[ ]`. */
const BULLET_RE = /^(\s*)- (?!\[[ xX]\])([\s\S]*)$/;

/** Inline markers, applied to already-escaped text. Order matters: ** before *. */
function inlineMd(t){
  return t
    .replace(/\*\*(?=\S)([\s\S]*?\S)\*\*/g, '<strong>$1</strong>')
    .replace(/__(?=\S)([\s\S]*?\S)__/g,     '<u>$1</u>')
    .replace(/(^|[^*\w])\*(?=\S)([^*\n]*?\S)\*/g, '$1<em>$2</em>')
    .replace(/(^|[^_\w])_(?=\S)([^_\n]*?\S)_/g,   '$1<em>$2</em>');
}

const BOX_TICK = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

/**
 * Render `content` for reading. Each task line carries its source line number,
 * so ticking one rewrites exactly that line and leaves the rest alone.
 */
function renderRich(text){
  const lines = String(text||'').split('\n');
  let out='', para=[];
  const flush=()=>{ if(para.length){ out+='<p>'+para.join('<br>')+'</p>'; para=[]; } };

  lines.forEach((line,i)=>{
    const h=line.match(HEAD_RE);
    if(h){
      flush();
      out+='<h3 class="rich-h">'+inlineMd(esc(h[1]))+'</h3>';
      return;
    }
    const bl=line.match(BULLET_RE);
    if(bl){
      flush();
      out+='<div class="bullet"><i class="dot"></i>'
         + '<span>'+inlineMd(esc(bl[2]))+'</span></div>';
      return;
    }
    const m=line.match(TASK_RE);
    if(m){
      flush();
      const done=m[2]!==' ';
      out+='<div class="task'+(done?' done':'')+'">'
         + '<button class="box" data-line="'+i+'" role="checkbox" aria-checked="'+done+'" '
         + 'aria-label="'+(done?'Done':'Not done')+'">'+(done?BOX_TICK:'')+'</button>'
         + '<span>'+inlineMd(esc(m[3]))+'</span></div>';
    }else if(line.trim()===''){
      flush();
    }else{
      para.push(inlineMd(esc(line)));
    }
  });

  flush();
  return out;
}

/** Strip the markers, for places that show a plain snippet like the board card. */
function stripMd(text){
  return String(text||'').split('\n').map(function(l){
    const h=l.match(HEAD_RE);
    if(h)return h[1];
    const bl=l.match(BULLET_RE);
    if(bl)return '\u2022 ' + bl[2];
    const m=l.match(TASK_RE);
    return m ? (m[2]===' ' ? '\u00b7 ' : '\u2713 ') + m[3] : l;
  }).join(' ')
    .replace(/\*\*([\s\S]*?)\*\*/g,'$1')
    .replace(/__([\s\S]*?)__/g,'$1')
    .replace(/\*([^*\n]*?)\*/g,'$1')
    .replace(/_([^_\n]*?)_/g,'$1');
}

/* --------------------------------------------------------------- chrome  */

function renderHead(){
  if(!me)return;
  // The header shows who you are *here* - your board name and photo if you
  // set them, your master profile if you did not.
  const here=meAsPerson();
  $('me-avatar').innerHTML=avatarHTML(here,46);
  $('me-name').textContent=here.name;
  $('me-role-text').textContent=board?board.name:'shared board';
  $('greet').classList.add('hidden');
  renderSideBoards();
}

/**
 * The boards list in the desktop rail.
 *
 * Same list as openBoardSwitcher()'s modal and the same switchBoard() behind
 * it - it is only that on a desktop there is room to leave it on screen, so
 * changing board is one click rather than open-modal-choose-close. The rail
 * itself is display:none below 1024px, so on a phone this writes into a box
 * nobody can see; that is cheaper than teaching the client about widths.
 *
 * No card or member counts here, deliberately. `boards` is only refreshed when
 * something reloads it, so a count next to the open board would sit there
 * being wrong every time you added a card.
 */
function renderSideBoards(){
  const host=$('side-boards'); if(!host)return;
  if(!boards.length){host.innerHTML='';return;}

  host.innerHTML=`<div class="side-label">Boards</div>
    <div class="side-list">
      ${boards.map(b=>`
        <button class="side-board${board&&b.slug===board.slug?' on':''}" data-board="${esc(b.slug)}">
          <i class="bullet"></i><span class="nm">${esc(b.name)}</span>
        </button>`).join('')}
      <button class="side-board new" id="side-new-board">
        <i class="bullet"></i><span class="nm">New board</span>
      </button>
    </div>`;

  host.querySelectorAll('[data-board]').forEach(el=>{
    el.onclick=()=>switchBoard(el.dataset.board);
  });
  $('side-new-board').onclick=promptNewBoard;
}

function renderChips(){
  renderSearch();
  syncLayoutButton();
  chipsEl.classList.add('hidden');
}

function renderSearch(){
  const row=$('search'); if(!row)return;
  row.classList.toggle('hidden',tab!=='notes'||!board);
  // One glyph at the trailing edge, never two: the magnifier while the field
  // is empty, the clear button once there is something to clear.
  const clear=$('q-clear'), ico=$('q-ico');
  if(clear)clear.classList.toggle('hidden',query==='');
  if(ico)ico.classList.toggle('hidden',query!=='');
}

function setQuery(v){
  query=String(v||'');
  const box=$('q');
  if(box&&box.value!==query)box.value=query;
  renderChips();
  if(tab==='notes')renderNotes();
}

/* ---------------------------------------------------------------- cards  */

function renderNotes(){
  const list=visible();
  if(!list.length){
    // Three different empty states: nothing searched and nothing on the board,
    // a filter that excludes everything, and a search that found nothing. They
    // want different words and different buttons.
    if(query){
      view.innerHTML=`<div class="empty">
        <h3>No cards match that search</h3>
        <p>Nothing on this board contains &ldquo;${esc(query)}&rdquo;. Search covers the
          titles and text of the cards on this board only - not the trash, and not your other boards.</p>
        <button class="acc-btn" id="empty-clear">Clear the search</button></div>`;
      const c=$('empty-clear'); if(c)c.onclick=()=>setQuery('');
      return;
    }
    view.innerHTML=`<div class="empty">
      <h3>${filter==='all'?'Nothing on the board yet':'No cards match this filter'}</h3>
      <p>${filter==='all'?'Add the first card. Everyone on this board sees it appear within a second.':'Switch back to All, or add a new card.'}</p>
      <button class="acc-btn" id="empty-new">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        New card</button></div>`;
    const b=$('empty-new'); if(b)b.onclick=newCard;
    return;
  }
  view.innerHTML=`<div class="cards ${cardsLayout==='grid'?'grid':'list'}">
    ${list.map(cardHTML).join('')}
  </div>`;
  view.querySelectorAll('[data-open]').forEach(el=>{
    el.onclick=()=>openCard(el.dataset.open);
    el.onkeydown=e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); openCard(el.dataset.open); } };
  });

  // A card whose text does not fit gets a fade at the cut, so a clipped card
  // reads as "there is more" rather than as a line sliced in half.
  view.querySelectorAll('.note-body').forEach(b=>{
    b.classList.toggle('clipped',b.scrollHeight>b.clientHeight+2);
  });
}

/**
 * One card on the board. The same card in both layouts - only the column count
 * changes - so a card looks the same wherever it lands.
 *
 * The body is the real rendered card: markers become bold and italic, task
 * lines become boxes. The boxes are inert here (pointer-events in the
 * stylesheet); ticking one is something you do inside the card, where the save
 * and its 409 live.
 */
function cardHTML(n){
  const title=n.title&&n.title.trim()?n.title:'';
  const body=renderRich(n.content);
  return `
    <article class="note-card${n.pinned?' flagged':''}" data-open="${esc(n.id)}" tabindex="0" role="button">
      ${n.pinned?`<span class="note-flag"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h13l-2.5 4.5L18 12H6"/><path d="M6 3v18"/></svg></span>`:''}
      ${title?`<h3 class="note-title">${esc(title)}</h3>`:''}
      ${body?`<div class="note-body">${body}</div>`
            :(title?'':`<div class="note-body empty-note">Empty card</div>`)}
      ${n.dueDate?`<span class="note-due">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="3.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
        ${esc(prettyDate(n.dueDate))}</span>`:''}
    </article>`;
}

/* -------------------------------------------------------------- activity */

/* A card's action verbs; an event's are separate because "created" on a card
 * reads as "added" but on an event as "added the event" - same key, different
 * sentence. */
const ACTION_TEXT = {
  created:   'added',
  edited:    'edited',
  flagged:   'flagged',
  unflagged: 'unflagged',
  deleted:   'deleted',
  restored:  'restored',
  // Written by Notes::flagDue() with no user behind it - the date did this,
  // so it is rendered as a sentence about the card, not about a person.
  due:       'came due',
};
const EVENT_ACTION_TEXT = {
  created: 'added the event',
  updated: 'updated the event',
  deleted: 'deleted the event',
};
const CAL_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="3.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>';

function renderActivity(){
  if(!activity.loaded){
    view.innerHTML=`<div class="empty"><h3>Loading activity…</h3><p>Pulling the edit history for this board.</p></div>`;
    loadActivity();
    return;
  }
  if(!activity.items.length){
    view.innerHTML=`<div class="empty"><h3>No activity yet</h3><p>Edits and calendar changes show up here as people work on the board.</p></div>`;
    return;
  }
  view.innerHTML=`<div class="panel">${activity.items.map(a=>{
    if(a.kind==='event'){
      return `<div class="act">
        <div class="act-icon">${CAL_ICON}</div>
        <div class="act-text"><b>${esc(a.user.name)}</b> ${esc(EVENT_ACTION_TEXT[a.action]||'changed the event')} <i>${esc(a.title)}</i>
          <div class="act-time">${esc(ago(a.at))}</div></div>
      </div>`;
    }
    const title=a.title&&a.title.trim()?a.title:'an untitled card';

    // A row with nobody behind it is the board noticing something, not a
    // person doing something: "Kitchen rebuild came due", no name, no face.
    if(!a.user||!a.user.id){
      return `<div class="act">
        <div class="act-icon">${CAL_ICON}</div>
        <div class="act-text"><b>${esc(title)}</b> ${esc(ACTION_TEXT[a.action]||'changed')}
          <div class="act-time">${esc(ago(a.at))}</div></div>
      </div>`;
    }

    return `<div class="act">
      ${avatarHTML(a.user,38)}
      <div class="act-text"><b>${esc(a.user.name)}</b> ${esc(ACTION_TEXT[a.action]||'touched')} ${esc(title)}
        <div class="act-time">${esc(ago(a.at))}</div></div>
    </div>`;}).join('')}</div>
    ${activity.nextCursor?`<button class="acc-btn" id="act-more" style="width:100%;justify-content:center">Load older</button>`:''}`;
  const more=$('act-more');
  if(more)more.onclick=()=>loadActivity(activity.nextCursor);
}

async function loadActivity(before){
  if(!board)return;
  try{
    // `before` is an opaque cursor - some JSON the server handed back last
    // time. The client never reads its shape, only round-trips it.
    const q=before?('?before='+encodeURIComponent(JSON.stringify(before))):'';
    const data=await api('GET','/boards/'+encodeURIComponent(board.slug)+'/activity'+q);
    activity.items  = before ? activity.items.concat(data.items) : data.items;
    activity.nextCursor = data.nextCursor;
    activity.loaded = true;
    if(tab==='activity')renderActivity();
  }catch(e){ handleApiError(e); }
}

/* -------------------------------------------------------------- profile  */

/**
 * The Profile tab.
 *
 * Two profiles sit here, and the distinction is the whole point:
 *
 *   - the master profile is who you are on Co notes - handle, email, name, photo
 *   - the board profile is who you are *on this board*, and each field is
 *     either your own or inherited from the master one
 *
 * Everything that belongs to the *board* rather than to you - who you are on
 * it, its theme, its name - is folded into one "Board settings" disclosure, so
 * the tab opens as a short list rather than five stacked panels. Appearance
 * stays outside it deliberately: light and dark is yours and device-local, not
 * the board's, and putting it in there would say otherwise.
 *
 * Every section on the tab folds, so it opens as a short list of rows rather
 * than a wall of panels. The open state lives out here because renderYou()
 * runs on every poll that touches the board - if it lived in the DOM alone,
 * a panel would snap shut under someone mid-edit.
 *
 * Switching boards is not on this tab: it is the board name in the header,
 * which is where you already look to see which board you are on.
 */
const panelOpen={profile:false,board:false,appearance:false,notifications:false,members:false,trash:false};

/* One glyph per row. A settings list reads as a list rather than as five
 * unrelated headings when each line has something to aim at. */
const PANEL_ICON={
  profile:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>',
  board:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14M5 12h14M5 17h14"/><circle cx="9" cy="7" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="17" r="2"/></svg>',
  // Filled in per render - the glyph follows the mode it controls.
  appearance:'',
  members:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M2.8 19a6.2 6.2 0 0 1 12.4 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.6M17.5 13.4A6.2 6.2 0 0 1 21.2 19"/></svg>',
  trash:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13"/></svg>',
  notifications:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6"/><path d="M10.5 20a1.8 1.8 0 0 0 3 0"/></svg>',
};

const SUN='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6L17 7M7 17l-1.4 1.4"/></svg>';
const MOON='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/></svg>';

/**
 * One row of the settings list: an icon, a label, what it currently says, and
 * a chevron. All five live in a single panel, divided by hairlines, so the tab
 * reads as one list instead of five floating cards.
 */
function foldHTML(key,label,summary,body){
  const open=!!panelOpen[key];
  return `
    <div class="fold">
      <button class="disclosure" data-panel="${esc(key)}" aria-expanded="${open?'true':'false'}" aria-controls="panel-${esc(key)}">
        <span class="disclosure-icon">${key==='appearance'?(mode==='light'?SUN:MOON):(PANEL_ICON[key]||'')}</span>
        <div class="fold-label">${esc(label)}</div>
        <div class="fold-value">${esc(summary||'')}</div>
        <span class="disclosure-chev"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
      </button>
      <div class="disclosure-body${open?'':' hidden'}" id="panel-${esc(key)}">${body}</div>
    </div>`;
}


function renderYou(){
  const owner = board && board.role === 'owner';
  const eff   = profile ? profile.effective : null;
  const ov    = profile ? profile.overrides : null;
  const cards = Object.keys(notes).length;

  view.innerHTML=`
    <div class="hero">
      <div class="hero-halo"></div>
      <div class="hero-face">${avatarHTML({name:me.displayName,color:me.color,avatar:me.avatar},78)}</div>
      <div class="hero-name">${esc(me.displayName)}</div>
      ${me.username?`<div class="hero-handle">@${esc(me.username)}</div>`:''}
      ${board?`<div class="hero-sub">${owner?'owner of':'on'} ${esc(board.name)}</div>`:''}

      <div class="stats">
        <div class="stat"><b>${boards.length}</b><span>board${boards.length===1?'':'s'}</span></div>
        <div class="stat"><b>${cards}</b><span>card${cards===1?'':'s'} here</span></div>
        <div class="stat"><b>${members.length}</b><span>member${members.length===1?'':'s'}</span></div>
      </div>
    </div>

    <div class="panel settings">
    ${foldHTML('profile','Your profile',me.email||'signed in',`
      <div class="fact"><span>Name</span><b>${esc(me.displayName)}</b></div>
      ${me.username?`<div class="fact"><span>Username</span><b>@${esc(me.username)}</b></div>`:''}
      <div class="fact"><span>Email</span><b>${esc(me.email||'not shared')}</b></div>
      <div class="fact"><span>Signed in with</span><b>${esc(me.provider||'OAuth')}</b></div>

      <button class="acc-btn" id="edit-master" style="width:100%;justify-content:center;margin-top:14px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/></svg>
        Edit profile</button>
      <div class="note-small">Your name and photo here are used on every board, unless you set something different for a board below.</div>`)}

    ${board?foldHTML('board','Board settings',board.name,`
        ${profile?`
        <div class="setting">
          <div class="sect-label" style="margin:0 0 12px">You on this board</div>
          <div class="you-head">${avatarHTML({name:eff.name,color:eff.color,avatar:eff.avatar},58)}
            <div style="min-width:0">
              <div class="you-name">${esc(eff.name)}</div>
              <div class="you-sub">${ov.displayName||ov.hasPhoto
                ? 'Just for this board'
                : 'Same as your profile'}</div>
            </div></div>

          <button class="acc-btn" id="edit-board" style="width:100%;justify-content:center">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/></svg>
            Edit board profile</button>
          <div class="note-small">This is the name and photo the other people on ${esc(board.name)} see on your cards.</div>
        </div>`:''}

        ${owner?`
        <div class="setting">
          <div class="sect-label" style="margin:0 0 12px">Name</div>
          <div class="fact"><span>Called</span><b>${esc(board.name)}</b></div>
          <div class="fact"><span>Address</span><b>/b/${esc(board.slug)}</b></div>
          <button class="acc-btn" id="rename-board" style="width:100%;justify-content:center;margin-top:14px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/></svg>
            Rename this board</button>
          <div class="note-small">Everyone on the board sees the new name within a second. The address
            stays as it is, so links and invites already sent keep working. Only the owner can change it.</div>
        </div>`:''}

        <div class="setting">
          <div class="swatch-row">
            <div class="sect-label">Theme</div>
            <div class="swatch-name" id="theme-name">${esc(themeLabel(board.theme))}</div>
          </div>
          <div class="swatches" id="theme-picker">${swatchesHTML(board.theme)}</div>
          <div class="note-small">Everyone on ${esc(board.name)} sees this theme, and anyone on the
            board can change it. Your other boards keep their own.</div>
        </div>`):''}

    ${foldHTML('appearance','Appearance',MODES[mode],`
      <div class="conflict-actions" id="mode-picker">
        ${Object.keys(MODES).map(id=>
          `<button class="mini-btn${id===mode?' acc':''}" data-mode="${esc(id)}">${esc(MODES[id])}</button>`).join('')}
      </div>
      <div class="note-small">Yours alone, and only on this device — the board's colour is
        shared, the ground it sits on is not. Nobody else on ${board?esc(board.name):'the board'} sees this change.</div>`)}

    ${push.state==='unsupported'?'':foldHTML('notifications','Notifications',pushSummary(),`
      ${push.state==='blocked'
        ? `<div class="note-small">This browser is blocking notifications for
             ${esc(location.host)}. Only you can undo that, in the site settings
             your browser keeps next to the address bar.</div>`
        : push.state==='needs-install'
        ? `<div class="ios-steps">
             <div class="ios-step"><span class="ios-step-n">1</span>
               <span>Tap <b>Share</b> at the bottom of Safari
                 <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>
               </span></div>
             <div class="ios-step"><span class="ios-step-n">2</span>
               <span>Choose <b>Add to Home Screen</b></span></div>
             <div class="ios-step"><span class="ios-step-n">3</span>
               <span>Open Co notes from the new icon, then come back here</span></div>
           </div>
           <div class="note-small">Apple only lets an installed web app receive
             notifications - a Safari tab cannot, whatever it is asked. Everything else
             in Co notes works either way; this is the one thing that needs the icon.</div>`
        : `<button class="acc-btn" id="push-toggle" style="width:100%;justify-content:center">
             ${push.state==='on'?'Turn off on this browser':'Turn on for this browser'}
           </button>
           <div class="note-small">You get one when somebody <b>flags a card</b> on a board
             you are on - nothing else. Flagging is the only thing in here that means
             &ldquo;look at this&rdquo;; everything else the app already shows you live.
             Like Appearance this is per browser, so turning it on here does not turn it
             on anywhere else you are signed in.</div>
           ${isIosLikely()&&isStandalone()?`<div class="note-small">Installed from the Home
             Screen, so this iPhone can receive them. Leaving it in Safari cannot.</div>`:''}`}`)}

    ${foldHTML('members','On this board',members.length+(members.length===1?' person':' people'),`
      ${members.map(m=>`<div class="act">${avatarHTML(m,38)}<div class="act-text" style="color:var(--ink);font-weight:600">${esc(m.name)}${m.id===me.id?' <span style="color:var(--ink-3);font-weight:500">(you)</span>':''}${m.role==='owner'?' <span style="color:var(--ink-3);font-weight:500">· owner</span>':''}</div></div>`).join('')}
      ${owner?`<button class="acc-btn" id="invite" style="width:100%;justify-content:center;margin-top:14px">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Invite someone</button>
      <div class="note-small">Creates a link that adds whoever opens it to this board. It lasts seven days.</div>`
      :`<div class="note-small">Only the board owner can invite new people.</div>`}`)}

    ${foldHTML('trash','Recently deleted',trashSummary(),`
      <div id="trash-list"><div class="note-small">Loading…</div></div>`)}
    </div>

    <div class="panel">
      <div class="sect-label" style="margin:0 0 12px">Careful with these</div>
      <button class="ghost-btn" id="leave">Leave this board</button>
      ${owner?`<button class="ghost-btn" id="reset" style="margin-top:10px">Reset the whole board</button>
      <div class="note-small">Moves every card on this board to Recently deleted. Anyone can restore them for 30 days.</div>
      <button class="ghost-btn danger" id="del-board" style="margin-top:10px">Delete this board</button>
      <div class="note-small">Removes the board for everyone on it, with its cards, calendar and history. This one is not undoable from the app.</div>`:''}
    </div>

    <button class="sign-out" id="signout">Sign out</button>`;

  const master=$('edit-master'); if(master)master.onclick=openMasterEditor;

  // Folding is done in place rather than through a re-render: these panels hold
  // a theme picker, a live board name and a trash list, and redrawing all of
  // that to move a chevron would be silly.
  view.querySelectorAll('.disclosure[data-panel]').forEach(btn=>btn.onclick=()=>{
    const key=btn.dataset.panel, body=$('panel-'+key);
    if(!body)return;
    panelOpen[key]=!panelOpen[key];
    body.classList.toggle('hidden',!panelOpen[key]);
    btn.setAttribute('aria-expanded',panelOpen[key]?'true':'false');
    // The trash is the one panel with a request behind it, so it waits until
    // someone actually opens it.
    if(key==='trash'&&panelOpen[key])renderTrash();
  });

  const boardEdit=$('edit-board');
  if(boardEdit)boardEdit.onclick=openBoardProfileEditor;

  bindSwatches($('theme-picker'),async id=>{
    // Paint first, persist second: a theme change should feel instant, and a
    // failed save just puts the old one back. Everyone else on the board picks
    // it up from the delta feed on their next poll.
    const previous=board.theme;
    board.theme=id;
    applyTheme(id);
    const label=$('theme-name'); if(label)label.textContent=themeLabel(id);

    try{
      const data=await saveBoardTheme(id);
      board.theme=data.theme||id;
      cursor=Math.max(cursor,data.cursor||0);
      syncTheme();
    }catch(e){
      board.theme=previous;
      applyTheme(previous);
      handleApiError(e,'Could not save the theme');
      renderView();
    }
  });

  // Light and dark need no save and no network - the attribute is the change.
  const modePicker=$('mode-picker');
  if(modePicker)modePicker.querySelectorAll('[data-mode]').forEach(b=>b.onclick=()=>{
    applyMode(b.dataset.mode);
    renderView();
  });

  const pushBtn=$('push-toggle');
  if(pushBtn)pushBtn.onclick=()=>{ push.state==='on'?disablePush():enablePush(); };

  $('leave').onclick=leaveBoard;
  $('signout').onclick=signOut;

  const inviteBtn=$('invite'); if(inviteBtn)inviteBtn.onclick=makeInvite;
  const resetBtn=$('reset');   if(resetBtn)resetBtn.onclick=resetBoard;
  const delBtn=$('del-board');  if(delBtn)delBtn.onclick=confirmDeleteBoard;
  const renameBtn=$('rename-board'); if(renameBtn)renameBtn.onclick=promptRenameBoard;

  if(panelOpen.trash)renderTrash();
}

/* --------------------------------------------------- profile: components */

function themeLabel(id){
  const found=themes.find(t=>t.id===id);
  return found?found.label:(id?id[0].toUpperCase()+id.slice(1):'Lime');
}

/** One disc per theme, painted in that theme's own accent. */
function swatchesHTML(selected){
  const list=themes.length?themes:Object.keys(THEME_SWATCH).map(id=>({id,label:themeLabel(id)}));
  return list.map(t=>`
    <button class="swatch${t.id===selected?' on':''}" data-theme="${esc(t.id)}"
            style="background:${esc(swatchPaint(t.id))}"
            aria-label="${esc(t.label)}" title="${esc(t.label)}">
      <svg class="tick" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
    </button>`).join('');
}

function bindSwatches(host,onPick){
  if(!host)return;
  host.querySelectorAll('[data-theme]').forEach(b=>b.onclick=()=>{
    host.querySelectorAll('[data-theme]').forEach(x=>x.classList.toggle('on',x===b));
    onPick(b.dataset.theme);
  });
}

/**
 * The photo block shared by both editors: the avatar as it stands, a file
 * picker, and a way to take the photo off again.
 */
function photoEditorHTML(person,hasOwnPhoto,removeLabel){
  return `
    <div class="photo-edit">
      ${avatarHTML(person,72)}
      <div class="photo-actions">
        <label class="file-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
          ${hasOwnPhoto?'Change photo':'Add photo'}
          <input type="file" id="photo-input" accept="image/jpeg,image/png,image/gif,image/webp">
        </label>
        ${hasOwnPhoto?`<button class="mini-btn" id="photo-remove">${esc(removeLabel)}</button>`:''}
      </div>
    </div>`;
}

/**
 * Wire the file picker. Uploading replaces the photo immediately - there is no
 * separate save step for it, because a half-applied photo is confusing.
 */
function bindPhoto(uploadPath, onDone){
  const input=$('photo-input');
  if(!input)return;

  input.onchange=async()=>{
    const file=input.files&&input.files[0];
    if(!file)return;

    if(file.size>3*1024*1024){
      toast('Photos must be 3MB or smaller');
      input.value='';
      return;
    }

    setLive('saving','Uploading photo…');
    try{
      const data=await upload(uploadPath,file);
      setLive('','Saved');
      await onDone(data);
    }catch(e){
      handleApiError(e,'Could not upload the photo');
    }finally{
      input.value='';
    }
  };
}

/* ------------------------------------------------- profile: master editor */

function openMasterEditor(){
  const card=$('modal-card');
  card.className='modal-card tall';

  card.innerHTML=`
    <h1>Your <span class="soft">profile</span></h1>
    <p>This is who you are across Co notes. Boards can override the name and photo individually.</p>

    ${photoEditorHTML({name:me.displayName,color:me.color,avatar:me.avatar},me.hasPhoto,'Remove photo')}

    <label class="field-label" for="m-name">Display name</label>
    <input class="field" id="m-name" value="${esc(me.displayName)}" maxlength="28" placeholder="Your name" autocomplete="off">

    <label class="field-label" for="m-username">Username</label>
    <input class="field" id="m-username" value="${esc(me.username||'')}" maxlength="32" placeholder="username" autocomplete="off" spellcheck="false">
    <div class="field-note">Letters, numbers, dots and underscores. This is how people tell two identical names apart.</div>

    <label class="field-label" for="m-email">Email</label>
    <input class="field" id="m-email" value="${esc(me.email||'not shared by your provider')}" disabled>
    <div class="field-note">Comes from ${esc(me.provider||'your provider')} and cannot be edited here.</div>

    <div class="swatch-row">
      <div class="sect-label">Default theme</div>
      <div class="swatch-name" id="m-theme-name">${esc(themeLabel(me.defaultTheme))}</div>
    </div>
    <div class="swatches" id="m-theme">${swatchesHTML(me.defaultTheme)}</div>
    <div class="field-note">The theme a board starts in when you create one. Boards you are already on have their own, shared with everyone on them.</div>

    <button class="acc-btn" id="m-save" style="width:100%;justify-content:center;margin-top:6px">Save profile</button>
    <button class="mini-btn" id="m-cancel" style="width:100%;margin-top:10px">Cancel</button>`;

  $('modal').classList.remove('hidden');

  let pickedTheme = me.defaultTheme;

  bindSwatches($('m-theme'),id=>{
    // No preview any more: what is on screen is the open board's theme, and
    // this choice only decides what a board created later starts in.
    pickedTheme=id;
    $('m-theme-name').textContent=themeLabel(id);
  });

  // A master photo is inherited by every board that has not set its own, so the
  // board profile has to be re-read before anything is redrawn - otherwise the
  // screen shows the new photo up top and the old initials below it.
  bindPhoto('/session/photo',async data=>{
    me=data.user;
    await refreshBoardProfile();
    closeModal(); renderHead(); renderView();
    toast('Photo updated');
  });

  const removeBtn=$('photo-remove');
  if(removeBtn)removeBtn.onclick=async()=>{
    try{
      const data=await api('DELETE','/session/photo');
      me=data.user;
      await refreshBoardProfile();
      closeModal(); renderHead(); renderView();
      toast('Photo removed');
    }catch(e){ handleApiError(e,'Could not remove the photo'); }
  };

  $('m-cancel').onclick=()=>{ syncTheme(); closeModal(); };

  $('m-save').onclick=async()=>{
    const patch={
      displayName: $('m-name').value.trim(),
      defaultTheme: pickedTheme,
    };

    const handle=$('m-username').value.trim();
    if(handle!==(me.username||''))patch.username=handle;

    if(!patch.displayName){ toast('Your name cannot be empty'); return; }

    try{
      me=await saveMe(patch);
      closeModal();
      toast('Profile saved');
      await refreshBoardProfile();
      syncTheme(); renderHead(); renderView();
    }catch(e){
      // A taken username is the one failure worth keeping the form open for.
      if(e.status===409){ toast(e.message); return; }
      handleApiError(e,'Could not save your profile');
    }
  };
}

/* -------------------------------------------------- profile: board editor */

function openBoardProfileEditor(){
  if(!profile)return;

  const card=$('modal-card');
  card.className='modal-card tall';

  const ov=profile.overrides, eff=profile.effective;

  card.innerHTML=`
    <h1>You on <span class="soft">${esc(board.name)}</span></h1>
    <p>Use a different name or photo here without changing your profile anywhere else.</p>

    ${photoEditorHTML({name:eff.name,color:eff.color,avatar:eff.avatar},ov.hasPhoto,'Use my profile photo')}

    <label class="field-label" for="b-name">Name on this board</label>
    <input class="field" id="b-name" value="${esc(ov.displayName||'')}" maxlength="28"
           placeholder="${esc(me.displayName)}" autocomplete="off">
    <div class="field-note">Leave empty to use your profile name, ${esc(me.displayName)}.</div>

    <button class="acc-btn" id="b-save" style="width:100%;justify-content:center;margin-top:14px">Save</button>
    <button class="mini-btn" id="b-cancel" style="width:100%;margin-top:10px">Cancel</button>`;

  $('modal').classList.remove('hidden');

  bindPhoto('/boards/'+encodeURIComponent(board.slug)+'/profile/photo',data=>{
    applyProfileResponse(data);
    toast('Board photo updated');
    closeModal(); renderHead(); renderView();
  });

  const removeBtn=$('photo-remove');
  if(removeBtn)removeBtn.onclick=async()=>{
    try{
      const data=await api('DELETE','/boards/'+encodeURIComponent(board.slug)+'/profile/photo');
      applyProfileResponse(data);
      toast('Using your profile photo here');
      closeModal(); renderHead(); renderView();
    }catch(e){ handleApiError(e,'Could not remove the photo'); }
  };

  $('b-cancel').onclick=()=>{ syncTheme(); closeModal(); };

  $('b-save').onclick=async()=>{
    const typed=$('b-name').value.trim();

    try{
      const data=await saveBoardProfile({
        displayName: typed===''?null:typed,
      });
      applyProfileResponse(data);
      closeModal();
      toast('Board profile saved');
      syncTheme(); renderHead(); await resync(); renderView();
    }catch(e){ handleApiError(e,'Could not save'); }
  };
}

/** Fold a { profile, members, cursor } response back into local state. */
function applyProfileResponse(data){
  if(!data)return;
  if(data.profile)profile=data.profile;
  if(data.members)members=data.members;
  if(data.cursor)cursor=Math.max(cursor,data.cursor);
  syncTheme();
}

/** Re-read my board profile, e.g. after the master profile it inherits changed. */
async function refreshBoardProfile(){
  if(!board)return;
  try{
    const data=await api('GET','/boards/'+encodeURIComponent(board.slug)+'/profile');
    profile=data.profile;
    syncTheme();
  }catch(e){ /* the next full sync will pick it up */ }
}

/** What the closed "Recently deleted" row says. Empty until the trash loads. */
function trashSummary(){
  if(!trash.loaded)return '';
  return trash.items.length
    ? trash.items.length+(trash.items.length===1?' card':' cards')
    : 'None';
}

function renderTrash(){
  const host=$('trash-list'); if(!host)return;

  if(!trash.loaded){ loadTrash(); return; }

  // The list arrives after the panel was drawn, so the closed row's count has
  // to be filled in here rather than waiting for the next full render.
  const sum=view.querySelector('.disclosure[data-panel="trash"] .swatch-name');
  if(sum)sum.textContent=trashSummary();

  if(!trash.items.length){
    host.innerHTML=`<div class="note-small">Nothing deleted in the last 30 days.</div>`;
    return;
  }

  host.innerHTML=trash.items.map(n=>{
    const by=n.updatedBy||{name:'someone',color:'#4B5240'};
    return `<div class="row" style="cursor:default">
      ${avatarHTML(by,36)}
      <div class="row-body">
        <div class="row-title"><span>${esc(n.title&&n.title.trim()?n.title:'Untitled card')}</span></div>
        <div class="row-meta">deleted ${esc(ago(n.deletedAt))}</div>
      </div>
      <button class="row-act" data-restore="${esc(n.id)}">Restore</button>
    </div>`;}).join('');

  host.querySelectorAll('[data-restore]').forEach(b=>b.onclick=()=>restoreNote(b.dataset.restore));
}

async function loadTrash(){
  if(!board)return;
  try{
    const data=await api('GET','/boards/'+encodeURIComponent(board.slug)+'/trash');
    trash={items:data.notes,loaded:true};
    if(tab==='you')renderTrash();
  }catch(e){ handleApiError(e); }
}

function renderView(){
  // Desktop only: the card grid wants the whole window and the other three
  // tabs do not, and only the stylesheet should know that.
  view.dataset.tab=tab;
  if(tab==='notes')renderNotes();
  else if(tab==='calendar')renderCalendar();
  else if(tab==='activity')renderActivity();
  else renderYou();
}
function renderAll(){renderHead();renderChips();renderView();}

/* ---------------------------------------------------------------- sheet  */

function openCard(id){
  if(!notes[id])return;
  activeId=id;
  const n=notes[id];
  eTitle.value=n.title||''; eContent.value=n.content||'';
  clearConflict();
  // A card with nothing in it wants the keyboard, not a preview of nothing.
  setSheetMode(!n.content);
  refreshSheetChrome(); closeMenu();
  scrim.classList.add('open'); sheet.classList.add('open');
  setTimeout(()=>{if(!n.title&&!n.content)eTitle.focus();},340);
}

/* The sheet reads by default and edits on demand. `editing` is the whole of
 * that state: the textarea is the source of truth, #e-read is a view of it. */
let editing=false;

function setSheetMode(edit){
  editing=!!edit;
  eRead.classList.toggle('hidden',editing);
  eEditor.classList.toggle('hidden',!editing);
  eTools.classList.toggle('hidden',!editing);
  if(!editing)renderRead();
  // Both are drawn hidden, so they settle on the way in.
  else syncEditor();
}

function renderRead(){
  const n=activeId&&notes[activeId];
  const body=n?renderRich(n.content):'';
  eRead.innerHTML = body || '<p class="rich-empty">Nothing written yet. Tap to start.</p>';
  eRead.querySelectorAll('.box').forEach(b=>{
    b.onclick=ev=>{ ev.stopPropagation(); toggleTask(parseInt(b.dataset.line,10)); };
  });
}

function startEditing(){
  if(editing)return;
  setSheetMode(true);
  eContent.focus();
  // Caret to the end rather than wherever the browser last left it.
  const end=eContent.value.length;
  try{ eContent.setSelectionRange(end,end); }catch(unsupported){}
}

/** Tick or untick one task line, rewriting only that line of the source. */
function toggleTask(lineNo){
  const n=activeId&&notes[activeId]; if(!n||isNaN(lineNo))return;

  const lines=String(n.content||'').split('\n');
  const m=lines[lineNo]&&lines[lineNo].match(TASK_RE);
  if(!m)return;

  const done=m[2]!==' ';
  lines[lineNo]=m[1]+'- ['+(done?' ':'x')+'] '+m[3];

  n.content=lines.join('\n');
  eContent.value=n.content;
  renderRead();          // paint the tick straight away
  onEdit();              // then save it the same way any other edit is saved
}

function closeCard(){
  if(pending&&saveTimer){clearTimeout(saveTimer);flushSave(pending);}
  closeMenu();
  sheet.classList.remove('open'); scrim.classList.remove('open');
  activeId=null; editing=false; clearConflict();
}

function refreshSheetChrome(){
  const n=activeId&&notes[activeId]; if(!n)return;
  if(!editing)renderRead();

  // The menu says what tapping it would do; the tags say what is already true.
  mFlagTxt.textContent=n.pinned?'Remove the flag':'Flag this card';
  $('m-flag').classList.toggle('on',!!n.pinned);
  mDateTxt.textContent=n.dueDate?'Change the date':'Add a date';
  $('m-date').classList.toggle('on',!!n.dueDate);

  eTags.innerHTML=
    (n.pinned?`<span class="due-pill flagged">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h13l-2.5 4.5L18 12H6"/><path d="M6 3v18"/></svg>Flagged</span>`:'')
   +(n.dueDate?`<span class="due-pill on">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="3.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>${esc(prettyDate(n.dueDate))}</span>`:'');

  // Who made this card and who touched it last, on one line. The avatar is the
  // creator's, because that is the name the line leads with. When the two are
  // the same person - which most cards are - naming them twice would be noise,
  // so the second half drops to a bare "edited".
  const made=n.createdBy, by=n.updatedBy;
  const same=made&&by&&made.id===by.id;
  const tail=by
    ? (same?`edited ${esc(ago(n.updatedAt))}`
          :`edited by ${esc(by.name)} ${esc(ago(n.updatedAt))}`)
    : '';

  eMeta.innerHTML=made
    ? `${avatarHTML(made,22)}<span>Created by ${esc(made.name)}${tail?' · '+tail:''}</span>`
    : (by?`${avatarHTML(by,22)}<span>Last edited by ${esc(by.name)} · ${esc(ago(n.updatedAt))}</span>`:'');
}

function onEdit(){
  const n=activeId&&notes[activeId]; if(!n)return;
  n.title=eTitle.value; n.content=eContent.value;
  editSeq++;
  pending=activeId; setLive('saving','Saving…');
  if(tab==='notes')renderNotes();
  clearTimeout(saveTimer);
  saveTimer=setTimeout(()=>flushSave(activeId),DEBOUNCE);
}

eTitle.addEventListener('input',onEdit);
eContent.addEventListener('input',onEdit);
/* Back leaves the card, in one tap, from wherever you are in it.
 *
 * It briefly took two - out of the editor, then out of the card - so that the
 * rendered view stayed reachable after "Done formatting" was removed. That was
 * the wrong trade: an empty card opens straight into the editor, so the common
 * case became "open a card, tap back, still be in the card", which reads as the
 * button not working. Ticking a checkbox needs the rendered view, but a card
 * opened to read is already showing it; only a card you were *editing* is not,
 * and by then you are usually leaving anyway.
 *
 * closeCard() flushes a pending save first, so leaving mid-keystroke loses
 * nothing. */
$('e-back').onclick=closeCard;
scrim.onclick=closeCard;

/* ------------------------------------------------------------ card menu */

/* Flag, date and delete live behind the three dots, so the card screen itself
 * is the card: a title, its text, and a way back. */
function openMenu(){
  eMenuPop.classList.remove('hidden');
  eMenu.setAttribute('aria-expanded','true');
}
function closeMenu(){
  eMenuPop.classList.add('hidden');
  eMenu.setAttribute('aria-expanded','false');
}
function menuOpen(){ return !eMenuPop.classList.contains('hidden'); }

eMenu.onclick=e=>{ e.stopPropagation(); menuOpen()?closeMenu():openMenu(); };

// Anywhere else closes it, including the card's own text.
document.addEventListener('click',e=>{
  if(menuOpen()&&!eMenuPop.contains(e.target))closeMenu();
});

// Reading is the default; tapping the text is how you get to the editor. The
// checkbox handler stops propagation, so ticking a box does not open it.
eRead.onclick=startEditing;

/* Done leaves the *editor* and stays in the card, which is the one move Back
 * no longer makes. Back means "leave the card" now (see above), so without
 * this the rendered view - and with it the tickable checkboxes - is only
 * reachable by closing the card and opening it again.
 *
 * There is nothing to flush here: onEdit() writes the textarea straight into
 * notes[activeId] on every keystroke, so renderRead() already has the current
 * text, and the debounced save is still armed and still guarded by `pending`
 * against the poll. Blur first, or the phone keyboard stays up over a card
 * that is no longer being edited. */
$('e-done').onclick=()=>{
  try{ eContent.blur(); }catch(ignored){}
  setSheetMode(false);
};

/* =========================================================================
   The formatting bar.

   Everything it writes is a marker in the plain text of the card - see
   decision 8. There is no rich-text model and no contenteditable: the textarea
   holds the source, and `renderRich()` stays a pure function of it.

   The design turns on one awkward fact: `*` and `**` share a character. A run
   of asterisks has to be read *whole* before you can say whose markers are in
   it, and the previous bar failed twice on exactly that - once by reading a
   single `*` out of a `**` and silently breaking bold, once by refusing every
   run of three and turning bold-italic into five asterisks a side.

   So bold and italic are scanned *together* by `asteriskMarkers()`, which
   walks the string once and knows whether each run opens or closes; and
   `inlineAt()` answers the only question anyone asks - "is the caret or
   selection inside this style, and where are its two markers?". The toggle
   and the lit-button state read that same answer, so they cannot disagree.
   Last time they were separate notions and the bugs lived in the gap.
   ========================================================================= */

const INLINE = { bold:'**', italic:'*', underline:'__' };

/**
 * Where bold's and italic's markers are, scanned together.
 *
 * They have to be read together because they share a character, and the
 * ambiguous case - three in a row - only has one reading that works:
 * **italic outside, bold inside**. That is the reading where removing either
 * style leaves the other one still well formed (`***x***` minus bold is
 * `*x*`, minus italic is `**x**`). Any other split interleaves them and
 * breaks whichever one you keep.
 *
 * Which half of a triple comes first therefore depends on whether the run
 * opens or closes - outer first going in, inner first coming out - and that
 * is the whole reason this walks the string with two open/closed flags rather
 * than judging each run on its own.
 */
function asteriskMarkers(val){
  const bold=[], italic=[];
  let bOpen=false, iOpen=false, i=0;
  while(i<val.length){
    if(val[i]!=='*'){ i++; continue; }
    let j=i; while(j<val.length&&val[j]==='*')j++;
    const len=j-i;

    if(len===1){ italic.push(i); iOpen=!iOpen; }
    else if(len===2){ bold.push(i); bOpen=!bOpen; }
    else if(len===3){
      if(bOpen&&iOpen){ bold.push(i);   italic.push(i+2); }  // closing: inner first
      else            { italic.push(i); bold.push(i+1);   }  // opening: outer first
      bOpen=!bOpen; iOpen=!iOpen;
    }
    else if(len===4){ bold.push(i); bold.push(i+2); }        // `****`, nothing between
    // Longer runs are not markup anybody meant; left alone.
    i=j;
  }
  return {bold,italic};
}

/**
 * Where this style's markers sit.
 *
 * Underline has no single-character sibling in this subset, so its runs read
 * simply; bold and italic go through the joint scan above.
 */
function markerPositions(val,mark){
  if(mark!=='__'){
    const found=asteriskMarkers(val);
    return mark==='**'?found.bold:found.italic;
  }
  const out=[];
  let i=0;
  while(i<val.length){
    if(val[i]!=='_'){ i++; continue; }
    let j=i; while(j<val.length&&val[j]==='_')j++;
    const len=j-i;
    if(len===2)out.push(i);
    else if(len===4){ out.push(i); out.push(i+2); }
    i=j;
  }
  return out;
}

/**
 * Is [start,end) inside this style, and where are its markers?
 *
 * Markers pair off left to right, the way the renderer reads them. A range
 * counts as styled when it sits inside a pair - which covers a bare caret, a
 * selection of the styled text, and a selection of the whole thing including
 * its markers.
 */
function inlineAt(val,start,end,mark){
  const m=mark.length, marks=markerPositions(val,mark);
  for(let k=0;k+1<marks.length;k+=2){
    const open=marks[k], close=marks[k+1], inner=open+m;
    if((start>=inner&&end<=close)||(start<=open&&end>=close+m)){
      return {on:true,open,close,empty:inner===close};
    }
  }
  return {on:false};
}

/** The lines [from,to) a selection touches, whole. */
function lineRange(val,start,end){
  const from=val.lastIndexOf('\n',start-1)+1;
  let to=val.indexOf('\n',end); if(to<0)to=val.length;
  return {from,to};
}

/* Heading and checklist are the two line styles. A line is one or the other -
   a heading that is also a checklist item is not a thing - so applying either
   strips whatever the line already carried. */
const BLOCK = {
  heading:{ re:HEAD_RE, add:l=>'# '+l,     strip:l=>l.replace(HEAD_RE,'$1')   },
  task:   { re:TASK_RE, add:l=>'- [ ] '+l, strip:l=>l.replace(TASK_RE,'$1$3') },
  bullet: { re:BULLET_RE, add:l=>'- '+l,   strip:l=>l.replace(BULLET_RE,'$1$2') },
};

function bareLine(line){
  for(const k in BLOCK){
    if(BLOCK[k].re.test(line))return BLOCK[k].strip(line);
  }
  return line;
}

/* ---------------------------------------------------------- the editor mirror

   A textarea cannot style part of its own text - it is one uniform font - so
   bold written as `**bold**` shows you the asterisks and nothing else. The fix
   is a styled copy of the same text sitting exactly behind a textarea whose
   own text is transparent. You type into the textarea, which stays the source
   of truth and owns the caret; the mirror only paints.

   That buys the styling without a contenteditable, which matters here: the
   save path, the 409 conflict flow and the "content is plain text" invariant
   all read `eContent.value`, and every one of them would have had to move.

   The hard rule is **the mirror may not change any glyph's advance width**, or
   the painted text drifts out from under the caret, a little more with every
   character on the line. So the markers are dimmed rather than removed (their
   width still has to be there), weight is faked with `-webkit-text-stroke`
   rather than a bolder face, and a heading is not made bigger. Underline and
   line-through are free - neither moves anything.
   ---------------------------------------------------------------------------- */

/**
 * Is italic safe to paint in the mirror on *this* device?
 *
 * A real italic face is usually narrower than the upright one, and any width
 * difference slides the painted text out from under the caret. A synthesised
 * oblique is a pure shear and costs nothing. Which one you get depends on the
 * font the stack actually resolved to - the webfont here is loaded without an
 * italic axis, so browsers shear it, but a Mac resolving to SF Pro has a real
 * italic face and might not.
 *
 * Rather than guess per platform, measure once and let the answer decide.
 * Bold never asks this question: a bolder face is always wider, which is why
 * it is faked with a stroke instead.
 */
let italicSafe=null;
function italicIsMetricSafe(){
  if(italicSafe!==null)return italicSafe;

  const probe=document.createElement('div');
  probe.style.cssText='position:absolute;left:-9999px;top:0;white-space:pre;'
    +'font-family:var(--font);font-size:17px;letter-spacing:-.008em;';
  const plain=document.createElement('span'), slanted=document.createElement('span');
  plain.textContent=slanted.textContent='fabricator quotes 640mm';
  slanted.style.fontStyle='italic';
  probe.appendChild(plain); probe.appendChild(slanted);
  document.body.appendChild(probe);

  italicSafe=Math.abs(plain.getBoundingClientRect().width
                     -slanted.getBoundingClientRect().width)<0.5;
  probe.remove();
  return italicSafe;
}

/** Flag the marker characters of each pair, and the text between them. */
function flagPairs(positions,m,isMarker,inside){
  for(let k=0;k+1<positions.length;k+=2){
    const open=positions[k], close=positions[k+1];
    for(let x=open;x<open+m;x++)isMarker[x]=true;
    for(let x=close;x<close+m;x++)isMarker[x]=true;
    for(let x=open+m;x<close;x++)inside[x]=true;
  }
}

/** One run of characters that share a style, escaped and wrapped. */
function mirrorRun(key,text){
  if(!text)return '';
  const e=esc(text);
  if(key==='m')return '<span class="mk">'+e+'</span>';
  if(!key)return e;
  let cls='';
  if(key.indexOf('b')>=0)cls+=' hl-b';
  if(key.indexOf('i')>=0)cls+=' hl-i';
  if(key.indexOf('u')>=0)cls+=' hl-u';
  return '<span class="'+cls.slice(1)+'">'+e+'</span>';
}

/** The inline styles of one line, every character preserved in order. */
function mirrorInline(text){
  const n=text.length;
  if(!n)return '';
  const isMarker=new Array(n).fill(false),
        bold=new Array(n).fill(false),
        ital=new Array(n).fill(false),
        undr=new Array(n).fill(false);

  const ast=asteriskMarkers(text);
  flagPairs(ast.bold,2,isMarker,bold);
  flagPairs(ast.italic,1,isMarker,ital);
  flagPairs(markerPositions(text,'__'),2,isMarker,undr);

  const keyAt=x=>isMarker[x]?'m'
    :(bold[x]?'b':'')+(ital[x]?'i':'')+(undr[x]?'u':'');

  let out='', run='', key=keyAt(0);
  for(let x=0;x<n;x++){
    const k=keyAt(x);
    if(k!==key){ out+=mirrorRun(key,run); run=''; key=k; }
    run+=text[x];
  }
  return out+mirrorRun(key,run);
}

/** One source line: its line marker dimmed, its text styled. */
function mirrorLine(line){
  const h=line.match(HEAD_RE);
  if(h){
    const mk=line.slice(0,line.length-h[1].length);
    return '<span class="mk">'+esc(mk)+'</span><span class="hl-h">'+mirrorInline(h[1])+'</span>';
  }
  const bl=line.match(BULLET_RE);
  if(bl){
    const mk=line.slice(0,line.length-bl[2].length);
    return '<span class="mk">'+esc(mk)+'</span>'
         + '<span class="hl-bul">'+mirrorInline(bl[2])+'</span>';
  }
  const t=line.match(TASK_RE);
  if(t){
    const mk=line.slice(0,line.length-t[3].length);
    return '<span class="mk">'+esc(mk)+'</span>'
         + '<span class="hl-t'+(t[2]===' '?'':' done')+'">'+mirrorInline(t[3])+'</span>';
  }
  return mirrorInline(line);
}

/**
 * Repaint the mirror and keep it scrolled with the textarea.
 *
 * The trailing space matters: a textarea reserves room after the last
 * character and `white-space:pre-wrap` collapses a bare trailing newline, so
 * without it the mirror comes up one line short of the text it is copying.
 */
function syncMirror(){
  if(!eMirror||!eContent)return;
  // Measured on first paint, by which point the webfont has settled.
  eMirror.classList.toggle('italic-ok',italicIsMetricSafe());
  eMirror.innerHTML=String(eContent.value).split('\n').map(mirrorLine).join('\n')+' ';
  eMirror.scrollTop=eContent.scrollTop;
  eMirror.scrollLeft=eContent.scrollLeft;
}

function setText(ta,value,start,end){
  ta.value=value;
  ta.setSelectionRange(start,end);
}

function afterFormat(ta){
  ta.focus();
  onEdit();
  syncEditor();
}

/** The two things that follow the text: the painted mirror and the lit bar. */
function syncEditor(){
  syncMirror();
  syncBar();
}

/**
 * Add or remove `mark` around [a,b) of a single line. Pure; the caller splices
 * the result back. Returns the new line and where the selection lands in it.
 *
 * `force` is 'on' or 'off' when a whole block has to move the same way, and
 * undefined when the range decides for itself.
 */
function inlineOnLine(line,a,b,mark,force){
  const m=mark.length, at=inlineAt(line,a,b,mark);
  const remove = force===undefined ? at.on : (force==='off');

  if(at.on && remove){
    if(at.empty){
      return {text:line.slice(0,at.open)+line.slice(at.close+m), a:at.open, b:at.open};
    }
    if(a===b){
      // Mid-word: step out past the closing marker and edit nothing, so what
      // is written keeps the style and what comes next does not.
      return {text:line, a:at.close+m, b:at.close+m};
    }
    const inner=line.slice(at.open+m,at.close);
    return {text:line.slice(0,at.open)+inner+line.slice(at.close+m),
            a:at.open, b:at.open+inner.length};
  }

  if(at.on || force==='off')return {text:line,a,b};   // already as asked

  if(a===b)return {text:line.slice(0,a)+mark+mark+line.slice(a), a:a+m, b:a+m};
  const sel=line.slice(a,b);
  return {text:line.slice(0,a)+mark+sel+mark+line.slice(b), a, b:b+m*2};
}

/**
 * Bold, italic, underline - over a selection or at the caret.
 *
 * Scoped to the line, always. `renderRich()` splits on newlines before it
 * applies any marker, and so does the mirror, so a `*` on one line and a `*`
 * three lines down are not a pair to either of them. Reasoning over the whole
 * value instead made the toolbar the odd one out: it lit the button for a
 * style nothing rendered, and tapping it pulled one marker out of each of two
 * unrelated lines.
 */
function toggleInline(kind){
  const ta=eContent, mark=INLINE[kind];
  if(!mark)return;
  const val=ta.value, selStart=ta.selectionStart, selEnd=ta.selectionEnd;
  const {from,to}=lineRange(val,selStart,selEnd);
  const block=val.slice(from,to);

  if(block.indexOf('\n')<0){
    const r=inlineOnLine(block,selStart-from,selEnd-from,mark);
    setText(ta,val.slice(0,from)+r.text+val.slice(to),from+r.a,from+r.b);
  }else{
    // A selection dragged across lines. Each line is styled on its own - a
    // marker either side of a newline is not something the renderer pairs -
    // and the block moves together: all on, unless it is already all on.
    const lines=block.split('\n');
    const meaningful=lines.filter(l=>l.trim()!=='');
    const allOn=meaningful.length>0
      && meaningful.every(l=>inlineAt(l,0,l.length,mark).on);
    const done=lines
      .map(l=>l.trim()===''?l:inlineOnLine(l,0,l.length,mark,allOn?'off':'on').text)
      .join('\n');
    setText(ta,val.slice(0,from)+done+val.slice(to),from,from+done.length);
  }

  afterFormat(ta);
}

/** Heading and checklist - across every line the selection touches. */
function toggleBlock(kind){
  const ta=eContent, val=ta.value, style=BLOCK[kind];
  if(!style)return;

  const {from,to}=lineRange(val,ta.selectionStart,ta.selectionEnd);
  const lines=val.slice(from,to).split('\n');
  const allOn=lines.every(l=>style.re.test(l));

  // A block already entirely this style comes off; one that is only partly it
  // is completed, which is what dragging across half a list means by it.
  const done=lines
    .map(l=>allOn?style.strip(l):(style.re.test(l)?l:style.add(bareLine(l))))
    .join('\n');

  const caret=from+done.length;
  setText(ta,val.slice(0,from)+done+val.slice(to),caret,caret);
  afterFormat(ta);
}

/**
 * Delete every ticked line.
 *
 * The whole card, not the selection: "clear the done ones" is something you
 * mean about the list, and making it depend on where the caret happens to be
 * would only be surprising. Unticked lines and ordinary text are untouched.
 */
function clearTicked(){
  const ta=eContent, lines=ta.value.split('\n');
  const kept=lines.filter(l=>{ const m=l.match(TASK_RE); return !(m&&m[2]!==' '); });
  const gone=lines.length-kept.length;

  if(!gone){ toast('Nothing ticked to clear'); return; }

  const text=kept.join('\n');
  setText(ta,text,text.length,text.length);
  afterFormat(ta);
  toast(gone===1?'Cleared 1 ticked item':'Cleared '+gone+' ticked items');
}

/**
 * Light the buttons whose style the caret is currently in, so a toggle says
 * which way it is about to go. Cheap enough to run on every keystroke.
 */
function syncBar(){
  if(!eTools||eTools.classList.contains("hidden"))return;
  const val=eContent.value, start=eContent.selectionStart, end=eContent.selectionEnd;

  const {from,to}=lineRange(val,start,end);
  const lines=val.slice(from,to).split('\n');

  /* Same line scope as the toggle. A button that lights for a style the
     tap will not find is worse than one that never lights: it is the
     bar telling you something the rest of the app disagrees with. A
     selection spanning lines lights nothing inline, because the toggle
     will treat each line on its own. */
  const oneLine=lines.length===1;
  eTools.querySelectorAll('[data-inline]').forEach(b=>{
    const mark=INLINE[b.dataset.inline];
    b.classList.toggle("on", oneLine
      && inlineAt(lines[0],start-from,end-from,mark).on);
  });

  eTools.querySelectorAll('[data-block]').forEach(b=>{
    const style=BLOCK[b.dataset.block];
    b.classList.toggle("on",!!style&&lines.length>0&&lines.every(l=>style.re.test(l)));
  });

  // Nothing ticked, nothing to clear - say so before it is tapped.
  const clear=$('e-clear-ticked');
  if(clear){
    const any=val.split('\n').some(l=>{ const m=l.match(TASK_RE); return !!m&&m[2]!==' '; });
    clear.classList.toggle('dim',!any);
  }
}

/* The bar writes markers into the textarea and never touches the DOM of the
 * rendered view, which stays a pure function of the text. */
eTools.querySelectorAll('[data-inline],[data-block]').forEach(b=>{
  b.onmousedown=e=>e.preventDefault();     // keep the caret where the user left it
  b.onclick=()=>b.dataset.inline?toggleInline(b.dataset.inline):toggleBlock(b.dataset.block);
});

const clearTickedBtn=$('e-clear-ticked');
if(clearTickedBtn){
  clearTickedBtn.onmousedown=e=>e.preventDefault();
  clearTickedBtn.onclick=clearTicked;
}

/* The lit buttons follow the caret, not just the edits - arrowing into bold
 * text should light B without typing anything. selectionchange is the one
 * event that fires for an arrow key, a tap and a drag alike. */
['keyup','click','input','focus'].forEach(ev=>eContent.addEventListener(ev,syncEditor));
eContent.addEventListener('scroll',()=>{
  eMirror.scrollTop=eContent.scrollTop;
  eMirror.scrollLeft=eContent.scrollLeft;
});
document.addEventListener('selectionchange',()=>{
  if(document.activeElement===eContent)syncBar();
});


$('m-date').onclick=()=>{
  const n=activeId&&notes[activeId]; if(!n)return;
  closeMenu();

  const card=$('modal-card');
  card.className='modal-card';
  card.innerHTML=`
    <h1>Card <span class="soft">date</span></h1>
    <p>A card with a date shows up on the board calendar, where everyone sees it.</p>
    <label class="field-label" for="due-date">Due date</label>
    <input class="field" id="due-date" type="date" value="${esc(n.dueDate||'')}">
    <button class="acc-btn" id="due-save" style="width:100%;justify-content:center;margin-top:14px">Save</button>
    ${n.dueDate?'<button class="ghost-btn" id="due-clear" style="margin-top:10px">Take it off the calendar</button>':''}
    <button class="mini-btn" id="due-cancel" style="width:100%;margin-top:10px">Cancel</button>`;
  $('modal').classList.remove('hidden');

  const commit=value=>{
    n.dueDate=value||null;
    closeModal();
    refreshSheetChrome();
    calendar.loaded=false;
    editSeq++; pending=activeId;
    clearTimeout(saveTimer); flushSave(activeId);
    toast(value?'Added to the calendar':'Taken off the calendar');
  };

  $('due-save').onclick=()=>commit($('due-date').value||null);
  $('due-cancel').onclick=closeModal;
  const clear=$('due-clear');
  if(clear)clear.onclick=()=>commit(null);
};

$('m-flag').onclick=()=>{
  const n=activeId&&notes[activeId]; if(!n)return;
  closeMenu();
  n.pinned=!n.pinned; refreshSheetChrome(); pending=activeId;
  clearTimeout(saveTimer); flushSave(activeId);
  if(tab==='notes')renderNotes();
};

/**
 * Delete, which used to be the slide-to-delete rail at the foot of the card.
 * A menu item is one tap, so it asks first - and the card is restorable from
 * the trash for 30 days either way, which is why one confirmation is enough.
 */
$('m-delete').onclick=()=>{
  const id=activeId; if(!id||!notes[id])return;
  closeMenu();

  const name=(notes[id].title||'').trim();
  const card=$('modal-card');
  card.className='modal-card';
  card.innerHTML=`
    <h1>Delete <span class="soft">${name?esc(name):'this card'}</span>?</h1>
    <p>It goes to Recently deleted, where anyone on this board can restore it
      for 30 days.</p>
    <button class="ghost-btn" id="card-del-go">Delete this card</button>
    <button class="mini-btn" id="card-del-cancel" style="width:100%;margin-top:10px">Cancel</button>`;

  $('modal').classList.remove('hidden');
  $('card-del-cancel').onclick=closeModal;
  $('card-del-go').onclick=()=>{ closeModal(); deleteCard(id); };
};

document.addEventListener('keydown',e=>{
  if(e.key!=='Escape')return;
  // The menu is on top of the card, so it is what Escape closes first.
  if(menuOpen()){ closeMenu(); return; }
  if(sheet.classList.contains('open'))closeCard();
});

/* ------------------------------------------------------------- mutations */

async function newCard(){
  if(!board)return;
  if(pending&&saveTimer){clearTimeout(saveTimer);await flushSave(pending);}

  setLive('saving','Adding card…');
  try{
    const data=await createNote(board.slug);
    notes[data.note.id]=data.note;
    cursor=Math.max(cursor,data.cursor||0);
    activity.loaded=false;   // this card's creation is a new activity row
    if(tab!=='notes'){tab='notes';syncTabs();}
    filter='all'; renderAll(); openCard(data.note.id);
    setLive('','Saved');
  }catch(e){ handleApiError(e,'Could not add the card'); }
}

/**
 * Write one note. The whole reason the backend exists: two people saving
 * different cards can no longer clobber each other, because nobody writes the
 * board any more.
 */
async function flushSave(id){
  const local=notes[id]; if(!local)return;
  if(conflict&&conflict.id===id)return;   // wait for the human to choose
  if(saving){queued=id;return;}

  saving=true; setLive('saving','Saving…');

  // Anything typed while the request is in flight must survive the response.
  const seqAtStart=editSeq;

  try{
    const data=await patchNote(id,{
      title:   local.title||'',
      content: local.content||'',
      pinned:  !!local.pinned,
      dueDate: local.dueDate||null,
      version: local.version,
    });

    cursor=Math.max(cursor,data.cursor||0);
    retryDelay=1000;

    if(editSeq!==seqAtStart&&activeId===id){
      // They kept typing. Take the server's new version number, keep their
      // newer text, and save again - otherwise those keystrokes are lost.
      notes[id]={...data.note,title:local.title,content:local.content,
                 pinned:!!local.pinned,dueDate:local.dueDate||null};
      pending=id;
      clearTimeout(saveTimer);
      saveTimer=setTimeout(()=>flushSave(id),DEBOUNCE);
    }else{
      notes[id]=data.note;
      if(pending===id)pending=null;
      setLive('','Saved');
    }

    activity.loaded=false;   // this save is a new (or coalesced) activity row
    calendar.loaded=false;   // the due date may have moved on or off a day
    if(activeId===id)refreshSheetChrome();
    renderChips();
    if(tab!=='notes')renderView(); else renderNotes();

  }catch(e){
    if(e.status===409){
      openConflict(id,e.payload&&e.payload.current);
    }else if(e.status===404){
      delete notes[id];
      if(activeId===id)closeCard();
      renderAll();
      toast('That card was deleted by someone else');
    }else if(e.status===0){
      markOffline(); scheduleRetry(id);
    }else{
      handleApiError(e,'Could not save');
    }
  }finally{
    saving=false;
    if(queued){const next=queued;queued=null;flushSave(next);}
  }
}

function scheduleRetry(id){
  clearTimeout(retryTimer);
  retryTimer=setTimeout(()=>{retryDelay=Math.min(retryDelay*2,RETRY_MAX);flushSave(id);},retryDelay);
}

async function deleteCard(id){
  const snapshot=notes[id];
  delete notes[id];
  closeCard(); renderAll();

  try{
    const data=await deleteNote(id);
    cursor=Math.max(cursor,(data&&data.cursor)||0);
    trash.loaded=false;
    activity.loaded=false;
    showUndo(id);
  }catch(e){
    if(snapshot)notes[id]=snapshot;   // put it back; the delete did not land
    renderAll();
    handleApiError(e,'Could not delete');
  }
}

function showUndo(id){
  const el=$('toast');
  el.innerHTML=`Card moved to Recently deleted · <button class="mini-btn" id="undo" style="padding:6px 12px;margin-left:8px">Undo</button>`;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer=setTimeout(()=>el.classList.remove('show'),6000);
  const btn=$('undo');
  if(btn)btn.onclick=()=>{el.classList.remove('show');restoreNote(id);};
}

async function restoreNote(id){
  try{
    const data=await api('POST','/notes/'+encodeURIComponent(id)+'/restore');
    notes[data.note.id]=data.note;
    cursor=Math.max(cursor,data.cursor||0);
    trash.loaded=false;
    activity.loaded=false;
    renderAll();
    toast('Card restored');
  }catch(e){ handleApiError(e,'Could not restore'); }
}

/* ------------------------------------------------------------- conflicts */

/**
 * 409 handling. Both versions are kept and the person decides - the one thing
 * the prototype could not do, because it had no way to notice.
 */
function openConflict(id,theirs){
  if(!theirs){ handleApiError({status:409,message:'This card changed elsewhere.'}); return; }

  const local=notes[id]||{};
  conflict={
    id,
    mine:{title:local.title||'',content:local.content||'',pinned:!!local.pinned},
    theirs,
  };

  notes[id]=theirs;   // the board shows the saved truth
  renderChips(); if(tab==='notes')renderNotes();

  if(activeId!==id){
    toast('A card you edited was changed by someone else');
    return;
  }

  const who=theirs.updatedBy?theirs.updatedBy.name:'Someone';
  $('conflict-text').textContent=
    who+' saved this card while you were typing. Your text is still in the editor - keep yours, or switch to theirs.';
  $('conflict').classList.remove('hidden');
  setSheetMode(true);
  setLive('err','Conflict — choose a version');
  refreshSheetChrome();
}

function clearConflict(){
  conflict=null;
  const el=$('conflict');
  if(el)el.classList.add('hidden');
}

$('conflict-keep').onclick=async()=>{
  if(!conflict)return;
  const {id,mine,theirs}=conflict;
  clearConflict();
  // Rebase our text onto their version number and save again.
  notes[id]={...theirs,...mine,version:theirs.version};
  eTitle.value=mine.title; eContent.value=mine.content;
  pending=id;
  await flushSave(id);
};

$('conflict-theirs').onclick=()=>{
  if(!conflict)return;
  const {id,theirs}=conflict;
  clearConflict();
  notes[id]=theirs;
  if(activeId===id){eTitle.value=theirs.title||'';eContent.value=theirs.content||'';}
  pending=null;
  refreshSheetChrome(); renderAll();
  setLive('','Up to date');
};

/* ------------------------------------------------------------------ tabs */

function syncTabs(){document.querySelectorAll('.tab[data-tab]').forEach(b=>b.classList.toggle('on',b.dataset.tab===tab));}
document.querySelectorAll('.tab[data-tab]').forEach(b=>b.onclick=()=>{
  tab=b.dataset.tab; syncTabs(); renderChips(); renderView(); view.scrollTop=0;
  // The push state is the one thing on this tab that can be stale by the time
  // you arrive, because it waits on the service worker. Re-ask on the way in;
  // it repaints only if the answer moved.
  if(tab==='you')syncPushRow();});
$('tab-new').onclick=newCard;

$('q').addEventListener('input',e=>setQuery(e.target.value));
$('q').addEventListener('keydown',e=>{
  if(e.key==='Escape'){ setQuery(''); e.target.blur(); }
});
$('q-clear').onclick=()=>{ setQuery(''); $('q').focus(); };
$('btn-layout').onclick=toggleCardsLayout;
$('btn-sync').onclick=async()=>{setLive('saving','Syncing');await resync();setLive('','Up to date');};
$('me-role').onclick=openBoardSwitcher;

/* Search is the one thing on a desktop you reach for often enough to want a
 * key: Cmd/Ctrl-K from anywhere, or "/" when you are not already typing into
 * something. Both move to Cards first, since that is the only tab search
 * applies to. Nothing here fires while a card or a modal is open - Escape
 * still owns those. */
function focusSearch(){
  if(tab!=='notes'){ tab='notes'; syncTabs(); renderChips(); renderView(); view.scrollTop=0; }
  const box=$('q');
  if(box){ box.focus(); box.select(); }
}

document.addEventListener('keydown',e=>{
  const slash = e.key==='/' && !e.metaKey && !e.ctrlKey && !e.altKey;
  const cmdK  = (e.key==='k'||e.key==='K') && (e.metaKey||e.ctrlKey) && !e.altKey;
  if(!slash&&!cmdK)return;

  const t=e.target;
  const typing = !!t && (t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.isContentEditable);
  if(slash&&typing)return;
  if(!board||sheet.classList.contains('open')||!$('modal').classList.contains('hidden'))return;

  e.preventDefault();
  focusSearch();
});

/* ------------------------------------------------------------------ sync */

/**
 * Live updates.
 *
 * The API surface in CLAUDE.md asked for `WS /api/boards/:slug/live`. Shared
 * hosting has no process to hold a socket open, so the same events arrive over
 * a cursor-based delta poll instead: the server hands back only what changed
 * since our last cursor, which is a few dozen bytes when nothing has.
 *
 * Everything below the transport is unchanged by that choice - swapping in a
 * WebSocket later means replacing tick() and nothing else.
 */
function startSync(){
  stopSync();
  syncTimer=setInterval(tick,document.hidden?SYNC_HIDDEN:SYNC_ACTIVE);
}
function stopSync(){ if(syncTimer){clearInterval(syncTimer);syncTimer=null;} }

document.addEventListener('visibilitychange',()=>{
  if(board){ startSync(); if(!document.hidden) tick(); }
});

async function tick(){
  if(!board||saving)return;

  try{
    const data=await api('GET',
      '/boards/'+encodeURIComponent(board.slug)+'/changes?since='+encodeURIComponent(cursor));

    if(data.resync){ await resync(); return; }

    cursor=data.cursor||cursor;

    let touched=false;

    (data.changed||[]).forEach(n=>{
      // Never let a remote update overwrite the card being typed into.
      if(pending===n.id||(conflict&&conflict.id===n.id))return;
      notes[n.id]=n; touched=true;

      // If that card is open and idle, move the editor to their text too.
      // Leaving stale text in the boxes would let the next keystroke save it
      // over their change at the new version - a silent overwrite with no 409.
      if(activeId===n.id&&!isTyping()){
        eTitle.value=n.title||'';
        eContent.value=n.content||'';
      }
    });

    (data.deleted||[]).forEach(id=>{
      if(notes[id]){delete notes[id];touched=true;}
      if(activeId===id){closeCard();toast('That card was deleted');}
    });

    if(data.members){ members=data.members; touched=true; }

    // Someone added, moved or deleted an event. Only a flag comes down the
    // wire; the month on screen is what decides which range to re-read.
    if(data.calendar){
      calendar.loaded=false;
      activity.loaded=false;
      if(tab==='calendar')renderCalendar();
      if(tab==='activity')loadActivity();
    }

    // Someone on the board picked a new theme. Unlike a profile change this
    // needs no resync - it is one attribute on <html> and no note data moves.
    if(data.theme&&data.theme!==board.theme){
      board.theme=data.theme;
      syncTheme();
      // The picker on the You tab is showing the old tick and label.
      if(tab==='you')touched=true;
    }

    // The owner renamed the board. Same reasoning as the theme: one board
    // attribute, no note data behind it, so the new value is the whole delta
    // and nobody has to reload the board to see it.
    if(data.name&&data.name!==board.name){
      board.name=data.name;
      syncBoardsEntry();
      touched=true;
    }

    if(touched){
      renderHead(); renderChips();
      if(activeId&&notes[activeId])refreshSheetChrome();
      if(!isTyping())renderView();
      if(tab==='activity')loadActivity();
    }

  }catch(e){
    if(e.status===0)markOffline();
    else if(e.status===401)requireSignIn();
    else if(e.status===404)await pickBoard();
  }
}

function isTyping(){
  return document.activeElement===eTitle||document.activeElement===eContent;
}

/** Full reload of the current board - used on reset, cursor gaps, and manual sync. */
async function resync(){
  if(!board)return;
  try{
    const data=await fetchBoard(board.slug);
    applyBoard(data);
    if(!isTyping())renderAll(); else {renderHead();renderChips();}
  }catch(e){ handleApiError(e); }
}

function applyBoard(data){
  board  = data.board;
  cursor = data.cursor||0;
  members= data.members||[];
  profile= data.profile||null;

  // Each board carries its own theme, so opening one repaints the app.
  syncTheme();
  syncBoardsEntry();

  const kept = (pending&&notes[pending]) ? {[pending]:notes[pending]} : {};
  notes = {};
  (data.notes||[]).forEach(n=>{notes[n.id]=n;});
  Object.assign(notes,kept);   // preserve the card being edited right now

  activity={items:[],nextCursor:null,loaded:false};
  trash={items:[],loaded:false};
  query=''; const qbox=$('q'); if(qbox)qbox.value='';
  calendar={events:[],cards:[],loaded:false,month:null,selected:null};
}

/* ---------------------------------------------------------------- boards */

async function switchBoard(slug){
  if(board&&slug===board.slug){return;}
  if(pending&&saveTimer){clearTimeout(saveTimer);await flushSave(pending);}

  closeModal();
  setLive('saving','Opening board…');

  try{
    const data=await fetchBoard(slug);
    pending=null; activeId=null;
    applyBoard(data);
    store.set('conotes-last-board',slug);
    setUrl('/b/'+slug);
    tab='notes'; filter='all'; syncTabs();
    renderAll(); startSync();
    setLive('','Up to date');
    boards=await loadBoards();
    renderSideBoards();
  }catch(e){ handleApiError(e,'Could not open that board'); }
}

function openBoardSwitcher(){
  const card=$('modal-card');
  card.className='modal-card';
  card.innerHTML=`
    <h1>Your boards</h1>
    <p>Each board is its own set of cards, shared with the people on it.</p>
    ${boards.map(b=>`
      <button class="row flat${board&&b.slug===board.slug?' on':''}" data-board="${esc(b.slug)}" style="margin-bottom:10px">
        <div class="row-body">
          <div class="row-title"><span>${esc(b.name)}</span></div>
          <div class="row-meta">${b.noteCount} card${b.noteCount===1?'':'s'} · ${b.memberCount} member${b.memberCount===1?'':'s'}</div>
        </div>
      </button>`).join('')}
    <button class="acc-btn" id="switch-new" style="margin-top:6px">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      New board</button>
    <button class="mini-btn" id="switch-close" style="width:100%;margin-top:10px">Close</button>`;

  card.querySelectorAll('[data-board]').forEach(b=>b.onclick=()=>switchBoard(b.dataset.board));
  $('switch-new').onclick=promptNewBoard;
  $('switch-close').onclick=closeModal;
  $('modal').classList.remove('hidden');
}

function promptNewBoard(){
  const card=$('modal-card');
  card.className='modal-card';
  card.innerHTML=`
    <h1>New board</h1>
    <p>You will be its owner, and the only person on it until you invite someone.</p>
    <input class="field" id="board-name" placeholder="Board name" maxlength="80" autocomplete="off">
    <button class="acc-btn" id="board-go">Create board</button>
    <button class="mini-btn" id="board-cancel" style="width:100%;margin-top:10px">Cancel</button>`;
  $('modal').classList.remove('hidden');
  setTimeout(()=>$('board-name').focus(),80);

  const go=async()=>{
    const name=$('board-name').value.trim();
    if(!name)return;
    try{
      const data=await api('POST','/boards',{name});
      boards=await loadBoards();
      await switchBoard(data.board.slug);
      toast('Board created');
    }catch(e){ handleApiError(e,'Could not create the board'); }
  };
  $('board-go').onclick=go;
  $('board-cancel').onclick=closeModal;
  $('board-name').addEventListener('keydown',e=>{if(e.key==='Enter')go();});
}

/**
 * Rename the open board. Owners only - the server checks that too, and the
 * button is not drawn for anyone else.
 *
 * Only the name moves. The slug is the board's address: members have it in
 * their URL bar and in invite links already sent, so renaming deliberately
 * leaves it alone.
 */
function promptRenameBoard(){
  if(!board)return;

  const card=$('modal-card');
  card.className='modal-card';
  card.innerHTML=`
    <h1>Rename <span class="soft">${esc(board.name)}</span></h1>
    <p>Everyone on this board sees the new name within a second. Its address stays
      as it is, so links and invites already out there keep working.</p>
    <input class="field" id="rename-name" value="${esc(board.name)}" maxlength="80" autocomplete="off">
    <button class="acc-btn" id="rename-go">Save name</button>
    <button class="mini-btn" id="rename-cancel" style="width:100%;margin-top:10px">Cancel</button>`;

  $('modal').classList.remove('hidden');
  setTimeout(()=>{const f=$('rename-name'); if(f){f.focus();f.select();}},80);

  const go=async()=>{
    const name=$('rename-name').value.trim();
    if(!name){ toast('A board needs a name'); return; }
    if(name===board.name){ closeModal(); return; }

    try{
      const data=await saveBoardName(name);
      board.name=(data.board&&data.board.name)||name;
      cursor=Math.max(cursor,data.cursor||0);
      syncBoardsEntry();
      closeModal();
      renderHead(); renderView();
      toast('Board renamed');
    }catch(e){ handleApiError(e,'Could not rename the board'); }
  };

  $('rename-go').onclick=go;
  $('rename-cancel').onclick=closeModal;
  $('rename-name').addEventListener('keydown',e=>{if(e.key==='Enter')go();});
}

/** Keep the open board's row in the boards list in step with the board itself. */
function syncBoardsEntry(){
  if(!board)return;
  const row=boards.find(b=>b.slug===board.slug);
  if(row)row.name=board.name;
}

async function makeInvite(){
  try{
    const data=await api('POST','/boards/'+encodeURIComponent(board.slug)+'/invites');
    const url=data.invite.url;
    let copied=false;
    try{ await navigator.clipboard.writeText(url); copied=true; }catch(clipboardBlocked){ copied=false; }
    if(copied)toast('Invite link copied to your clipboard');
    else showInviteLink(url);
  }catch(e){ handleApiError(e,'Could not create an invite'); }
}

function showInviteLink(url){
  const card=$('modal-card');
  card.className='modal-card';
  card.innerHTML=`
    <h1>Invite link</h1>
    <p>Anyone who opens this link joins the board. It lasts seven days.</p>
    <input class="field" id="invite-url" value="${esc(url)}" readonly>
    <button class="mini-btn" id="invite-close" style="width:100%">Done</button>`;
  $('modal').classList.remove('hidden');
  $('invite-close').onclick=closeModal;
  const input=$('invite-url'); input.focus(); input.select();
}

async function leaveBoard(){
  const btn=$('leave');
  if(btn.dataset.armed!=='1'){btn.dataset.armed='1';btn.textContent='Tap again to leave this board';return;}
  try{
    await api('POST','/boards/'+encodeURIComponent(board.slug)+'/leave');
    store.remove('conotes-last-board');
    boards=await loadBoards();
    board=null; profile=null;
    await pickBoard();
    toast('You left the board');
  }catch(e){ btn.dataset.armed='0'; btn.textContent='Leave this board'; handleApiError(e); }
}

/**
 * Deleting a board is the one action here that cannot be undone from the app,
 * and it takes everyone else's work with it - so it asks for the board's name
 * rather than the double-tap the reversible actions use. The asymmetry is the
 * point: a slip of the thumb should not be able to do this.
 */
function confirmDeleteBoard(){
  if(!board)return;
  const name=board.name;

  const card=$('modal-card');
  card.className='modal-card';
  card.innerHTML=`
    <h1>Delete <span class="soft">${esc(name)}</span></h1>
    <p>This removes the board for everyone on it, along with every card, the
      calendar and the activity history. It cannot be undone from here.</p>

    <label class="field-label" for="del-name">Type the board's name to confirm</label>
    <input class="field" id="del-name" placeholder="${esc(name)}" autocomplete="off" spellcheck="false">
    <div class="field-note">Members on this board will be moved to another one of
      theirs the next time their app checks in.</div>

    <button class="ghost-btn" id="del-go" style="margin-top:14px" disabled>Delete this board</button>
    <button class="mini-btn" id="del-cancel" style="width:100%;margin-top:10px">Cancel</button>`;

  $('modal').classList.remove('hidden');
  setTimeout(()=>{const f=$('del-name'); if(f)f.focus();},80);

  const field=$('del-name'), go=$('del-go');
  const check=()=>{ go.disabled = field.value.trim() !== name; };
  field.addEventListener('input',check);
  field.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!go.disabled)go.click(); });
  $('del-cancel').onclick=closeModal;

  go.onclick=async()=>{
    if(field.value.trim()!==name)return;
    go.disabled=true; go.textContent='Deleting…';
    try{
      await deleteBoard(board.slug);
      closeModal();
      store.remove('conotes-last-board');
      board=null; profile=null;
      await pickBoard();
      toast('Board deleted');
    }catch(e){
      go.disabled=false; go.textContent='Delete this board';
      handleApiError(e,'Could not delete the board');
    }
  };
}

async function resetBoard(){
  const btn=$('reset');
  if(btn.dataset.armed!=='1'){btn.dataset.armed='1';btn.textContent='Tap again to clear every card';return;}
  try{
    await api('POST','/boards/'+encodeURIComponent(board.slug)+'/reset');
    await resync();
    toast('Board cleared — cards are restorable for 30 days');
  }catch(e){ handleApiError(e,'Could not reset the board'); }
}

async function signOut(){
  try{ await api('DELETE','/session'); }catch(ignored){}
  store.remove('conotes-last-board');
  profile=null; me=null;
  applyTheme(DEFAULT_THEME);
  location.href='/';
}

/* ------------------------------------------------------------- sign-in   */

const PROVIDER_LABEL={google:'Continue with Google',github:'Continue with GitHub'};
const PROVIDER_ICON={
  google:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h5.5a6 6 0 1 1-1.8-2.6"/></svg>',
  github:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-4 1.2-4-2.2-5.5-2.8M15 21v-3.4c0-1 .3-1.7.8-2.2C12.5 15 9.5 13.9 9.5 9a4 4 0 0 1 1.1-2.8 3.7 3.7 0 0 1 .1-2.8s.9-.3 3 1.1a10.2 10.2 0 0 1 5.4 0c2.1-1.4 3-1.1 3-1.1.4 1 .2 2 .1 2.8A4 4 0 0 1 23 9c0 4.9-3 6-6.3 6.4.5.5.8 1.3.8 2.4V21"/></svg>',
};

function requireSignIn(reason){
  stopSync();
  const providers=(window.__coNotesProviders||['google','github']);
  const card=$('modal-card');
  card.className='modal-card';

  card.innerHTML=`
    <h1>Co notes, <span class="soft">a shared notes board.</span></h1>
    <p>Sign in so your edits carry your name. Everyone on a board can read and edit every card on it.</p>
    ${providers.map(p=>`<button class="oauth-btn" data-provider="${esc(p)}">${PROVIDER_ICON[p]||''}${esc(PROVIDER_LABEL[p]||p)}</button>`).join('')}
    ${providers.length?'':'<div class="note-small">No sign-in provider is configured on this server yet. Add the Google or GitHub keys to .env.</div>'}
    ${reason?`<div class="note-small">${esc(reason)}</div>`:''}`;

  card.querySelectorAll('[data-provider]').forEach(b=>b.onclick=()=>{
    const next=encodeURIComponent(location.pathname+location.search);
    location.href='/api/auth/'+b.dataset.provider+'?next='+next;
  });

  $('boot').classList.add('hidden');
  $('modal').classList.remove('hidden');
}

function closeModal(){ $('modal').classList.add('hidden'); }

/* --------------------------------------------------------------- invites */

async function showJoinScreen(token){
  const card=$('modal-card');
  card.className='modal-card';
  $('boot').classList.add('hidden');

  try{
    const data=await api('GET','/invites/'+encodeURIComponent(token));

    card.innerHTML=`
      <h1>Join <span class="soft">${esc(data.board.name)}</span></h1>
      <p>${data.board.memberCount} ${data.board.memberCount===1?'person is':'people are'} on this board. Joining lets you read and edit every card on it.</p>
      ${data.signedIn
        ? `<button class="acc-btn" id="join-go" style="width:100%;justify-content:center">Join this board</button>`
        : `<button class="oauth-btn" data-provider="google">${PROVIDER_ICON.google}Continue with Google</button>
           <button class="oauth-btn" data-provider="github">${PROVIDER_ICON.github}Continue with GitHub</button>`}`;

    $('modal').classList.remove('hidden');

    card.querySelectorAll('[data-provider]').forEach(b=>b.onclick=()=>{
      location.href='/api/auth/'+b.dataset.provider+'?invite='+encodeURIComponent(token);
    });

    const go=$('join-go');
    if(go)go.onclick=async()=>{
      try{
        const res=await api('POST','/invites/'+encodeURIComponent(token)+'/accept');
        closeModal();
        boards=await loadBoards();
        await switchBoard(res.slug);
        toast('You joined the board');
      }catch(e){ handleApiError(e,'Could not join'); }
    };

  }catch(e){
    card.innerHTML=`
      <h1>That link <span class="soft">will not open.</span></h1>
      <p>${esc(e.message||'The invite is not valid any more.')}</p>
      <button class="acc-btn" id="join-home" style="width:100%;justify-content:center">Go to my boards</button>`;
    $('modal').classList.remove('hidden');
    $('join-home').onclick=()=>{location.href='/';};
  }
}

/* ----------------------------------------------------------------- errors */

function handleApiError(e,prefix){
  if(!e)return;
  if(e.status===0){markOffline();return;}
  if(e.status===401){requireSignIn();return;}
  setLive('err',(prefix?prefix+': ':'')+(e.message||'Error'));
  toast((prefix?prefix+' — ':'')+(e.message||'Something went wrong'));
}

/* -------------------------------------------------------------- start-up */

function routeFromUrl(){
  const m=location.pathname.match(/^\/b\/([a-z0-9-]+)/i);
  if(m)return{kind:'board',slug:m[1]};
  const j=location.pathname.match(/^\/join\/([A-Za-z0-9_-]{22})/);
  if(j)return{kind:'join',token:j[1]};
  return{kind:'default'};
}

/** Open the board named in the URL, else the last one used, else the first. */
async function pickBoard(preferred){
  boards=await loadBoards();

  if(!boards.length){
    // The API bootstraps a first board, so this only happens if that failed.
    requireSignIn('No boards are available for this account.');
    return;
  }

  const remembered=store.get('conotes-last-board');
  const wanted=[preferred,remembered].find(s=>s&&boards.some(b=>b.slug===s))
    || boards[0].slug;

  await switchBoard(wanted);
}

async function init(){
  const route=routeFromUrl();

  // Surface an OAuth failure that bounced us back here.
  const params=new URLSearchParams(location.search);
  const authError=params.get('auth_error');
  if(authError){
    setUrl(location.pathname);
  }

  let session;
  try{
    session=await loadMe();
  }catch(e){
    $('boot').textContent='Cannot reach the server.';
    markOffline();
    return;
  }

  window.__coNotesProviders=session.providers&&session.providers.length?session.providers:['google','github'];
  push.key=session.pushKey||null;
  themes=session.themes&&session.themes.length?session.themes:[];

  if(!session.user){
    if(route.kind==='join'){ await showJoinScreen(route.token); return; }
    requireSignIn(authError?authErrorText(authError):null);
    return;
  }

  me=session.user;

  // Paint the master default straight away, so the app is never briefly lime
  // for someone whose profile says otherwise. The board theme lands moments
  // later when the board loads.
  syncTheme();

  if(route.kind==='join'){
    $('boot').classList.add('hidden');
    await showJoinScreen(route.token);
    return;
  }

  $('boot').classList.add('hidden');
  await pickBoard(route.kind==='board'?route.slug:null);

  // After the board, never before: this waits on the service worker, and the
  // board should not be held up by it. Repaints only if the Profile tab is open.
  syncPushRow();

  /* An installed app is *resumed* rather than reloaded, so boot can be days
   * old by the time someone looks at this row - and permission can have been
   * granted or revoked in system settings in between, where the page gets no
   * event at all. Re-asking on foreground is the only way to notice. */
  document.addEventListener('visibilitychange',()=>{
    if(!document.hidden&&tab==='you')syncPushRow();
  });

  if(authError)toast(authErrorText(authError));
}

/* Every reason OAuth::bounce() can send. Keep this in step with the codes in
 * app/OAuth.php - one missing entry turns a specific, actionable failure into
 * the generic fallback, which is a genuinely painful thing to debug. */
function authErrorText(code){
  const map={
    cancelled:'Sign-in was cancelled.',
    missing_code:'The sign-in provider did not send back a code. Try again.',
    expired_state:'That sign-in attempt timed out. Try again.',
    provider_unconfigured:'That sign-in provider is not set up on this server.',
    token_exchange_failed:'The sign-in provider rejected the request.',
    profile_failed:'The sign-in provider did not return a profile.',
    upstream_failed:'Could not reach the sign-in provider. Check the server can make outbound HTTPS requests.',
    signin_failed:'Sign-in did not complete. Try again.',
  };
  return map[code]||'Sign-in did not complete.';
}

window.addEventListener('online',()=>{markOnline();if(board)tick();});
window.addEventListener('offline',markOffline);

/* The service worker makes the app installable and lets the shell open with no
 * network - see public_html/sw.js for what it will and will not cache.
 *
 * Guarded twice over: workers need a secure context, so this is a no-op on the
 * preview harness (which runs from a file: or data: URL), and register() still
 * rejects on its own for reasons out of our hands - a host without HTTPS, a
 * private window, a browser with workers switched off. None of that should
 * reach the user: the app works without it, it is only slower to start.
 */
if('serviceWorker' in navigator
   && (location.protocol==='https:'||location.hostname==='localhost'||location.hostname==='127.0.0.1')){
  window.addEventListener('load',()=>{
    navigator.serviceWorker.register('/sw.js').catch(unavailable=>{});
  });
}

init();
})();
