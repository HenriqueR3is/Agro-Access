// Queue local simples p/ apontamentos
const QUEUE_KEY = 'apont_queue_v1';

const queue = {
  all() {
    try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }
    catch { return []; }
  },
  save(list) {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(list));
  },
  uid() {
    return 'apont-' + Date.now() + '-' + Math.random().toString(36).slice(2,8);
  },
  push(payload) {
    const q = queue.all();
    if (!payload.client_id) payload.client_id = queue.uid(); // idempotência
    q.push({ payload, ts: Date.now(), tries: 0 });
    queue.save(q);
  },
  async flush() {
    if (!navigator.onLine) return;
    const q = queue.all();
    const remain = [];
    for (const item of q) {
      try {
        const res = await fetch('/api/apontamentos', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify(item.payload)
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        // ok: enviado
      } catch (e) {
        item.tries = (item.tries || 0) + 1;
        // mantém na fila para próxima tentativa
        remain.push(item);
      }
    }
    queue.save(remain);
  }
};

// tenta enviar quando volta conexão e periodicamente
window.addEventListener('online', () => queue.flush());
setInterval(() => queue.flush(), 30_000); // a cada 30s
document.addEventListener('DOMContentLoaded', () => queue.flush()); // ao abrir a tela
