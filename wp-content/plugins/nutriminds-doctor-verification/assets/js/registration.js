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
        const selectedList = root.querySelector('[data-nm-selected-specialties]');
        const specialtySearch = root.querySelector('[data-nm-specialty-search]');
        const review = root.querySelector('[data-nm-review]');
        const notice = root.querySelector('[data-nm-submit-notice]');
        const selected = new Map();
        let primarySpecialty = '';
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

            fields.forEach((field) => {
                if (!validateField(field) && !firstInvalid) {
                    firstInvalid = field;
                }
            });

            if (step === 2) {
                clearSpecialtyError();
                if (selected.size === 0 || !primarySpecialty) {
                    setSpecialtyError(label('validation.specialtiesRequired', 'Please select at least one profession and choose a primary profession.'));
                    specialtyList.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
            }

            if (firstInvalid) {
                firstInvalid.focus({ preventScroll: true });
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            return true;
        }

        function renderSpecialties() {
            const query = specialtySearch.value.trim().toLowerCase();
            const filtered = specialties.filter((specialty) => {
                return !query ||
                    specialty.name.toLowerCase().includes(query) ||
                    specialty.category.toLowerCase().includes(query) ||
                    (specialty.description || '').toLowerCase().includes(query) ||
                    (specialty.groupDescription || '').toLowerCase().includes(query) ||
                    (specialty.tags || []).some((tag) => tag.toLowerCase().includes(query));
            });

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
                    <section class="nm-specialty-group">
                        <header class="nm-specialty-group__header">
                            <h3>${escapeHtml(category)}</h3>
                            ${description}
                        </header>
                        <div class="nm-specialty-group__items">${items}</div>
                    </section>
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
            const selectedItems = Array.from(selected.values()).map((specialty) => {
                const isPrimary = primarySpecialty === specialty.id;
                const prefix = isPrimary ? `${label('js.primaryPrefix', 'Primary')} · ` : '';
                return `<span class="nm-pill ${isPrimary ? 'nm-pill--primary' : ''}">${escapeHtml(prefix + specialty.name)}</span>`;
            }).join('');

            review.innerHTML = `
                <p class="nm-review__title">${escapeHtml(label('js.reviewTitle', 'Application summary'))}</p>
                <p><strong>${escapeHtml(getValue('first_name'))} ${escapeHtml(getValue('last_name'))}</strong> · ${escapeHtml(getValue('email'))}</p>
                <p>${escapeHtml(getValue('phone'))} · ${escapeHtml(label('js.registrationLabel', 'Registration'))} ${escapeHtml(getValue('license_number'))}</p>
                <div class="nm-selected__items">${selectedItems}</div>
                <p>${escapeHtml(licenseFile ? licenseFile.name : label('js.noRegistrationDocument', 'No registration document selected'))} · ${escapeHtml(diplomaFile ? diplomaFile.name : label('js.noCredential', 'No credential selected'))}</p>
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
        specialtySearch.addEventListener('input', renderSpecialties);
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

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-nm-registration]').forEach(initRegistration);
    });
})();
