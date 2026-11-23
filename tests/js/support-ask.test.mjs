import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/support.js', import.meta.url), 'utf8');
const FRAME_ORIGIN = 'https://assistant.example';
const tick = () => new Promise(resolve => setImmediate(resolve));

/** The element in a stand-in page: a dialog that opens and closes, a session endpoint and a chat frame. */
function mount({ attributes = {}, session = { url: FRAME_ORIGIN + '/embed/w', identity: 'identity-jwt' }, storage = new Map() } = {}) {
  const posted = [], events = [], assigned = [], fetches = [];
  class Element {
    dataset = {};
    nodes = new Map();
    listeners = new Map();
    attributes = { ...attributes };
    contentWindow = { postMessage: (message, origin) => posted.push([message, origin]) };
    append() {}
    remove() {}
    focus() {}
    hasAttribute(name) { return name in this.attributes; }
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name] ?? null; }
    addEventListener(name, listener) { this.listeners.set(name, listener); }
    dispatchEvent(event) { events.push(event); return !event.defaultPrevented; }
    attachShadow() { return (this.shadowRoot = new Node()); }
    querySelector(selector) {
      if (!this.nodes.has(selector)) this.nodes.set(selector, new Node());
      return this.nodes.get(selector);
    }
  }
  // What the element builds inside itself; the dialog among them opens and closes.
  class Node extends Element {
    open = false;
    shown = null;
    showModal() { this.open = true; this.shown = 'modal'; }
    show() { this.open = true; this.shown = 'inline'; }
    close() { if (!this.open) return; this.open = false; this.listeners.get('close')?.(); }
  }
  const windowListeners = new Map();
  let Support;
  vm.runInNewContext(source, {
    HTMLElement: Element, URL, Promise, AbortController,
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init?.detail; this.cancelable = init?.cancelable === true; } preventDefault() { if (this.cancelable) this.defaultPrevented = true; } },
    customElements: { get: () => undefined, define: (name, value) => { Support = value; } },
    document: { currentScript: { src: 'https://product.example/ai-assistant/support.js' }, documentElement: { lang: 'en', dataset: {}, classList: { contains: () => false } }, createElement: () => new Node() },
    window: { addEventListener: (name, listener) => windowListeners.set(name, listener), removeEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) },
    location: { origin: 'https://product.example', assign: href => assigned.push(href) },
    sessionStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
    fetch: async url => { fetches.push(String(url)); return session === null ? { ok: false } : { ok: true, json: async () => session }; },
    MutationObserver: class { observe() {} disconnect() {} },
    setInterval: () => 1, clearInterval() {},
  });
  const element = new Support();
  element.setAttribute('session-url', '/support/session');
  element.connectedCallback();
  const fromFrame = data => windowListeners.get('message')({ source: element.frame.contentWindow, origin: FRAME_ORIGIN, data });
  const sent = kind => posted.filter(([message]) => message.__aiAssistant === kind);
  return { element, posted, sent, events, assigned, fetches, storage, fromFrame };
}

test('ask() sends the question as the person\'s message once the chat is ready, never before', async () => {
  const { element, sent, fromFrame } = mount();
  const opened = element.ask('  How do I add email?  ', { route: 'inbox.index', title: 'Inbox' });
  assert.equal(await opened, true);
  assert.equal(element.dialog.shown, 'modal');
  // The frame exists but has not said it listens: a message now would be lost.
  assert.equal(sent('ask').length, 0);

  fromFrame({ __aiAssistant: 'ready' });
  assert.deepEqual(sent('identity').map(([message]) => ({ ...message.page })), [{ route: 'inbox.index', title: 'Inbox' }]);
  assert.deepEqual(sent('ask').map(([message, origin]) => [message.text, origin]), [['How do I add email?', FRAME_ORIGIN]]);

  // A second question goes straight into the same conversation, once.
  assert.equal(await element.ask('And a signature?'), true);
  fromFrame({ __aiAssistant: 'ready' });
  assert.deepEqual(sent('ask').map(([message]) => message.text), ['How do I add email?', 'And a signature?']);
});

test('ask() answers false and sends nothing when the chat cannot be opened', async () => {
  const { element, posted } = mount({ session: null });
  assert.equal(await element.ask('Lost?'), false);
  assert.equal(posted.length, 0);
  assert.equal(element.requests.length, 0);
});

test('handover() asks for a person in the open chat', async () => {
  const { element, sent, fromFrame } = mount();
  assert.equal(await element.handover(), true);
  fromFrame({ __aiAssistant: 'ready' });
  assert.equal(sent('handover').length, 1);
  assert.equal(sent('ask').length, 0);
});

test('two calls while the session loads share one connection', async () => {
  const { element, fetches } = mount();
  const [first, second] = await Promise.all([element.open(), element.ask('Hello')]);
  assert.deepEqual([first, second], [true, true]);
  assert.equal(fetches.length, 1);
});

test('inline: the chat fills the element, and the host hears it open, close and fail', async () => {
  const { element, events } = mount({ attributes: { inline: '' } });
  assert.equal(await element.open(), true);
  assert.equal(element.dialog.shown, 'inline');
  assert.deepEqual({ ...events.find(event => event.type === 'cbox-support:open').detail }, { inline: true });
  // The chat page is told it sits inside the host, so it wears the host's look.
  assert.equal(new URL(element.frame.src).searchParams.get('inline'), 'column');

  element.close();
  assert.equal(events.at(-1).type, 'cbox-support:close');
  assert.equal(events.at(-1).detail.reason, null);
  // Closing hides the chat; the conversation's frame stays for the next open.
  assert.ok(element.frame);
  assert.equal(await element.open(), true);

  element.unavailable();
  assert.equal(element.dialog.open, false);
  assert.equal(events.at(-1).detail.reason, 'unavailable');
});

test('a product link is the host\'s to follow; unhandled, the page loads and the chat reopens', async () => {
  const handled = mount();
  await handled.element.open();
  handled.element.dispatchEvent = event => { handled.events.push(event); if (event.type === 'cbox-support:navigate') event.preventDefault(); return !event.defaultPrevented; };
  handled.fromFrame({ __aiAssistant: 'navigate', url: 'https://product.example/orgs/cbox/deliver/integrations' });
  assert.equal(handled.events.at(-1).detail.url, 'https://product.example/orgs/cbox/deliver/integrations');
  assert.equal(handled.assigned.length, 0);
  assert.equal(handled.storage.size, 0);
  assert.equal(handled.element.dialog.open, true);

  // Another origin is never followed.
  handled.fromFrame({ __aiAssistant: 'navigate', url: 'https://evil.example/' });
  assert.equal(handled.assigned.length, 0);

  const plain = mount();
  await plain.element.open();
  plain.fromFrame({ __aiAssistant: 'navigate', url: '/orgs/cbox/deliver/integrations' });
  assert.deepEqual(plain.assigned, ['https://product.example/orgs/cbox/deliver/integrations']);

  // The next page: the chat opens by itself, once.
  const next = mount({ storage: plain.storage });
  await tick();
  assert.equal(next.element.dialog.open, true);
  assert.equal(next.storage.size, 0);
  const later = mount({ storage: next.storage });
  await tick();
  assert.equal(later.element.dialog.open, false);
});

test('the shell\'s intent, as an attribute or a property, is the page the chat is told about', async () => {
  const { element, sent, fromFrame } = mount({ attributes: { intent: JSON.stringify({ route: 'inbox.index', title: 'Inbox', context: 'Cbox', url: '/orgs/cbox/inbox' }) } });
  await element.open();
  fromFrame({ __aiAssistant: 'ready' });
  assert.deepEqual({ ...sent('identity')[0][0].page }, { route: 'inbox.index', title: 'Inbox', context: 'Cbox' });

  element.intent = { route: 'deliver.integrations', title: 'Integrations', context: 'Cbox' };
  assert.deepEqual({ ...sent('page').at(-1)[0].page }, { route: 'deliver.integrations', title: 'Integrations', context: 'Cbox' });
});
