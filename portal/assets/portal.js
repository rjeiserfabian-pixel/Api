// Portal de clientes. Sin scripts en linea (la politica de seguridad del portal solo permite este archivo).
(function () {
  'use strict';
  // Confirmar acciones destructivas: <form data-confirmar="Mensaje">
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.getAttribute && f.getAttribute('data-confirmar') && !window.confirm(f.getAttribute('data-confirmar'))) {
      e.preventDefault();
    }
  });
  // Copiar un valor: <button data-copiar="#idDelElemento">
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-copiar]') : null;
    if (!b) { return; }
    var el = document.querySelector(b.getAttribute('data-copiar'));
    if (!el) { return; }
    var texto = el.textContent.trim();
    var listo = function () { var t = b.textContent; b.textContent = 'Copiado'; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(listo);
    } else {
      var r = document.createRange(); r.selectNodeContents(el);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      try { document.execCommand('copy'); listo(); } catch (x) { /* el usuario puede copiar a mano */ }
    }
  });
})();
