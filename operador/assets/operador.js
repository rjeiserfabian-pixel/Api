// Panel del operador. Sin scripts en linea: la politica de seguridad solo permite este archivo.
(function () {
  'use strict';
  // Registrar un pago: al elegir plan o periodo se propone el monto segun los precios del plan (editable).
  var f = document.getElementById('form-pago');
  if (!f) { return; }
  var plan = f.querySelector('[name=plan_id]');
  var per = f.querySelector('[name=periodo]');
  var monto = f.querySelector('[name=monto]');
  if (!plan || !per || !monto) { return; }
  var proponer = function () {
    var o = plan.options[plan.selectedIndex];
    var v = o ? o.getAttribute(per.value === 'anual' ? 'data-a' : 'data-m') : null;
    if (v) { monto.value = v; }
  };
  plan.addEventListener('change', proponer);
  per.addEventListener('change', proponer);
})();
