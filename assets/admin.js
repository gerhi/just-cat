// Admin helpers: copy buttons next to read-only link fields.
document.addEventListener('click', function (ev) {
  var btn = ev.target.closest('[data-copy]');
  if (!btn) return;
  var input = btn.parentElement.querySelector('.copy-src');
  if (!input) return;
  input.select();
  var done = function () {
    var old = btn.textContent;
    btn.textContent = btn.dataset.copied || 'Copied';
    setTimeout(function () { btn.textContent = old; }, 1500);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(input.value).then(done);
  } else {
    try { document.execCommand('copy'); done(); } catch (e) { /* user can copy manually */ }
  }
});

document.addEventListener('focusin', function (ev) {
  if (ev.target.classList && ev.target.classList.contains('copy-src')) ev.target.select();
});
