// Sitio publico. Sin scripts en linea: la politica de seguridad solo permite este archivo.
(function () {
  'use strict';

  // Copiar un ejemplo de codigo: <button data-copiar="#idDelBloque">
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-copiar]') : null;
    if (!b) { return; }
    var el = document.querySelector(b.getAttribute('data-copiar'));
    if (!el) { return; }
    var texto = el.textContent;
    var listo = function () { var t = b.textContent; b.textContent = 'Copiado'; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(listo, function () {});
    }
  });

  // Formulario de contacto: se envia sin recargar la pagina. Si algo falla, se envia de la forma normal.
  var form = document.getElementById('form-contacto');
  var estado = document.getElementById('contacto-estado');
  if (form && window.fetch && window.FormData) {
    var mostrar = function (clase, html) {
      estado.textContent = '';
      var d = document.createElement('div');
      d.className = 'aviso ' + clase;
      d.innerHTML = html; // solo textos fijos de este archivo (nunca datos del usuario)
      estado.appendChild(d);
      d.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var boton = form.querySelector('button[type=submit]');
      var textoBoton = boton.textContent;
      boton.disabled = true;
      boton.textContent = 'Enviando…';
      var sinErrores = function () {
        var m = form.querySelectorAll('.campo-error'); for (var i = 0; i < m.length; i++) { m[i].parentNode.removeChild(m[i]); }
        var inv = form.querySelectorAll('[aria-invalid]'); for (var j = 0; j < inv.length; j++) { inv[j].removeAttribute('aria-invalid'); }
      };
      sinErrores();
      fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
        .then(function (r) {
          boton.disabled = false;
          boton.textContent = textoBoton;
          if (r.json && r.json.ok) {
            form.reset();
            mostrar('aviso-ok', '<strong>¡Gracias!</strong> Recibimos su solicitud. Le responderemos al correo que nos indicó.');
            return;
          }
          var errs = (r.json && r.json.errores) || {};
          var hay = false;
          Object.keys(errs).forEach(function (campo) {
            var input = document.getElementById(campo);
            if (input) {
              hay = true;
              input.setAttribute('aria-invalid', 'true');
              var p = document.createElement('p');
              p.className = 'campo-error';
              p.id = 'err-' + campo;
              p.textContent = errs[campo];
              input.parentNode.appendChild(p);
            }
          });
          mostrar('aviso-error', errs.general ? '' : '<strong>Revise el formulario.</strong> Hay datos por corregir.');
          if (errs.general) { estado.firstChild.textContent = errs.general; }
          if (!hay && !errs.general) { estado.firstChild.textContent = 'No se pudo enviar. Intente de nuevo.'; }
        })
        .catch(function () { form.submit(); });
    });
  }
})();
