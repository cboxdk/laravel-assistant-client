import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/support.js', import.meta.url), 'utf8');

function mount({ preference, darkClass = false, systemDark = false } = {}) {
  class Element {
    dataset = {};
    nodes = new Map();
    append() {}
    setAttribute() {}
    addEventListener() {}
    attachShadow() { return (this.shadowRoot = new Element()); }
    querySelector(selector) {
      if (!this.nodes.has(selector)) this.nodes.set(selector, new Element());
      return this.nodes.get(selector);
    }
  }
  const listeners = new Set();
  const scheme = {
    matches: systemDark,
    addEventListener: (name, listener) => { assert.equal(name, 'change'); listeners.add(listener); },
    removeEventListener: (name, listener) => { assert.equal(name, 'change'); listeners.delete(listener); },
  };
  const root = { lang: 'da', dataset: { theme: preference }, classList: { contains: () => darkClass } };
  let Support;
  vm.runInNewContext(source, {
    HTMLElement: Element, URL,
    customElements: { get: () => undefined, define: (name, value) => { Support = value; } },
    document: { currentScript: { src: 'https://product.example/ai-assistant/support.js' }, documentElement: root, createElement: () => new Element() },
    window: { addEventListener() {}, removeEventListener() {}, matchMedia: () => scheme },
    MutationObserver: class { observe() {} disconnect() {} },
    setInterval: () => 1, clearInterval() {},
  });
  const element = new Support();
  element.connectedCallback();
  return { element, root, listeners, changeSystem(dark) { scheme.matches = dark; for (const listener of listeners) listener(); } };
}

test('uses the system theme when the host has no explicit preference', () => {
  assert.equal(mount({ systemDark: true }).element.theme, 'dark');
  assert.equal(mount({ systemDark: false }).element.theme, 'light');
});

test('an explicit host theme and dark class take precedence over system preference', () => {
  assert.equal(mount({ preference: 'light', systemDark: true }).element.theme, 'light');
  assert.equal(mount({ preference: 'dark', systemDark: false }).element.theme, 'dark');
  assert.equal(mount({ darkClass: true }).element.theme, 'dark');
});

test('updates an open chat on system-theme changes without reloading its conversation', () => {
  const { element, listeners, changeSystem } = mount();
  const messages = [];
  element.identity = 'test-identity';
  element.origin = 'https://assistant.example';
  element.locale = 'da';
  element.frame = { src: 'https://assistant.example/embed/test', contentWindow: { postMessage: (...args) => messages.push(args) } };
  changeSystem(true);
  assert.equal(element.theme, 'dark');
  assert.equal(element.frame.src, 'https://assistant.example/embed/test');
  assert.equal(messages.at(-1)[0].theme, 'dark');
  element.disconnectedCallback();
  assert.equal(listeners.size, 0);
});

test('system changes retain an explicit light preference', () => {
  const { element, changeSystem } = mount({ preference: 'light' });
  changeSystem(true);
  assert.equal(element.theme, 'light');
});
