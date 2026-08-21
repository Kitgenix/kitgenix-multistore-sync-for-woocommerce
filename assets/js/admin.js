(() => {
  'use strict';
  document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-kitgenix-multistore-sync-for-woocommerce_confirm]');
    if (target && !window.confirm(target.getAttribute('data-kitgenix-multistore-sync-for-woocommerce_confirm'))) event.preventDefault();
    const all = event.target.closest('[data-kitgenix-multistore-sync-for-woocommerce_check-all]');
    if (all) document.querySelectorAll('input[name="network_ids[]"]').forEach((box) => { box.checked = all.checked; });
  });
})();
