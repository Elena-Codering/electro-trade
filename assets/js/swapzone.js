// Mobile nav toggle
function toggleNav() {
  const menu = document.getElementById('navMenu');
  menu.classList.toggle('open');
}

// ── WISHLIST TOGGLE ──────────────────────────────────────
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.btn-wishlist');
  if (!btn) return;

  const listingId = btn.dataset.listingId;
  const icon      = btn.querySelector('.wish-icon');
  const text      = btn.querySelector('#wishlistText');
  const isWished  = icon.textContent === '❤️';

  // Optimistic UI update
  icon.textContent = isWished ? '🤍' : '❤️';
  if (text) text.textContent = isWished ? 'Save' : 'Saved';

  // Scale animation
  btn.style.transform = 'scale(1.2)';
  setTimeout(() => btn.style.transform = 'scale(1)', 200);

  // Send to server
  const formData = new FormData();
  formData.append('listing_id', listingId);
  formData.append('action', isWished ? 'remove' : 'add');

  fetch('/electrotrade/api/wishlist.php', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    if (!res.success) {
      // Revert on failure
      icon.textContent = isWished ? '❤️' : '🤍';
      if (text) text.textContent = isWished ? 'Saved' : 'Save';

      // Redirect to login if not logged in
      if (res.redirect) {
        window.location.href = res.redirect;
      }
    } else {
      // Show toast notification
      showToast(res.action === 'added'
        ? '❤️ Added to wishlist!'
        : '🤍 Removed from wishlist');
    }
  })
  .catch(() => {
    // Revert on error
    icon.textContent = isWished ? '❤️' : '🤍';
    if (text) text.textContent = isWished ? 'Saved' : 'Save';
  });
});

// ── TOAST NOTIFICATION ───────────────────────────────────
function showToast(message) {
  // Remove existing toast
  const existing = document.getElementById('sz-toast');
  if (existing) existing.remove();

  const toast = document.createElement('div');
  toast.id = 'sz-toast';
  toast.textContent = message;
  toast.style.cssText = `
    position: fixed;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%);
    background: #1A1A1A;
    color: #fff;
    padding: .75rem 1.5rem;
    border-radius: 30px;
    font-size: .9rem;
    font-weight: 500;
    z-index: 9999;
    box-shadow: 0 4px 20px rgba(0,0,0,.3);
    animation: fadeInUp .3s ease;
  `;

  document.body.appendChild(toast);

  // Auto remove after 2.5 seconds
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity .3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 2500);
}