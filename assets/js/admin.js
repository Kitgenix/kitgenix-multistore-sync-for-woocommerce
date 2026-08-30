(() => {
  'use strict';
  document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-kitgenix-multistore-sync-for-woocommerce_confirm]');
    if (target && !window.confirm(target.getAttribute('data-kitgenix-multistore-sync-for-woocommerce_confirm'))) event.preventDefault();
    const all = event.target.closest('[data-kitgenix-multistore-sync-for-woocommerce_check-all]');
    if (all) document.querySelectorAll('input[name="network_ids[]"]').forEach((box) => { box.checked = all.checked; });
  });
})();

/**
 * Bulk Operations live progress: while a job is running, polls the job's
 * state as JSON every ~1.75s and patches the existing DOM in place - no
 * whole-page reload while running, and each poll also nudges the next batch
 * forward server-side (BulkJob::poke()) so progress doesn't wait on a page
 * reload to give WP-Cron/Action Scheduler a chance to run. A single reload
 * happens only once, at the moment the job actually stops running (paused/
 * completed/stopped), to swap in the buttons/forms for that new state.
 */
(() => {
  'use strict';

  const card = document.getElementById('kitgenix-bulk-operations');
  const pollUrl = card && card.getAttribute('data-kitgenix-bulk-poll-url');
  const pollNonce = card && card.getAttribute('data-kitgenix-bulk-poll-nonce');
  if (!card || !pollUrl || !pollNonce) return;

  const byId = (id) => document.getElementById(id);
  const setText = (id, text) => { const el = byId(id); if (el) el.textContent = text; };

  const renderErrors = (errors) => {
    const wrap = byId('kitgenix-bulk-errors-wrap');
    if (!wrap || !Array.isArray(errors) || !errors.length) return;

    let body = byId('kitgenix-bulk-errors-body');
    let count = byId('kitgenix-bulk-errors-count');

    if (!body) {
      wrap.textContent = '';
      const details = document.createElement('details');
      details.style.marginBottom = '16px';
      const summary = document.createElement('summary');
      summary.className = 'button';
      count = document.createElement('span');
      count.id = 'kitgenix-bulk-errors-count';
      summary.appendChild(count);
      summary.appendChild(document.createTextNode(' error(s) – view'));
      details.appendChild(summary);
      const tableWrap = document.createElement('div');
      tableWrap.className = 'kitgenix-table-wrap';
      const table = document.createElement('table');
      table.className = 'kitgenix-table';
      body = document.createElement('tbody');
      body.id = 'kitgenix-bulk-errors-body';
      table.appendChild(body);
      tableWrap.appendChild(table);
      details.appendChild(tableWrap);
      wrap.appendChild(details);
    }

    if (count) count.textContent = String(errors.length);
    body.textContent = '';
    errors.slice(-20).forEach((err) => {
      const tr = document.createElement('tr');
      const tdTime = document.createElement('td');
      tdTime.textContent = new Date(err.time * 1000).toISOString().slice(0, 19).replace('T', ' ');
      const tdMsg = document.createElement('td');
      tdMsg.textContent = err.message;
      tr.appendChild(tdTime);
      tr.appendChild(tdMsg);
      body.appendChild(tr);
    });
  };

  const applyState = (job) => {
    setText('kitgenix-bulk-processed', job.processed + ' / ' + job.total);
    setText('kitgenix-bulk-repaired', job.repaired + ' / ' + job.excluded);
    setText('kitgenix-bulk-drift', job.drift + ' drift detected');
    setText('kitgenix-bulk-status-sub', job.percent + '% complete');

    const badge = byId('kitgenix-bulk-status-badge');
    if (badge) {
      badge.textContent = job.status.charAt(0).toUpperCase() + job.status.slice(1);
      const tone = job.status === 'running' ? 'warning' : (job.status === 'completed' ? 'success' : 'neutral');
      badge.className = 'kitgenix-badge kitgenix-badge-dot ' + tone;
    }

    const fill = byId('kitgenix-bulk-progressbar-fill');
    const bar = byId('kitgenix-bulk-progressbar');
    if (bar) bar.setAttribute('aria-valuenow', String(job.percent));
    if (fill) fill.style.width = job.percent + '%';

    const currentWrap = byId('kitgenix-bulk-current');
    const currentLabel = byId('kitgenix-bulk-current-label');
    if (currentWrap && currentLabel) {
      if (job.current && job.current.label) {
        currentLabel.textContent = job.current.label;
        currentWrap.style.display = '';
      } else {
        currentWrap.style.display = 'none';
      }
    }

    renderErrors(job.errors);
    renderRecent(job.recent);
  };

  const recentList = byId('kitgenix-bulk-recent-list');
  const recentCard = byId('kitgenix-bulk-recent');
  const placeholderImage = recentCard ? recentCard.getAttribute('data-kitgenix-bulk-placeholder-image') : '';
  const outcomeTone = (outcome) => outcome === 'error' ? 'danger' : (outcome === 'excluded' ? 'neutral' : 'success');
  const outcomeLabel = (outcome) => outcome === 'error' ? 'Error' : (outcome === 'excluded' ? 'Excluded' : 'Synced');

  const renderRecent = (recent) => {
    // Card only exists once at least one product has been touched or a job
    // is running (see Admin::render_bulk_recent()); nothing to patch before that.
    if (!recentList || !Array.isArray(recent) || !recent.length) return;
    recentList.textContent = '';
    recent.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'kitgenix-bulk-recent-item';
      row.style.cssText = 'display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--kitgenix-border-color,#eee)';

      const img = document.createElement('img');
      img.src = item.image || placeholderImage || '';
      img.alt = '';
      img.width = 40;
      img.height = 40;
      img.style.cssText = 'width:40px;height:40px;object-fit:cover;border-radius:4px;background:var(--kitgenix-border-color,#eee);flex:none';
      row.appendChild(img);

      const info = document.createElement('div');
      info.style.cssText = 'flex:1;min-width:0';
      const name = document.createElement('div');
      name.style.cssText = 'font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
      name.textContent = item.label || '';
      const price = document.createElement('div');
      price.style.cssText = 'color:var(--kitgenix-text-muted,#666);font-size:12px';
      price.textContent = item.price || '';
      info.appendChild(name);
      info.appendChild(price);
      row.appendChild(info);

      const badge = document.createElement('span');
      badge.className = 'kitgenix-badge ' + outcomeTone(item.outcome);
      badge.textContent = outcomeLabel(item.outcome);
      row.appendChild(badge);

      recentList.appendChild(row);
    });
  };

  let stopped = false;
  const poll = () => {
    if (stopped) return;
    const body = new URLSearchParams();
    body.set('action', 'kitgenix_multistore_sync_for_woocommerce_bulk_job_poll');
    body.set('nonce', pollNonce);
    fetch(pollUrl, { method: 'POST', credentials: 'same-origin', body })
      .then((res) => res.json())
      .then((json) => {
        if (!json || !json.success || !json.data) return;
        const job = json.data;
        if (job.status !== 'running') {
          stopped = true;
          window.location.reload();
          return;
        }
        applyState(job);
      })
      .catch(() => { /* transient network hiccup - the next poll will retry */ });
  };

  poll();
  const interval = setInterval(poll, 1750);
  window.addEventListener('beforeunload', () => { stopped = true; clearInterval(interval); });
})();
