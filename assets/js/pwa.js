(function () {
  if (!('serviceWorker' in navigator)) {
    return;
  }

  var script = document.currentScript || document.querySelector('script[src$="assets/js/pwa.js"], script[src$="../assets/js/pwa.js"]');
  var scriptHref = script && script.src ? script.src : new URL('/assets/js/pwa.js', window.location.origin).href;

  window.addEventListener('load', function () {
    var swUrl = new URL('../../sw.js', scriptHref);
    var scopeUrl = new URL('../../', scriptHref);

    navigator.serviceWorker.register(swUrl.href, { scope: scopeUrl.pathname })
      .catch(function (error) {
        console.warn('PWA service worker registration failed:', error);
      });
  });
})();
