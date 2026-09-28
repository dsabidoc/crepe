(() => {
    const closePickers = (except = null) => {
        document.querySelectorAll('.select-picker.is-open').forEach((picker) => {
            if (picker !== except) {
                picker.classList.remove('is-open');
                picker.querySelector('.select-picker-trigger')?.setAttribute('aria-expanded', 'false');
            }
        });
    };

    const enhanceSelect = (select) => {
        if (select.dataset.customSelectReady === 'true' || select.dataset.nativeSelect === 'true') {
            return;
        }

        select.dataset.customSelectReady = 'true';
        const multiple = select.multiple;
        const wrapper = document.createElement('div');
        wrapper.className = `select-picker${multiple ? ' select-picker-multiple' : ''}`;
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'select-picker-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        const menu = document.createElement('div');
        menu.className = 'select-picker-menu';
        menu.setAttribute('role', 'listbox');
        if (multiple) {
            menu.setAttribute('aria-multiselectable', 'true');
        }

        const footer = multiple ? document.createElement('div') : null;
        if (footer) {
            footer.className = 'select-picker-footer';
            footer.innerHTML = '<span class="select-picker-count"></span><button type="button">Listo</button>';
            menu.appendChild(footer);
        }

        wrapper.append(trigger, menu);

        const selectedOptions = () => [...select.options].filter((option) => option.selected);
        const label = () => {
            const selected = selectedOptions();
            if (multiple) {
                return selected.length === 0 ? (select.dataset.placeholder || 'Selecciona opciones') : selected.length === 1 ? selected[0].textContent.trim() : `${selected.length} seleccionados`;
            }

            return selected[0]?.textContent.trim() || (select.dataset.placeholder || 'Selecciona una opción');
        };

        const sync = () => {
            trigger.querySelector('.select-picker-label').textContent = label();
            trigger.classList.toggle('has-value', selectedOptions().length > 0 && !(selectedOptions().length === 1 && selectedOptions()[0].value === ''));
            menu.querySelectorAll('[data-select-value]').forEach((optionButton) => {
                const option = [...select.options].find((candidate) => candidate.value === optionButton.dataset.selectValue);
                optionButton.classList.toggle('selected', Boolean(option?.selected));
                optionButton.querySelector('.select-picker-check').textContent = option?.selected ? '✓' : '';
            });
            if (footer) {
                footer.querySelector('.select-picker-count').textContent = selectedOptions().length ? `${selectedOptions().length} seleccionados` : 'Sin selección';
            }
        };

        trigger.innerHTML = '<span class="select-picker-label"></span><span class="select-picker-arrow">⌄</span>';

        const renderOptions = () => {
            menu.querySelectorAll('[data-select-value], .select-picker-empty').forEach((item) => item.remove());
            const fragment = document.createDocumentFragment();
            [...select.options].filter((option) => !option.hidden).forEach((option) => {
                const optionButton = document.createElement('button');
                optionButton.type = 'button';
                optionButton.className = 'select-picker-option';
                optionButton.dataset.selectValue = option.value;
                optionButton.setAttribute('role', multiple ? 'option' : 'option');
                optionButton.innerHTML = `<span>${option.textContent}</span><b class="select-picker-check"></b>`;
                optionButton.disabled = option.disabled;
                optionButton.addEventListener('click', () => {
                    if (multiple) {
                        option.selected = !option.selected;
                    } else {
                        select.value = option.value;
                        wrapper.classList.remove('is-open');
                        trigger.setAttribute('aria-expanded', 'false');
                    }
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    sync();
                });
                fragment.appendChild(optionButton);
            });
            menu.insertBefore(fragment, footer);
            sync();
        };

        trigger.addEventListener('click', () => {
            const opening = !wrapper.classList.contains('is-open');
            closePickers(wrapper);
            wrapper.classList.toggle('is-open', opening);
            trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });
        select.addEventListener('change', sync);
        if (footer) {
            footer.querySelector('button').addEventListener('click', () => {
                wrapper.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            });
        }

        new MutationObserver(renderOptions).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden'] });
        renderOptions();
    };

    const init = () => document.querySelectorAll('select').forEach(enhanceSelect);

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.select-picker')) {
            closePickers();
        }
    });
    document.addEventListener('DOMContentLoaded', init);
})();
