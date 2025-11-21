async function submitApontamento(formEl) {
  const data = Object.fromEntries(new FormData(formEl).entries());
  // adapte os campos reais do seu form:
  const payload = {
    talhao_id: Number(data.talhao_id),
    quantidade: Number(data.quantidade),
    turno: data.turno,
    obs: data.obs || '',
    // metadados úteis
    client_time: new Date().toISOString()
  };

  if (!navigator.onLine) {
    queue.push(payload);
    showToast('✅ Salvo offline — será enviado quando a conexão voltar.');
    formEl.reset();
    return;
  }

  try {
    const res = await fetch('/api/apontamentos', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    showToast('✅ Enviado com sucesso!');
    formEl.reset();
  } catch (e) {
    // falhou online? guarda na fila assim mesmo
    queue.push(payload);
    showToast('⚠️ Falha ao enviar — guardado offline para reenvio.');
  }
}

function showToast(msg){
  const t = document.createElement('div');
  t.className = 'toast-top';
  t.textContent = msg;
  document.body.appendChild(t);
  requestAnimationFrame(()=> t.classList.add('show'));
  setTimeout(()=> { t.classList.remove('show'); setTimeout(()=>t.remove(), 200); }, 2400);
}
