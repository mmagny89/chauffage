import { Controller } from '@hotwired/stimulus';

/*
 * Menu repliable sur petit écran. Amélioration progressive : sans JavaScript, le menu est
 * toujours visible (empilé) et le bouton reste caché. Avec JavaScript, sous le point de rupture
 * « lg » (1024 px), le menu est replié et le bouton l'ouvre ; au-delà, il est toujours déployé.
 * Échap et un clic à l'extérieur le referment ; le focus revient alors sur le bouton.
 */
export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this.media = window.matchMedia('(min-width: 1024px)');
        this.onMediaChange = () => this.apply();
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.isOpen() && !this.media.matches) {
                this.close();
                this.buttonTarget.focus();
            }
        };
        this.onClick = (event) => {
            if (this.isOpen() && !this.media.matches && !this.element.contains(event.target)) {
                this.close();
            }
        };

        this.media.addEventListener('change', this.onMediaChange);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('click', this.onClick);
        this.apply();
    }

    disconnect() {
        this.media.removeEventListener('change', this.onMediaChange);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('click', this.onClick);
    }

    toggle() {
        if (this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.panelTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.panelTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    isOpen() {
        return !this.panelTarget.hidden;
    }

    apply() {
        if (this.media.matches) {
            // Grand écran : menu déployé, bouton inutile.
            this.buttonTarget.hidden = true;
            this.open();
        } else {
            this.buttonTarget.hidden = false;
            this.close();
        }
    }
}
