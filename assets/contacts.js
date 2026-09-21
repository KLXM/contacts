/**
 * Web Components des Kontakt-Editors. Ohne Build-Schritt, ohne jQuery – bis auf das Signal "rex:ready",
 * mit dem REDAXO-Addons (etwa der Datumswähler) neu eingefügte Felder aufwerten.
 */
const t = (key, ...args) => (window.rex?.contacts_i18n?.[key] ?? key).replace(/\{(\d+)\}/g, (match, index) => args[Number(index)] ?? match);

const CUSTOM = '__custom__';

class ContactsItems extends HTMLElement {
  connectedCallback() {
    this.rows = this.querySelector('[data-rows]');
    this.template = this.querySelector('template');
    this.next = Number(this.dataset.next) || 0;

    this.addEventListener('click', (event) => {
      if (event.target.closest('[data-add]')) this.add();
      const remove = event.target.closest('[data-remove]');
      if (remove) this.#remove(remove.closest('[data-row]'));
    });
    this.addEventListener('change', (event) => {
      if (event.target.matches('[data-label-select]')) this.#syncCustomLabel(event.target, true);
    });
  }

  /** Neue Zeile; label belegt die Beschriftung vor, etwa für ein eigenes Feld aus den Einstellungen. */
  add(label = null) {
    this.hidden = false;
    const holder = document.createElement('div');
    holder.innerHTML = this.template.innerHTML.replaceAll('__i__', String(this.next++));
    const row = holder.firstElementChild;
    this.rows.append(row);

    const labelInput = row.querySelector('[data-label]');
    if (label !== null && labelInput) labelInput.value = label;
    if (window.jQuery) window.jQuery(document).trigger('rex:ready', [window.jQuery(row)]);
    (label === null && labelInput ? labelInput : row.querySelector('.contacts-item-value input, .contacts-item-value textarea'))?.focus();
  }

  #remove(row) {
    const neighbour = row.nextElementSibling ?? row.previousElementSibling;
    row.remove();
    if (this.rows.children.length === 0) this.hidden = true;
    (neighbour?.querySelector('input, select') ?? this.querySelector('[data-add]'))?.focus();
  }

  #syncCustomLabel(select, focus) {
    const input = select.parentElement.querySelector('[data-custom-label]');
    const custom = select.value === CUSTOM;
    input.hidden = !custom;
    input.required = custom;
    if (custom && focus) input.focus();
  }
}

class ContactsEditor extends HTMLElement {
  connectedCallback() {
    this.addEventListener('click', (event) => {
      const addKind = event.target.closest('[data-add-kind]');
      if (addKind) {
        this.querySelector(`contacts-items[data-kind="${addKind.dataset.addKind}"]`)?.add(addKind.dataset.label ?? null);
        this.#closeMenu();
      }
      const show = event.target.closest('[data-show-section]');
      if (show) {
        const section = this.querySelector(`[data-section="${show.dataset.showSection}"]`);
        section.hidden = false;
        section.querySelector('select, textarea, input')?.focus();
        this.#closeMenu();
      }
    });

    // Freigaben je Eintrag haben nur Sinn, wenn der Kontakt öffentlich ist.
    this.publicToggle = this.querySelector('[data-public-toggle]');
    const syncPublic = () => {
      this.classList.toggle('is-public', this.publicToggle.checked);
      this.querySelector('[data-public-fields]').hidden = !this.publicToggle.checked;
    };
    this.publicToggle?.addEventListener('change', syncPublic);
    if (this.publicToggle) syncPublic();

    this.bookSelect = this.querySelector('[data-book-select]');
    this.bookSelect?.addEventListener('change', () => this.#syncLists());
    this.#syncLists();
  }

  /** Listen gehören zu einem Adressbuch: nur die des gewählten anbieten. */
  #syncLists() {
    if (!this.bookSelect) return;
    let visible = 0;
    for (const label of this.querySelectorAll('.contacts-lists-choice [data-book]')) {
      const match = label.dataset.book === this.bookSelect.value;
      label.hidden = !match;
      const input = label.querySelector('input');
      input.disabled = !match;
      if (!match) input.checked = false;
      if (match) visible++;
    }
    const hint = this.querySelector('[data-no-lists]');
    if (hint) hint.hidden = visible > 0;
  }

  #closeMenu() {
    this.querySelector('.contacts-add-field.open')?.classList.remove('open');
  }
}

/** Sammelaktionen erscheinen erst, wenn Kontakte angehakt sind. */
function initBulk() {
  for (const form of document.querySelectorAll('.contacts-index-form')) {
    const bar = form.querySelector('[data-bulk]');
    if (!bar) continue;
    form.addEventListener('change', () => {
      const count = form.querySelectorAll('input[name="ids[]"]:checked').length;
      bar.hidden = count === 0;
      bar.dataset.count = t('selected', count);
    });
  }
  document.querySelector('.contacts-index-item.is-active')?.scrollIntoView({ block: 'nearest' });
}

/**
 * Kontakte auf eine Liste ziehen. Sind mehrere angehakt und der gezogene gehört dazu, wandern alle.
 * Listen gehören zu einem Adressbuch: Ziele in anderen Adressbüchern nehmen nichts an.
 */
function initDragAndDrop() {
  const app = document.querySelector('.contacts-app[data-api]');
  if (!app) return;
  const TYPE = 'application/x-klxm-contacts';
  let dragged = null;

  app.addEventListener('dragstart', (event) => {
    const item = event.target.closest?.('.contacts-index-item[data-id]');
    if (!item) return;
    const checked = [...app.querySelectorAll('.contacts-index-item input[name="ids[]"]:checked')].map((input) => input.closest('.contacts-index-item'));
    const items = checked.includes(item) ? checked : [item];
    dragged = { ids: items.map((entry) => entry.dataset.id), books: new Set(items.map((entry) => entry.dataset.book)) };
    event.dataTransfer.effectAllowed = 'copy';
    event.dataTransfer.setData(TYPE, dragged.ids.join(','));
    event.dataTransfer.setData('text/plain', items.map((entry) => entry.dataset.name).join(', '));

    const ghost = document.createElement('div');
    ghost.className = 'contacts-drag-ghost';
    ghost.textContent = items.length > 1 ? t('selected', items.length) : item.dataset.name;
    document.body.append(ghost);
    event.dataTransfer.setDragImage(ghost, 12, 12);
    setTimeout(() => ghost.remove());
    items.forEach((entry) => entry.classList.add('is-dragging'));
    app.classList.add('is-dragging');
    for (const target of app.querySelectorAll('[data-drop-list]')) target.classList.toggle('is-drop-disabled', !dragged.books.has(target.dataset.book));
  });

  app.addEventListener('dragend', () => {
    dragged = null;
    app.classList.remove('is-dragging');
    for (const element of app.querySelectorAll('.is-dragging, .is-drop-target, .is-drop-disabled')) element.classList.remove('is-dragging', 'is-drop-target', 'is-drop-disabled');
  });

  const targetOf = (event) => {
    const target = event.target.closest?.('[data-drop-list]');
    return target && dragged?.books.has(target.dataset.book) ? target : null;
  };
  app.addEventListener('dragover', (event) => {
    const target = targetOf(event);
    if (!target) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
    target.classList.add('is-drop-target');
  });
  app.addEventListener('dragleave', (event) => event.target.closest?.('[data-drop-list]')?.classList.remove('is-drop-target'));

  app.addEventListener('drop', async (event) => {
    const target = targetOf(event);
    if (!target) return;
    event.preventDefault();
    target.classList.remove('is-drop-target');
    const body = new FormData();
    body.set('_csrf_token', app.dataset.token);
    body.set('list', target.dataset.dropList);
    dragged.ids.forEach((id) => body.append('ids[]', id));
    try {
      const response = await fetch(app.dataset.api, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(t('drop_failed'));
      const result = await response.json();
      target.querySelector('.contacts-nav-count').textContent = result.count;
      if (result.added > 0) {
        target.classList.add('is-drop-done');
        setTimeout(() => target.classList.remove('is-drop-done'), 900);
      }
      toast(result.message, result.ok ? 'info' : 'error');
    } catch (error) {
      toast(error.message, 'error');
    }
  });
}

function toast(message, type = 'info') {
  let region = document.querySelector('.contacts-toasts');
  if (!region) {
    region = document.createElement('div');
    region.className = 'contacts-toasts';
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    document.body.append(region);
  }
  const item = document.createElement('div');
  item.className = `contacts-toast contacts-toast-${type}`;
  item.textContent = message;
  region.append(item);
  setTimeout(() => item.remove(), type === 'error' ? 7000 : 3500);
}

customElements.define('contacts-items', ContactsItems);
customElements.define('contacts-editor', ContactsEditor);
const init = () => { initBulk(); initDragAndDrop(); };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
