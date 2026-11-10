import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/support.js', import.meta.url), 'utf8');

function mount(attributes = {}) {
  class Element {
    dataset = {};
    nodes = new Map();
    attributes = { ...attributes };
    append() {}
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name] ?? null; }
    addEventListener() {}
    dispatchEvent() { return true; }
    attachShadow() { return (this.shadowRoot = new Element()); }
    querySelector(selector) {
      if (!this.nodes.has(selector)) this.nodes.set(selector, new Element());
      return this.nodes.get(selector);
    }
  }
  const windowListeners = new Map();
  const scheme = { matches: false, addEventListener() {}, removeEventListener() {} };
  let Support;
  vm.runInNewContext(source, {
    HTMLElement: Element, URL,
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init?.detail; } },
    customElements: { get: () => undefined, define: (name, value) => { Support = value; } },
    document: { currentScript: { src: 'https://product.example/ai-assistant/support.js' }, documentElement: { lang: 'en', dataset: {}, classList: { contains: () => false } }, createElement: () => new Element() },
    window: {
      addEventListener: (name, listener) => windowListeners.set(name, listener),
      removeEventListener: (name) => windowListeners.delete(name),
      matchMedia: () => scheme,
    },
    MutationObserver: class { observe() {} disconnect() {} },
    setInterval: () => 1, clearInterval() {},
  });
  const element = new Support();
  element.connectedCallback();
  const messages = [];
  const open = () => {
    element.identity = 'identity-jwt';
    element.origin = 'https://assistant.example';
    element.frame = { src: 'https://assistant.example/embed/w', contentWindow: { postMessage: (...args) => messages.push(args) } };
  };
  return { element, messages, open, windowListeners };
}

test('sends the page intent with the identity when the chat starts', () => {
  const { element, messages, open } = mount({ 'page-route': 'invoices.index', 'page-title': 'Invoices', 'page-context': 'Invoices · Cbox / Webshop' });
  open();
  element.sendIdentity();
  const [payload, origin] = messages.at(-1);
  assert.equal(origin, 'https://assistant.example');
  assert.equal(payload.__aiAssistant, 'identity');
  assert.deepEqual({ ...payload.page }, { route: 'invoices.index', title: 'Invoices', context: 'Invoices · Cbox / Webshop' });
});

test('forwards a changed page on navigation, once per change', () => {
  const { element, messages, open } = mount({ 'page-route': 'invoices.index', 'page-title': 'Invoices' });
  open();
  element.sendIdentity();
  element.setAttribute('page-route', 'invoices.show');
  element.setAttribute('page-title', 'Invoice 42');
  element.attributeChangedCallback('page-title', 'Invoices', 'Invoice 42');
  element.attributeChangedCallback('page-title', 'Invoice 42', 'Invoice 42 ');
  const pages = messages.filter(([m]) => m.__aiAssistant === 'page');
  assert.equal(pages.length, 1);
  assert.deepEqual({ ...pages[0][0].page }, { route: 'invoices.show', title: 'Invoice 42' });
  // Page attributes never tear down the open conversation (only session-url does).
  assert.ok(element.frame);
});

test('setPage and the cbox:page event drive the intent without attributes', () => {
  const { element, messages, open, windowListeners } = mount();
  open();
  element.setPage({ route: 'deployments.index', title: 'Deployments', context: 'x'.repeat(400) });
  windowListeners.get('cbox:page')({ detail: { route: 'deployments.show', title: 'Deploy #9' } });
  const pages = messages.filter(([m]) => m.__aiAssistant === 'page').map(([m]) => ({ ...m.page }));
  assert.equal(pages[0].context.length, 300);
  assert.deepEqual(pages[1], { route: 'deployments.show', title: 'Deploy #9' });
});

test('does not post a page before the chat frame exists', () => {
  const { element, messages } = mount({ 'page-route': 'invoices.index' });
  element.attributeChangedCallback('page-route', null, 'invoices.index');
  assert.equal(messages.length, 0);
});

test('the live portal link from Assistant replaces the configured one', () => {
  const { element, open, windowListeners } = mount();
  const events = [];
  element.dispatchEvent = (event) => { events.push(event); return true; };
  open();
  element.details = { portal_url: 'https://configured.example', updates_url: null, search_url: null, topics_url: null };
  const message = windowListeners.get('message');

  // Only the chat frame's own origin is heard.
  message({ source: {}, origin: 'https://assistant.example', data: { __aiAssistant: 'session', portal_url: null } });
  message({ source: element.frame.contentWindow, origin: 'https://evil.example', data: { __aiAssistant: 'session', portal_url: null } });
  assert.equal(events.length, 0);

  message({ source: element.frame.contentWindow, origin: 'https://assistant.example', data: { __aiAssistant: 'session', portal_url: 'https://support.example/' } });
  assert.equal(events.at(-1).type, 'cbox-support:session');
  assert.equal(events.at(-1).detail.portal_url, 'https://support.example/');

  // Offline (null) and anything that is not an https URL hide the link.
  message({ source: element.frame.contentWindow, origin: 'https://assistant.example', data: { __aiAssistant: 'session', portal_url: null } });
  assert.equal(events.at(-1).detail.portal_url, null);
  message({ source: element.frame.contentWindow, origin: 'https://assistant.example', data: { __aiAssistant: 'session', portal_url: 'javascript:alert(1)' } });
  assert.equal(events.at(-1).detail.portal_url, null);
  assert.equal(element.livePortalUrl, null);
});
