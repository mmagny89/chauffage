import { Controller } from '@hotwired/stimulus';

/*
 * Affiche le champ « Nom du lieu » seulement quand « Autre lieu… » est choisi.
 * Sans JavaScript, le champ reste visible.
 */
export default class extends Controller {
    static targets = ['select', 'custom'];

    connect() {
        this.toggle();
    }

    toggle() {
        const other = this.selectTarget.value === '__other__';
        this.customTarget.hidden = !other;
        if (!other) {
            const input = this.customTarget.querySelector('input');
            if (input) {
                input.value = '';
            }
        }
    }
}
