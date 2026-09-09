(() => {
  const isVisible = el => el && !el.classList.contains('hidden');
  const focusTarget = el => {
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
    el.focus({ preventScroll: true });
  };
  const isResolvedReviewError = el => {
    const message = el?.textContent?.trim() || '';
    return message.includes('Esta revisão foi atualizada')
      || message.includes('Este caso já foi resolvido automaticamente');
  };
  let refreshingResolvedReview = false;
  const dismissResolvedReview = () => {
    const error = document.querySelector('#cockpit-error');
    if (!isResolvedReviewError(error)) return false;
    const panel = document.querySelector('#review-panel');
    if (panel) panel.classList.add('hidden');
    if (refreshingResolvedReview) return true;
    refreshingResolvedReview = true;
    const queue = typeof window.loadReviews === 'function' ? window.loadReviews() : Promise.resolve();
    Promise.resolve(queue)
      .then(() => typeof window.loadSummary === 'function' ? window.loadSummary() : null)
      .finally(() => {
        refreshingResolvedReview = false;
        focusTarget(document.querySelector('#case-list'));
      });
    return true;
  };

  const errorBox = document.querySelector('#cockpit-error');
  if (errorBox) {
    new MutationObserver(() => dismissResolvedReview()).observe(errorBox, {
      childList: true,
      subtree: true,
      characterData: true,
    });
  }

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

      if (error && error.textContent.trim() !== '') {
        clearInterval(timer);
        button.disabled = false;
        button.textContent = original;
        if (!dismissResolvedReview()) focusTarget(error);
        return;
      }

      if (isVisible(panel)) {
        clearInterval(timer);
        button.disabled = false;
        button.textContent = original;
        focusTarget(panel);
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
