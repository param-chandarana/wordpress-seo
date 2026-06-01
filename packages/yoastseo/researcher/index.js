/*
 * Public entry point for the per-language Researcher factory: `require( "yoastseo/researcher" )`.
 *
 * It is deliberately kept out of the package root (`build/index.js`): the root index becomes the
 * plugin's core `analysis` webpack bundle, and resolving the language Researchers through it would
 * inline ~2.4 MB of language data (function words, stemmers, etc.) into core. Exposing the factory as
 * its own shipped entry — like the `vendor`/`images` folders — lets consumers outside the plugin
 * (Node apps, the web worker) reach it via a stable path without deep-requiring `build/...`, while the
 * plugin keeps loading per-language Researchers from their separate `js/dist/languages/<lang>.js` bundles.
 */
module.exports = require( "../build/languageProcessing/getResearcher" ).default;
