/* Shared product support surface. The host serves its authenticated session endpoint.
 * Page intent: set `page-route`, `page-title` and `page-context` (or call `setPage({ route, title,
 * context })`, or dispatch `cbox:page` on window with that detail) and the chat is told which page
 * the person is on, at session start and again on every change. The shared shell's `intent`
 * (attribute as JSON, or property) is the same thing under another name.
 * `ask(question)` opens the chat and sends the question as the person's message; `handover()` opens
 * it and asks for a person. Both wait for the chat to be ready, so nothing typed is lost.
 * A product link in an answer raises a cancelable `cbox-support:navigate` (detail: { url }): a
 * single-page host visits the URL itself and the chat stays open; otherwise the page loads and the
 * chat opens again on the next page.
 * With the `inline` attribute the chat fills the element instead of opening a dialog over the page:
 * (`inline="sheet"` when that is a phone's bottom sheet, so the chat takes touch-sized measures)
 * the host gives the element a size and shows it on `cbox-support:open` (detail: { inline }), hides it
 * on `cbox-support:close` (detail: { reason }, "unavailable" when the chat was lost). `close()` hides
 * the chat and keeps its conversation. */
(() => {
  if (customElements.get('cbox-support')) return;
  const labels = {
    da: ['Spørg Cbox', 'Luk', 'Forbinder…', 'Support kunne ikke åbnes. Prøv igen.'],
    en: ['Ask Cbox', 'Close', 'Connecting…', 'Support could not be opened. Try again.'],
    de: ['Cbox fragen', 'Schließen', 'Verbinden…', 'Support konnte nicht geöffnet werden. Erneut versuchen.'],
    sv: ['Fråga Cbox', 'Stäng', 'Ansluter…', 'Supporten kunde inte öppnas. Försök igen.'],
    nb: ['Spør Cbox', 'Lukk', 'Kobler til…', 'Kunne ikke åpne support. Prøv igjen.'],
    nl: ['Vraag Cbox', 'Sluiten', 'Verbinden…', 'Support kon niet worden geopend. Probeer opnieuw.'],
    fr: ['Demander à Cbox', 'Fermer', 'Connexion…', 'Impossible de contacter le support. Réessayez.'],
    es: ['Pregunta a Cbox', 'Cerrar', 'Conectando…', 'No se pudo abrir el soporte. Inténtalo de nuevo.'],
    it: ['Chiedi a Cbox', 'Chiudi', 'Connessione…', 'Impossibile aprire il supporto. Riprova.'],
  };
  const assets = new URL('.', document.currentScript.src);
  // Set just before a full page load from a link in the chat, so the next page opens it again.
  const REOPEN_KEY = 'cbox-support:reopen', REOPEN_WITHIN_MS = 15000;
  class Support extends HTMLElement {
    static get observedAttributes() { return ['session-url', 'page-route', 'page-title', 'page-context', 'intent']; }
    connectedCallback() {
      if (this.shadowRoot) return;
      this.inlineMode = this.getAttribute('inline') !== null;
      // A host may have set `intent` before this script loaded; hand that value to the setter.
      if (Object.prototype.hasOwnProperty.call(this, 'intent')) { const early = this.intent; delete this.intent; this.intent = early; }
      this.requests = [];
      const shadow = this.attachShadow({ mode: 'open' });
      const stylesheet = document.createElement('link');
      stylesheet.rel = 'stylesheet'; stylesheet.href = new URL('support.css', assets).href;
      shadow.append(stylesheet);
      const content = document.createElement('div');
      content.className = 'support-container';
      content.innerHTML = `<button class="open" type="button"></button><dialog><header><strong></strong><button class="close" type="button">×</button></header><div class="status" role="status"></div></dialog>`;
      shadow.append(content);
      this.button = shadow.querySelector('.open');
      this.button.innerHTML = '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg><span class="launcher-label"></span>';
      this.dialog = shadow.querySelector('dialog');
      this.status = shadow.querySelector('.status');
      this.closeButton = shadow.querySelector('.close');
      this.button.addEventListener('click', () => this.open());
      this.closeButton.addEventListener('click', () => this.close());
      this.dialog.addEventListener('close', () => {
        if (!this.inlineMode) this.button.focus();
        const reason = this.closeReason ?? null; this.closeReason = undefined;
        this.dispatchEvent(new CustomEvent('cbox-support:close', { detail: { reason }, bubbles: true, composed: true }));
      });
      this.onMessage = (ev) => {
        if (!this.frame || ev.source !== this.frame.contentWindow || ev.origin !== this.origin) return;
        if (ev.data?.__aiAssistant === 'ready') {
          this.frameReady = true; this.sendIdentity(); this.flushRequests();
          // Inline, the composer is where the person types next; a dialog keeps focus on its close button.
          if (this.inlineMode && this.dialog.open) this.frame.focus?.();
        }
        if (ev.data?.__aiAssistant === 'close') this.close();
        // Assistant's live portal link (null while the portal is offline) wins over the configured one.
        if (ev.data?.__aiAssistant === 'session' && 'portal_url' in ev.data) this.livePortal(ev.data.portal_url);
        if (ev.data?.__aiAssistant === 'navigate' && typeof ev.data.url === 'string') {
          const next = new URL(ev.data.url, location.origin);
          if (next.origin === location.origin && ['https:', 'http:'].includes(next.protocol)) this.navigate(next.href);
        }
      };
      window.addEventListener('message', this.onMessage);
      this.onPage = (ev) => this.setPage(ev.detail);
      window.addEventListener('cbox:page', this.onPage);
      this.colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
      this.onColorSchemeChange = () => this.presentation();
      this.colorScheme.addEventListener('change', this.onColorSchemeChange);
      this.observer = new MutationObserver(() => this.presentation());
      this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['lang', 'data-theme', 'class'] });
      this.presentation();
      this.timer = setInterval(() => { if (this.frame) this.session().catch(() => this.unavailable()); }, 60000);
      if (this.reopenAfterLoad()) this.open();
    }
    disconnectedCallback() {
      this.observer?.disconnect(); clearInterval(this.timer);
      window.removeEventListener('message', this.onMessage);
      window.removeEventListener('cbox:page', this.onPage);
      this.colorScheme?.removeEventListener('change', this.onColorSchemeChange);
      this.abort?.abort();
    }
    attributeChangedCallback(name, oldValue, newValue) {
      if (oldValue === newValue || !this.shadowRoot) return;
      if (name !== 'session-url') { this.sendPage(); return; }
      this.abort?.abort(); this.frame?.remove(); this.frame = null; this.frameReady = false; this.requests = []; this.identity = null; this.sentPage = undefined; this.livePortalUrl = undefined;
      this.dialog.close();
    }
    language() { const language = (document.documentElement.lang || 'en').split('-')[0]; return language === 'no' ? 'nb' : language; }
    presentation() {
      const root = document.documentElement;
      const preference = root.dataset.theme;
      this.theme = preference === 'dark' || preference === 'light' ? preference
        : root.classList.contains('dark') || this.colorScheme.matches ? 'dark' : 'light';
      this.dataset.theme = this.theme;
      const language = this.language();
      const text = labels[language] || labels.en;
      this.button.querySelector('.launcher-label').textContent = text[0]; this.button.setAttribute('aria-label', text[0]);
      this.shadowRoot.querySelector('strong').textContent = text[0];
      if (this.inlineMode) this.dialog.setAttribute('aria-label', text[0]);
      this.closeButton.setAttribute('aria-label', text[1]);
      if (this.frame) this.frame.title = text[0];
      if (this.frame && this.locale !== language) {
        this.locale = language;
        const url = new URL(this.frame.src); url.searchParams.set('locale', language); url.searchParams.set('theme', this.theme);
        this.frameReady = false;
        this.frame.src = url.href;
      }
      this.sendIdentity();
    }
    async session() {
      const url = new URL(this.getAttribute('session-url'), location.origin);
      if (url.origin !== location.origin) throw new Error('Invalid support session origin');
      this.abort?.abort(); this.abort = new AbortController();
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: this.abort.signal });
      if (!response.ok) throw new Error('Support session unavailable');
      const session = await response.json();
      if (typeof session.url !== 'string' || typeof session.identity !== 'string') throw new Error('Invalid support session');
      const target = new URL(session.url);
      if (target.protocol !== 'https:' && !(target.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(target.hostname))) throw new Error('Invalid support endpoint');
      this.identity = session.identity; this.origin = target.origin;
      // What the shell's Help column reads (all null-safe; the chat works without them).
      this.details = { portal_url: this.livePortalUrl !== undefined ? this.livePortalUrl : (session.portal_url ?? null), updates_url: session.updates_url ?? null, search_url: session.search_url ?? null, topics_url: session.topics_url ?? null, article_url: session.article_url ?? null };
      this.dispatchEvent(new CustomEvent('cbox-support:session', { detail: this.details, bubbles: true, composed: true }));
      this.sendIdentity();
      return target;
    }
    livePortal(value) {
      let portal = null;
      if (typeof value === 'string') {
        try { const url = new URL(value); if (url.protocol === 'https:') portal = url.href; } catch { portal = null; }
      }
      this.livePortalUrl = portal;
      this.details = { ...(this.details || {}), portal_url: portal };
      this.dispatchEvent(new CustomEvent('cbox-support:session', { detail: this.details, bubbles: true, composed: true }));
    }
    /** Opens the chat. Resolves true once its frame exists, false when it could not be opened. */
    open() {
      if (!this.dialog.open) { if (this.inlineMode) this.dialog.show(); else this.dialog.showModal(); }
      // Every call, also when the chat is already open: the host may have shown something else since.
      this.dispatchEvent(new CustomEvent('cbox-support:open', { detail: { inline: this.inlineMode }, bubbles: true, composed: true }));
      if (this.inlineMode) this.frame?.focus?.(); else this.closeButton.focus();
      if (this.frame) return Promise.resolve(true);
      // One connection at a time: a second call while the session is loading joins the first.
      return (this.opening ??= this.connect().finally(() => { this.opening = null; }));
    }
    /** Hides the chat. Its frame stays, so the conversation is there when it opens again. */
    close() { if (this.dialog.open) this.dialog.close(); }
    async connect() {
      this.status.hidden = false;
      this.status.textContent = (labels[this.language()] || labels.en)[2];
      try {
        const target = await this.session();
        if (!this.dialog.open) return false;
        this.locale = this.language();
        target.searchParams.set('locale', this.locale); target.searchParams.set('theme', this.theme);
        target.searchParams.set('embedded', '1');
        // Inline, the chat page wears the shell's own surfaces, field and send button.
        // `inline="sheet"` is a phone's bottom sheet; anything else is a column beside the page.
        if (this.inlineMode) target.searchParams.set('inline', this.getAttribute('inline') === 'sheet' ? 'sheet' : 'column');
        this.frame = document.createElement('iframe');
        this.frame.title = (labels[this.locale] || labels.en)[0];
        this.frame.referrerPolicy = 'strict-origin';
        this.frame.src = target.href;
        this.dialog.append(this.frame);
        this.status.hidden = true;
        return true;
      } catch (error) { if (error.name !== 'AbortError') this.unavailable(); return false; }
    }
    /**
     * Opens the chat and sends `question` as the person's own message, in the conversation they
     * already have. Resolves false when the chat could not be opened, so the host keeps their text.
     */
    ask(question, page) {
      if (page && typeof page === 'object') this.setPage(page);
      const text = typeof question === 'string' ? question.trim().slice(0, 8000) : '';
      if (text) this.request({ __aiAssistant: 'ask', text });
      return this.open();
    }
    /** Opens the chat and asks for a person, the same as its own "Talk to a human" button. */
    handover() {
      this.request({ __aiAssistant: 'handover' });
      return this.open();
    }
    request(message) {
      this.requests.push(message);
      this.flushRequests();
    }
    // The chat frame says `ready` once it listens; until then what the person asked for waits here.
    flushRequests() {
      if (!this.frame || !this.frameReady || !this.identity) return;
      for (const message of this.requests.splice(0)) this.frame.contentWindow?.postMessage(message, this.origin);
    }
    navigate(href) {
      const event = new CustomEvent('cbox-support:navigate', { detail: { url: href }, bubbles: true, composed: true, cancelable: true });
      if (!this.dispatchEvent(event)) return;
      try { sessionStorage.setItem(REOPEN_KEY, String(Date.now())); } catch { /* the page still loads */ }
      location.assign(href);
    }
    reopenAfterLoad() {
      try {
        const at = Number(sessionStorage.getItem(REOPEN_KEY));
        sessionStorage.removeItem(REOPEN_KEY);
        return at > 0 && Date.now() - at < REOPEN_WITHIN_MS;
      } catch { return false; }
    }
    get intent() { return this.pageOverride ?? null; }
    set intent(value) {
      let page = value;
      if (typeof value === 'string') { try { page = JSON.parse(value); } catch { page = null; } }
      this.setPage(page);
    }
    sendIdentity() {
      if (!this.frame || !this.identity) return;
      const page = this.page();
      this.sentPage = JSON.stringify(page);
      this.frame.contentWindow?.postMessage({ __aiAssistant: 'identity', jwt: this.identity, theme: this.theme, locale: this.language(), page }, this.origin);
    }
    page() {
      const limits = { route: 200, title: 200, context: 300 };
      const page = {};
      let intent = null;
      try { intent = JSON.parse(this.getAttribute('intent') || 'null'); } catch { intent = null; }
      for (const key of Object.keys(limits)) {
        const value = (this.pageOverride && typeof this.pageOverride[key] === 'string') ? this.pageOverride[key]
          : this.getAttribute('page-' + key) ?? (intent && typeof intent[key] === 'string' ? intent[key] : null);
        if (typeof value === 'string' && value.trim()) page[key] = value.trim().slice(0, limits[key]);
      }
      return Object.keys(page).length ? page : null;
    }
    setPage(page) {
      this.pageOverride = page && typeof page === 'object' ? { route: page.route, title: page.title, context: page.context } : null;
      this.sendPage();
    }
    sendPage() {
      const page = this.page();
      const key = JSON.stringify(page);
      if (key === this.sentPage) return;
      if (this.frame && this.identity) {
        this.sentPage = key;
        this.frame.contentWindow?.postMessage({ __aiAssistant: 'page', page }, this.origin);
      }
    }
    unavailable() {
      // What was asked is dropped with the frame: `ask()` answers false, and the host keeps the text.
      this.frame?.remove(); this.frame = null; this.frameReady = false; this.requests = []; this.identity = null; this.sentPage = undefined;
      this.status.hidden = false;
      this.status.textContent = (labels[this.language()] || labels.en)[3];
      // Inline, the host owns the way back: it hears why the chat closed and says so beside its own retry.
      if (this.inlineMode && this.dialog.open) { this.closeReason = 'unavailable'; this.dialog.close(); }
    }
  }
  customElements.define('cbox-support', Support);
})();
