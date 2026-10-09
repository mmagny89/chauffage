import './stimulus_bootstrap.js';
/*
 * Point d'entrée JavaScript, chargé par importmap() dans base.html.twig.
 *
 * La feuille de style n'est volontairement PAS importée ici : AssetMapper convertirait
 * cet import en module « data: » injectant le CSS à l'exécution, que la CSP interdit
 * (script-src sans data:) et qui ferait échouer tout le JavaScript. Elle est chargée par
 * une balise <link> dans base.html.twig.
 */

