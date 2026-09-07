(() => {
  const isVisible = el => el && !el.classList.contains('hidden');
  const focusTarget = el => {
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
    el.focus({ preventScroll: true });
  };

  document.addEventListener('click', event => {
    const button = event.target.closest('.review-card .case-open');
    if (!button) return;

    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Abrindo revisão…';

    const started = Date.now();
    const timer = setInterval(() => {
      const panel = document.querySelector('#review-panel');
      const error = document.querySelector('#cockpit-error');

      if (isVisible(panel)) {
        clearInterval(timer);
        button.disabled = false;
        button.textContent = original;
        focusTarget(panel);
        return;
      }

      if (error && error.textContent.trim() !== '') {
        clearInterval(timer);
        button.disabled = false;
        button.textContent = original;
        focusTarget(error);
        return;
      }

      if (Date.now() - started > 12000) {
        clearInterval(timer);
        button.disabled = false;
        button.textContent = original;
      }
    }, 100);
  });
})();
