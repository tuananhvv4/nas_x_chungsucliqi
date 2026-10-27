document.querySelector('[data-menu]')?.addEventListener('click', () => {
  document.querySelector('.sidebar')?.classList.toggle('open');
});

document.querySelectorAll('[data-mapping-form]').forEach((form) => {
  const choice = form.querySelector('[data-target-choice]');
  if (!choice) return;

  const typeInput = form.querySelector('[data-target-type-value]');
  const productIdInput = form.querySelector('[data-target-product-id]');
  const productNameInput = form.querySelector('[data-target-product-name]');
  const packInput = form.querySelector('[data-target-pack]');
  const retailQuantity = form.querySelector('[data-retail-quantity]');
  const summary = form.querySelector('[data-selected-target]');

  const applyChoice = () => {
    const option = choice.options[choice.selectedIndex];
    const type = option?.dataset.type || '';
    const externalId = option?.dataset.externalId || '';
    const name = option?.dataset.name || '';
    const isRetail = type === 'retail';

    typeInput.value = isRetail ? 'retail_order' : 'order';
    productIdInput.value = type === 'order' ? externalId : '';
    packInput.value = isRetail ? externalId : '';
    productNameInput.value = name;

    if (retailQuantity) retailQuantity.hidden = !isRetail;
    if (summary) {
      summary.hidden = !name;
      const title = summary.querySelector('strong');
      if (title) title.textContent = name;
    }
  };

  choice.addEventListener('change', applyChoice);
  if (choice.value) applyChoice();
});
