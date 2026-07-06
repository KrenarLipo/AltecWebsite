(function () {
    function readConfig() {
        if (window.NutriMindsRegistration && window.NutriMindsRegistration.specialties) {
            return window.NutriMindsRegistration;
        }

        const configNode = document.querySelector('[data-nm-registration-config]');
        if (!configNode) {
            return {};
        }

        try {
            return JSON.parse(configNode.textContent);
        } catch (error) {
            return {};
        }
    }

    const config = readConfig();
    const text = config.text || {};
    const specialties = config.specialties || [];
    const maxFileSize = 10 * 1024 * 1024;
    const allowedFileExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    const allowedFileTypes = ['application/pdf', 'image/jpeg', 'image/png'];

    function label(key, fallback) {
        return text[key] || fallback;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function initRegistration(root) {
        const form = root.querySelector('form');
        const panels = Array.from(root.querySelectorAll('[data-nm-step]'));
        const dots = Array.from(root.querySelectorAll('[data-nm-step-dot]'));
        const currentStep = root.querySelector('[data-nm-current-step]');
        const backButton = root.querySelector('[data-nm-back]');
        const nextButton = root.querySelector('[data-nm-next]');
        const submitButton = root.querySelector('[data-nm-submit]');
        const specialtyList = root.querySelector('[data-nm-specialties]');
        const specialtyFilters = root.querySelector('[data-nm-specialty-filters]');
        const selectedList = root.querySelector('[data-nm-selected-specialties]');
        const specialtySearch = root.querySelector('[data-nm-specialty-search]');
        const review = root.querySelector('[data-nm-review]');
        const notice = root.querySelector('[data-nm-submit-notice]');
        const validationSummary = root.querySelector('[data-nm-validation-summary]');
        const selected = new Map();
        const categories = Array.from(new Set(specialties.map((specialty) => specialty.category || '').filter(Boolean)));
        let primarySpecialty = '';
        let activeCategory = 'all';
        let step = 1;

        function getValue(name) {
            const input = form.elements[name];
            return input ? input.value.trim() : '';
        }

        function showStep(nextStep) {
            step = Math.max(1, Math.min(nextStep, panels.length));
            panels.forEach((panel) => panel.classList.toggle('is-active', Number(panel.dataset.nmStep) === step));
            dots.forEach((dot) => {
                const dotStep = Number(dot.dataset.nmStepDot);
                dot.classList.toggle('is-active', dotStep === step);
                dot.classList.toggle('is-complete', dotStep < step);
            });
            currentStep.textContent = String(step);
            backButton.disabled = step === 1;
            nextButton.hidden = step === panels.length;
            submitButton.hidden = step !== panels.length;

            if (step === 4) {
                renderReview();
            }
        }

        function errorIdFor(field) {
            return `nm-error-${field.name || field.type}`;
        }

        function clearFieldError(field) {
            const container = field.closest('label') || field.parentElement;
            if (!container) {
                return;
            }

            const existing = container.querySelector(`[data-nm-field-error="${field.name}"]`);
            if (existing) {
                existing.remove();
            }
            field.removeAttribute('aria-invalid');
            field.removeAttribute('aria-describedby');
        }

        function fieldLabel(field) {
            const labelNode = field.closest('label');
            const labelText = labelNode ? labelNode.querySelector('span') : null;

            return labelText ? labelText.textContent.trim() : field.name;
        }

        function clearValidationSummary() {
            if (!validationSummary) {
                return;
            }

            validationSummary.hidden = true;
            validationSummary.innerHTML = '';
        }

        function showValidationSummary(items) {
            if (!validationSummary || items.length === 0) {
                return;
            }

            const list = items.map((item) => `<li>${escapeHtml(item.label)}: ${escapeHtml(item.message)}</li>`).join('');
            validationSummary.innerHTML = `
                <p class="nm-validation-summary__title">${escapeHtml(label('validation.summaryTitle', 'Please check the highlighted fields'))}</p>
                <p>${escapeHtml(label('validation.summaryIntro', 'We found a few details that need attention before you can continue.'))}</p>
                <ul>${list}</ul>
            `;
            validationSummary.hidden = false;
            validationSummary.focus({ preventScroll: true });
        }

        function setFieldError(field, message) {
            const container = field.closest('label') || field.parentElement;
            if (!container) {
                return;
            }

            clearFieldError(field);
            const error = document.createElement('small');
            error.className = 'nm-field-error';
            error.id = errorIdFor(field);
            error.dataset.nmFieldError = field.name;
            error.textContent = message;
            container.appendChild(error);
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', error.id);
        }

        function clearSpecialtyError() {
            const existing = root.querySelector('[data-nm-specialties-error]');
            if (existing) {
                existing.remove();
            }
        }

        function setSpecialtyError(message) {
            clearSpecialtyError();
            const error = document.createElement('p');
            error.className = 'nm-group-error';
            error.dataset.nmSpecialtiesError = 'true';
            error.textContent = message;
            specialtyList.insertAdjacentElement('afterend', error);
        }

        function isAllowedFile(file) {
            const extension = file.name.split('.').pop().toLowerCase();

            return allowedFileExtensions.includes(extension) && (!file.type || allowedFileTypes.includes(file.type));
        }

        function isValidPhone(value) {
            const digits = value.replace(/\D/g, '');

            return /^\+?[\d\s().-]{7,24}$/.test(value) && digits.length >= 7 && digits.length <= 20;
        }

        function validationMessage(field) {
            const value = field.value.trim();

            if (field.type === 'checkbox') {
                return field.checked ? '' : label('validation.termsRequired', 'Please confirm this consent before submitting.');
            }

            if (field.type === 'file') {
                const file = field.files[0];
                if (!file) {
                    return label('validation.fileRequired', 'Please upload this document.');
                }
                if (file.size > maxFileSize) {
                    return label('validation.fileSize', 'This file is larger than 10 MB.');
                }
                if (!isAllowedFile(file)) {
                    return label('validation.fileType', 'Please upload a PDF, JPG, or PNG file.');
                }
                return '';
            }

            if (field.required && !value) {
                return label('validation.required', 'This field is required.');
            }

            if (field.type === 'email' && value && !field.checkValidity()) {
                return label('validation.email', 'Please enter a valid email address.');
            }

            if (field.type === 'tel' && value && !isValidPhone(value)) {
                return label('validation.phone', 'Please enter a valid phone number.');
            }

            return '';
        }

        function validateField(field) {
            const message = validationMessage(field);
            if (message) {
                setFieldError(field, message);
                return false;
            }

            clearFieldError(field);
            return true;
        }

        function validateStep() {
            const activePanel = panels[step - 1];
            const fields = Array.from(activePanel.querySelectorAll('input[required]'));
            let firstInvalid = null;
            const errors = [];

            fields.forEach((field) => {
                const message = validationMessage(field);
                if (message) {
                    setFieldError(field, message);
                    errors.push({
                        label: fieldLabel(field),
                        message,
                    });
                    if (!firstInvalid) {
                        firstInvalid = field;
                    }
                } else {
                    clearFieldError(field);
                }
            });

            if (step === 2) {
                clearSpecialtyError();
                if (selected.size === 0 || !primarySpecialty) {
                    const message = label('validation.specialtiesRequired', 'Please select at least one profession and choose a primary profession.');
                    setSpecialtyError(message);
                    errors.push({
                        label: label('step.specialties', 'Professions'),
                        message,
                    });
                    showValidationSummary(errors);
                    specialtyList.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
            }

            if (firstInvalid) {
                showValidationSummary(errors);
                firstInvalid.focus({ preventScroll: true });
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            clearValidationSummary();
            return true;
        }

        function renderCategoryFilters() {
            if (!specialtyFilters) {
                return;
            }

            if (!categories.includes(activeCategory)) {
                activeCategory = 'all';
            }

            const categoryItems = [
                {
                    id: 'all',
                    label: label('js.allCategories', 'All'),
                    count: specialties.length,
                },
                ...categories.map((category) => ({
                    id: category,
                    label: category,
                    count: specialties.filter((specialty) => specialty.category === category).length,
                })),
            ];

            specialtyFilters.innerHTML = categoryItems.map((category) => `
                <button type="button" class="nm-filter-chip ${activeCategory === category.id ? 'is-active' : ''}" data-nm-filter-category="${escapeHtml(category.id)}">
                    ${escapeHtml(category.label)}
                    <span>${category.count}</span>
                </button>
            `).join('');
        }

        function renderSpecialties() {
            const query = specialtySearch.value.trim().toLowerCase();
            renderCategoryFilters();

            const filtered = specialties.filter((specialty) => {
                return !query ||
                    specialty.name.toLowerCase().includes(query) ||
                    specialty.category.toLowerCase().includes(query) ||
                    (specialty.description || '').toLowerCase().includes(query) ||
                    (specialty.groupDescription || '').toLowerCase().includes(query) ||
                    (specialty.tags || []).some((tag) => tag.toLowerCase().includes(query));
            }).filter((specialty) => activeCategory === 'all' || specialty.category === activeCategory);

            if (!filtered.length) {
                specialtyList.innerHTML = `<p class="nm-empty">${escapeHtml(label('js.noResults', 'No professions match your search.'))}</p>`;
                renderSelected();
                return;
            }

            const grouped = filtered.reduce((groups, specialty) => {
                const category = specialty.category || '';
                if (!groups.has(category)) {
                    groups.set(category, {
                        description: specialty.groupDescription || '',
                        items: [],
                    });
                }
                groups.get(category).items.push(specialty);
                return groups;
            }, new Map());

            specialtyList.innerHTML = Array.from(grouped.entries()).map(([category, group]) => {
                const selectedInGroup = group.items.some((specialty) => selected.has(specialty.id));
                const groupShouldOpen = Boolean(query) || activeCategory !== 'all' || selectedInGroup;
                const description = group.description
                    ? `<p class="nm-specialty-group__description">${escapeHtml(group.description)}</p>`
                    : '';
                const items = group.items.map((specialty) => {
                    const isSelected = selected.has(specialty.id);
                    const isPrimary = primarySpecialty === specialty.id;
                    const specialtyDescription = specialty.description
                        ? `<span class="nm-specialty__description">${escapeHtml(specialty.description)}</span>`
                        : '';
                    const tags = specialty.tags && specialty.tags.length
                        ? `<span class="nm-specialty__tags">${specialty.tags.map((tag) => escapeHtml(tag)).join(' · ')}</span>`
                        : '';

                    return `
                        <div class="nm-specialty">
                            <div class="nm-specialty__meta">
                                <span class="nm-specialty__name">${escapeHtml(specialty.name)}</span>
                                ${specialtyDescription}
                                ${tags}
                            </div>
                            <button type="button" class="nm-mini-button ${isSelected ? 'is-selected' : ''}" data-nm-toggle-specialty="${escapeHtml(specialty.id)}">
                                ${isSelected ? escapeHtml(label('js.selected', 'Selected')) : escapeHtml(label('js.select', 'Select'))}
                            </button>
                            <button type="button" class="nm-mini-button ${isPrimary ? 'is-primary' : ''}" data-nm-primary-specialty="${escapeHtml(specialty.id)}" ${!isSelected ? 'disabled' : ''}>
                                ${escapeHtml(label('js.primary', 'Primary'))}
                            </button>
                        </div>
                    `;
                }).join('');

                return `
                    <details class="nm-specialty-group" ${groupShouldOpen ? 'open' : ''}>
                        <summary class="nm-specialty-group__header">
                            <h3>${escapeHtml(category)}</h3>
                            <span class="nm-specialty-group__count">${group.items.length} ${escapeHtml(group.items.length === 1 ? label('js.professionCount', 'profession') : label('js.professionCountPlural', 'professions'))}</span>
                            ${description}
                        </summary>
                        <div class="nm-specialty-group__items">${items}</div>
                    </details>
                `;
            }).join('');

            renderSelected();
        }

        function renderSelected() {
            if (selected.size === 0) {
                selectedList.innerHTML = '';
                return;
            }

            const items = Array.from(selected.values()).map((specialty) => {
                const isPrimary = primarySpecialty === specialty.id;
                const prefix = isPrimary ? `${label('js.primaryPrefix', 'Primary')} · ` : '';
                return `<span class="nm-pill ${isPrimary ? 'nm-pill--primary' : ''}">${escapeHtml(prefix + specialty.name)}</span>`;
            }).join('');

            selectedList.innerHTML = `
                <p class="nm-selected__title">${escapeHtml(label('js.selectedTitle', 'Selected'))} (${selected.size})</p>
                <div class="nm-selected__items">${items}</div>
            `;
        }

        function renderReview() {
            const licenseFile = form.elements.license_file.files[0];
            const diplomaFile = form.elements.diploma_file.files[0];
            const identityFile = form.elements.id_file.files[0];
            const address = getValue('address');
            const contactParts = [getValue('phone')];
            if (address) {
                contactParts.push(`${label('js.addressLabel', 'Address')}: ${address}`);
            }
            const selectedItems = Array.from(selected.values()).map((specialty) => {
                const isPrimary = primarySpecialty === specialty.id;
                const prefix = isPrimary ? `${label('js.primaryPrefix', 'Primary')} · ` : '';
                return `<span class="nm-pill ${isPrimary ? 'nm-pill--primary' : ''}">${escapeHtml(prefix + specialty.name)}</span>`;
            }).join('');

            review.innerHTML = `
                <p class="nm-review__title">${escapeHtml(label('js.reviewTitle', 'Application summary'))}</p>
                <p><strong>${escapeHtml(getValue('first_name'))} ${escapeHtml(getValue('last_name'))}</strong> · ${escapeHtml(getValue('email'))}</p>
                <p>${escapeHtml(contactParts.join(' · '))}</p>
                <div class="nm-selected__items">${selectedItems}</div>
                <p>${escapeHtml(licenseFile ? licenseFile.name : label('js.noRegistrationDocument', 'No registration document selected'))} · ${escapeHtml(diplomaFile ? diplomaFile.name : label('js.noCredential', 'No credential selected'))} · ${escapeHtml(identityFile ? identityFile.name : label('js.noIdentityDocument', 'No ID, driving license, or passport copy selected'))}</p>
            `;
        }

        function setNotice(message, type) {
            notice.textContent = message;
            notice.hidden = false;
            notice.classList.toggle('is-error', type === 'error');
            notice.classList.toggle('is-success', type === 'success');
        }

        function getSubmitPayload() {
            const payload = new FormData(form);
            payload.append('action', config.action || 'nutriminds_submit_application');
            payload.append('nonce', config.nonce || '');
            payload.append('language', config.language || '');
            payload.append('selected_specialties', JSON.stringify(Array.from(selected.values())));
            payload.append('primary_specialty', primarySpecialty);

            return payload;
        }

        specialtyList.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-nm-toggle-specialty]');
            const primary = event.target.closest('[data-nm-primary-specialty]');

            if (toggle) {
                const id = toggle.dataset.nmToggleSpecialty;
                const specialty = specialties.find((item) => item.id === id);
                if (selected.has(id)) {
                    selected.delete(id);
                    if (primarySpecialty === id) {
                        primarySpecialty = selected.size ? Array.from(selected.keys())[0] : '';
                    }
                } else if (specialty) {
                    selected.set(id, specialty);
                    if (!primarySpecialty) {
                        primarySpecialty = id;
                    }
                }
                clearSpecialtyError();
                renderSpecialties();
            }

            if (primary) {
                const id = primary.dataset.nmPrimarySpecialty;
                if (selected.has(id)) {
                    primarySpecialty = id;
                    clearSpecialtyError();
                    renderSpecialties();
                }
            }
        });

        form.addEventListener('input', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.type !== 'file') {
                clearFieldError(event.target);
                clearValidationSummary();
            }
        });
        form.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement) {
                validateField(event.target);
            }
        });
        form.addEventListener('blur', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.required) {
                validateField(event.target);
            }
        }, true);
        specialtySearch.addEventListener('input', () => {
            clearValidationSummary();
            renderSpecialties();
        });
        if (specialtyFilters) {
            specialtyFilters.addEventListener('click', (event) => {
                const filter = event.target.closest('[data-nm-filter-category]');
                if (!filter) {
                    return;
                }

                activeCategory = filter.dataset.nmFilterCategory || 'all';
                clearValidationSummary();
                renderSpecialties();
            });
        }
        backButton.addEventListener('click', () => showStep(step - 1));
        nextButton.addEventListener('click', () => {
            if (validateStep()) {
                showStep(step + 1);
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!validateStep()) {
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = label('js.submitting', 'Sending...');
            setNotice(label('js.submitting', 'Sending...'), 'success');

            try {
                const response = await fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: getSubmitPayload(),
                    credentials: 'same-origin',
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.data && result.data.message ? result.data.message : label('js.submitError', 'Submission failed. Please try again.'));
                }

                setNotice(result.data.message || label('js.frontendComplete', 'Frontend complete'), 'success');
                submitButton.textContent = label('js.frontendComplete', 'Frontend complete');
                form.querySelectorAll('input, button').forEach((field) => {
                    if (field !== submitButton) {
                        field.disabled = true;
                    }
                });
            } catch (error) {
                submitButton.disabled = false;
                submitButton.textContent = label('button.submit', 'Submit application');
                setNotice(error.message || label('js.submitError', 'Submission failed. Please try again.'), 'error');
            }
        });

        renderSpecialties();
        showStep(1);
    }

    function initRegistrationModal(modal) {
        const trigger = document.querySelector('[data-nm-registration-trigger]');
        const closeElements = Array.from(modal.querySelectorAll('[data-nm-registration-close]'));
        let lastFocusedElement = null;

        function onKeydown(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        }

        function openModal() {
            lastFocusedElement = document.activeElement;
            modal.hidden = false;
            document.body.classList.add('nm-registration-modal-open');
            const closeButton = modal.querySelector('.nm-registration-modal__close');
            if (closeButton) {
                closeButton.focus();
            }
            document.addEventListener('keydown', onKeydown);
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('nm-registration-modal-open');
            document.removeEventListener('keydown', onKeydown);
            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
        }

        if (trigger) {
            trigger.addEventListener('click', openModal);
        }

        closeElements.forEach((element) => {
            element.addEventListener('click', closeModal);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-nm-registration]').forEach(initRegistration);
        document.querySelectorAll('[data-nm-registration-modal]').forEach(initRegistrationModal);
    });
})();
