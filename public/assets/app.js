'use strict';
const chat = document.querySelector('#chat-messages');
if (chat) {
  const status = document.querySelector('#chat-status');
  let after = 0;
  async function poll() {
    try {
      const response = await fetch(`/api/messages?id=${chat.dataset.conversation}&after=${after}`, { headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Kunne ikkje hente meldingar.');
      const atBottom = chat.scrollHeight - chat.scrollTop - chat.clientHeight < 100;
      for (const m of data.messages) {
        const item = document.createElement('article');
        item.className = `bubble${m.own ? ' own' : ''}`;
        const body = document.createElement('p');
        body.textContent = m.body;
        item.append(body);
        if (m.image) {
          const link = document.createElement('a');
          link.href = m.image;
          const img = document.createElement('img');
          img.src = m.image;
          img.alt = 'Vedlagt bilete';
          img.addEventListener('load', () => { if (atBottom) chat.scrollTop = chat.scrollHeight; });
          link.append(img);
          item.append(link);
        }
        const time = document.createElement('small');
        time.textContent = m.time;
        item.append(time);
        chat.append(item);
        after = m.id;
      }
      if (atBottom) chat.scrollTop = chat.scrollHeight;
      status.textContent = 'Meldingar blir oppdaterte automatisk.';
      if (data.messages.length === 100) { setTimeout(poll, 50); return; }
    } catch (error) {
      status.textContent = `${error.message} Prøver att om litt.`;
    }
    setTimeout(poll, document.hidden ? 15000 : 4000);
  }
  poll();
}
