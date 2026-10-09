import { Controller } from '@hotwired/stimulus';

/*
 * Collection de formulaires : ajoute et retire des lignes à partir du prototype
 * Symfony (data-prototype, où __name__ désigne l'index).
 */
export default class extends Controller {
    static targets = ['list', 'item'];
    static values = { prototype: String, index: Number };

    add() {
        const html = this.prototypeValue.replace(/__name__/g, this.indexValue);
        this.indexValue += 1;

        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const item = holder.firstElementChild;
        this.listTarget.append(item);
        this.refresh();
        item.querySelector('input')?.focus();
    }

    remove(event) {
        const item = event.target.closest('[data-collection-target="item"]');
        if (item && this.itemTargets.length > 1) {
            item.remove();
            this.refresh();
        }
    }

    refresh() {
        const items = this.itemTargets;
        items.forEach((item, position) => {
            const title = item.querySelector('[data-collection-title]');
            if (title) {
                title.textContent = `Relevé ${position + 1}`;
            }
            const button = item.querySelector('[data-collection-remove]');
            if (button) {
                button.hidden = items.length === 1;
            }
        });
    }

    connect() {
        this.refresh();
    }
}
