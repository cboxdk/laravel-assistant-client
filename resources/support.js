/* Shared product support surface. The host serves its authenticated session endpoint.
 * Page intent: set `page-route`, `page-title` and `page-context` (or call `setPage({ route, title,
 * context })`, or dispatch `cbox:page` on window with that detail) and the chat is told which page
 * the person is on, at session start and again on every change. */
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
  class Support extends HTMLElement {
    static get observedAttributes() { return ['session-url', 'page-route', 'page-title', 'page-context']; }
    connectedCallback() {
      if (this.shadowRoot) return;
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
      this.closeButton.addEventListener('click', () => this.dialog.close());
      this.dialog.addEventListener('close', () => this.button.focus());
      this.onMessage = (ev) => {
        if (!this.frame || ev.source !== this.frame.contentWindow || ev.origin !== this.origin) return;
        if (ev.data?.__aiAssistant === 'ready') this.sendIdentity();
        if (ev.data?.__aiAssistant === 'close') this.dialog.close();
        // Assistant's live portal link (null while the portal is offline) wins over the configured one.
        if (ev.data?.__aiAssistant === 'session' && 'portal_url' in ev.data) this.livePortal(ev.data.portal_url);
        if (ev.data?.__aiAssistant === 'navigate' && typeof ev.data.url === 'string') {
          const next = new URL(ev.data.url, location.origin);
          if (next.origin === location.origin && ['https:', 'http:'].includes(next.protocol)) location.assign(next.href);
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
      this.abort?.abort(); this.frame?.remove(); this.frame = null; this.identity = null; this.sentPage = undefined; this.livePortalUrl = undefined;
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
      this.closeButton.setAttribute('aria-label', text[1]);
      if (this.frame) this.frame.title = text[0];
      if (this.frame && this.locale !== language) {
        this.locale = language;
        const url = new URL(this.frame.src); url.searchParams.set('locale', language); url.searchParams.set('theme', this.theme);
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
    async open() {
      this.dialog.showModal();
      this.closeButton.focus();
      if (this.frame) return;
      this.status.hidden = false;
      this.status.textContent = (labels[this.language()] || labels.en)[2];
      try {
        const target = await this.session();
        if (!this.dialog.open) return;
        this.locale = this.language();
        target.searchParams.set('locale', this.locale); target.searchParams.set('theme', this.theme);
        target.searchParams.set('embedded', '1');
        this.frame = document.createElement('iframe');
        this.frame.title = (labels[this.locale] || labels.en)[0];
        this.frame.referrerPolicy = 'strict-origin';
        this.frame.src = target.href;
        this.dialog.append(this.frame);
        this.status.hidden = true;
      } catch (error) { if (error.name !== 'AbortError') this.unavailable(); }
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
      for (const key of Object.keys(limits)) {
        const value = (this.pageOverride && typeof this.pageOverride[key] === 'string') ? this.pageOverride[key] : this.getAttribute('page-' + key);
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
      this.frame?.remove(); this.frame = null; this.identity = null; this.sentPage = undefined;
      this.status.hidden = false;
      this.status.textContent = (labels[this.language()] || labels.en)[3];
    }
  }
  customElements.define('cbox-support', Support);
})();
