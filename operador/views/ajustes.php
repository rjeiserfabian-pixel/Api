<h1>Ajustes de cobro</h1>
<form class="tarjeta" method="post" action="<?= h(Vista::url('/ajustes/guardar')) ?>">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
  <h2 class="sin-margen">Vencimientos</h2>
  <label for="g">Días de gracia después de vencer (0 a 60)</label>
  <input id="g" type="text" name="dias_gracia" value="<?= h($a['dias_gracia']) ?>" required inputmode="numeric" maxlength="2">
  <div class="ayuda">Durante la gracia el cliente conserva su plan y ve un aviso rojo. Las pruebas gratis no tienen gracia.</div>
  <label for="av">Avisar cuántos días antes de vencer (1 a 90)</label>
  <input id="av" type="text" name="dias_aviso" value="<?= h($a['dias_aviso']) ?>" required inputmode="numeric" maxlength="2">
  <label for="d">Plan al que pasa un cliente cuando vence del todo</label>
  <select id="d" name="plan_vencido_id">
    <option value="" <?= $a['plan_vencido_id'] === '' ? 'selected' : '' ?>>Ninguno (el plan no se cambia: solo se marca vencida)</option>
    <?php foreach ($planes as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (string) $a['plan_vencido_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option><?php endforeach; ?>
  </select>
  <div class="ayuda">Recomendado: su plan gratuito o de pruebas (por ejemplo “Prueba”). Si no elige ninguno, el cliente vencido sigue con su plan hasta que usted lo cambie; nunca queda sin límites.</div>
  <h2>Cómo pagar (se muestra a los clientes en su portal)</h2>
  <label for="i">Instrucciones de pago</label>
  <textarea id="i" name="instrucciones_pago" rows="4" maxlength="600" placeholder="Ej. Transferencia al BCP cuenta 000-0000000-0-00 a nombre de ... o Yape al 999 999 999. Envíe el comprobante a pagos@sudominio.com"><?= h($a['instrucciones_pago']) ?></textarea>
  <div class="ayuda">Hasta que conecte una pasarela de pago, aquí indica cómo le pagan. Máximo 600 caracteres.</div>
  <p><button type="submit">Guardar ajustes</button></p>
</form>
<p class="ayuda">Quién cobra y cuándo: el worker revisa los vencimientos en cada ciclo; si está detenido, el panel también los actualiza al abrir Resumen o Cobros.</p>
