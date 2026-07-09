document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('loginForm');
    const recoverForm = document.getElementById('recoverForm');
    const emailInput = document.getElementById('correo');
    const passwordInput = document.getElementById('contrasena');
    const passwordToggle = document.querySelector('.password-eye');
    const themeToggle = document.querySelector('.theme-toggle');
    const themeLight = document.querySelector('.theme-light');
    const themeDark = document.querySelector('.theme-dark');

    const confirmIcons = {
        logout: '<svg viewBox="0 0 24 24" width="28" height="28"><path d="M10.75 4.75a.75.75 0 0 1 .75-.75h6.75C19.22 4 20 4.78 20 5.75v12.5c0 .97-.78 1.75-1.75 1.75H11.5a.75.75 0 0 1 0-1.5h6.75a.25.25 0 0 0 .25-.25V5.75a.25.25 0 0 0-.25-.25H11.5a.75.75 0 0 1-.75-.75Zm-3.28 3.72a.75.75 0 0 1 1.06 1.06L6.81 11.25h7.44a.75.75 0 0 1 0 1.5H6.81l1.72 1.72a.75.75 0 1 1-1.06 1.06l-3-3a.75.75 0 0 1 0-1.06l3-3Z" fill="currentColor"/></svg>',
        notification: '<svg viewBox="0 0 24 24" width="28" height="28"><path d="M5.75 6h12.5C19.22 6 20 6.78 20 7.75v8.5c0 .97-.78 1.75-1.75 1.75H5.75C4.78 18 4 17.22 4 16.25v-8.5C4 6.78 4.78 6 5.75 6Zm.1 1.5 5.42 4.16c.43.33 1.03.33 1.46 0l5.42-4.16H5.85Zm12.65 1.2-4.86 3.73a2.7 2.7 0 0 1-3.28 0L5.5 8.7v7.55c0 .14.11.25.25.25h12.5c.14 0 .25-.11.25-.25V8.7Z" fill="currentColor"/></svg>',
        coordinator: '<svg viewBox="0 0 24 24" width="28" height="28"><path d="M12 4.25a3.75 3.75 0 1 1 0 7.5 3.75 3.75 0 0 1 0-7.5Zm0 1.5a2.25 2.25 0 1 0 0 4.5 2.25 2.25 0 0 0 0-4.5ZM5.75 19.5a.75.75 0 0 1-.75-.75c0-3.04 2.73-5.25 7-5.25s7 2.21 7 5.25a.75.75 0 0 1-.75.75H5.75Zm.84-1.5h10.82C17 16.24 14.99 15 12 15s-5 1.24-5.41 3Z" fill="currentColor"/></svg>',
        warning: '<svg viewBox="0 0 24 24" width="28" height="28"><path d="M12.87 4.72a1 1 0 0 0-1.74 0L3.7 17.73A1 1 0 0 0 4.57 19h14.86a1 1 0 0 0 .87-1.27L12.87 4.72ZM12 8.5a.75.75 0 0 1 .75.75v4a.75.75 0 0 1-1.5 0v-4A.75.75 0 0 1 12 8.5Zm0 7.75a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" fill="currentColor"/></svg>'
    };

    const getConfirmIcon = function (kicker, title, confirmText) {
        const text = [kicker || '', title || '', confirmText || ''].join(' ').toLowerCase();
        if (text.includes('salida') || text.includes('cerrar sesion') || text.includes('cerrar sesión')) {
            return confirmIcons.logout;
        }
        if (text.includes('cancelar') || text.includes('eliminar') || text.includes('rechazar')) {
            return confirmIcons.warning;
        }
        if (text.includes('coordinador') || text.includes('coordinacion') || text.includes('coordinación')) {
            return confirmIcons.coordinator;
        }
        return confirmIcons.notification;
    };

    const ensureLogoutModal = function () {
        if (document.getElementById('logoutConfirmModal')) {
            return document.getElementById('logoutConfirmModal');
        }

        const modalMarkup = '<div class="logout-confirm-overlay" id="logoutConfirmModal" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle">' +
            '<div class="logout-confirm-dialog">' +
            '<div class="logout-confirm-icon" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" width="28" height="28">' +
            '<path d="M10.75 4.75a.75.75 0 0 1 .75-.75h6.75C19.22 4 20 4.78 20 5.75v12.5c0 .97-.78 1.75-1.75 1.75H11.5a.75.75 0 0 1 0-1.5h6.75a.25.25 0 0 0 .25-.25V5.75a.25.25 0 0 0-.25-.25H11.5a.75.75 0 0 1-.75-.75Zm-3.28 3.72a.75.75 0 0 1 1.06 1.06L6.81 11.25h7.44a.75.75 0 0 1 0 1.5H6.81l1.72 1.72a.75.75 0 1 1-1.06 1.06l-3-3a.75.75 0 0 1 0-1.06l3-3Z" fill="currentColor"/>' +
            '</svg>' +
            '</div>' +
            '<p class="logout-confirm-kicker">Salida segura</p>' +
            '<h3 id="logoutConfirmTitle">Cerrar sesión</h3>' +
            '<p>Tu sesión se cerrará en este dispositivo. Podrás ingresar nuevamente con tu correo y contraseña.</p>' +
            '<div class="logout-confirm-actions">' +
            '<button type="button" class="logout-cancel-btn" id="logoutCancelButton">Cancelar</button>' +
            '<button type="button" class="logout-confirm-btn" id="logoutConfirmButton">Sí, cerrar sesión</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        document.body.insertAdjacentHTML('beforeend', modalMarkup);
        return document.getElementById('logoutConfirmModal');
    };

    let pendingLogoutUrl = '';
    let pendingConfirmAction = null;

    const setConfirmModalContent = function (kicker, title, message, confirmText) {
        const modal = ensureLogoutModal();
        const modalKicker = modal.querySelector('.logout-confirm-kicker');
        const modalTitle = modal.querySelector('#logoutConfirmTitle');
        const modalMessage = modal.querySelector('.logout-confirm-dialog > p:not(.logout-confirm-kicker)');
        const modalConfirm = modal.querySelector('#logoutConfirmButton');
        const modalIcon = modal.querySelector('.logout-confirm-icon');

        if (modalKicker) {
            modalKicker.textContent = kicker || 'Confirmacion';
        }
        if (modalTitle) {
            modalTitle.textContent = title || 'Confirmar accion';
        }
        if (modalMessage) {
            modalMessage.textContent = message || 'Revisa la informacion antes de continuar.';
        }
        if (modalConfirm) {
            modalConfirm.textContent = confirmText || 'Confirmar';
        }
        if (modalIcon) {
            modalIcon.innerHTML = getConfirmIcon(kicker, title, confirmText);
        }

        return modal;
    };

    const closeLogoutModal = function () {
        const modal = document.getElementById('logoutConfirmModal');
        if (modal) {
            modal.classList.remove('is-open');
        }
        document.body.classList.remove('logout-modal-open');
        pendingLogoutUrl = '';
        pendingConfirmAction = null;
    };

    const openLogoutModal = function (url) {
        const modal = setConfirmModalContent(
            'Salida segura',
            'Cerrar sesion',
            'Tu sesion se cerrara en este dispositivo. Podras ingresar nuevamente con tu correo y contrasena.',
            'Si, cerrar sesion'
        );
        pendingLogoutUrl = url;
        pendingConfirmAction = null;
        modal.classList.add('is-open');
        document.body.classList.add('logout-modal-open');
    };

    const openActionModal = function (options, onConfirm) {
        const modal = setConfirmModalContent(
            options.kicker,
            options.title,
            options.message,
            options.confirmText
        );
        pendingLogoutUrl = '';
        pendingConfirmAction = onConfirm;
        modal.classList.add('is-open');
        document.body.classList.add('logout-modal-open');
    };

    const modal = ensureLogoutModal();
    const cancelButton = document.getElementById('logoutCancelButton');
    const confirmButton = document.getElementById('logoutConfirmButton');

    if (cancelButton) {
        cancelButton.addEventListener('click', closeLogoutModal);
    }

    if (confirmButton) {
        confirmButton.addEventListener('click', function () {
            if (typeof pendingConfirmAction === 'function') {
                const action = pendingConfirmAction;
                closeLogoutModal();
                action();
                return;
            }

            if (pendingLogoutUrl) {
                window.location.href = pendingLogoutUrl;
            }
            closeLogoutModal();
        });
    }

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeLogoutModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeLogoutModal();
        }
    });

    document.querySelectorAll('a[href*="login/logout.php"]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            openLogoutModal(link.getAttribute('href') || 'login/logout.php');
        });
    });

    document.querySelectorAll('a[data-confirm-title], a[data-confirm-message]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            openActionModal({
                kicker: link.dataset.confirmKicker || 'Confirmacion',
                title: link.dataset.confirmTitle || 'Confirmar accion',
                message: link.dataset.confirmMessage || 'Revisa la informacion antes de continuar.',
                confirmText: link.dataset.confirmText || 'Confirmar'
            }, function () {
                window.location.href = link.getAttribute('href') || '#';
            });
        });
    });

    document.querySelectorAll('form').forEach(function (formElement) {
        formElement.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            const source = submitter && (submitter.dataset.confirmTitle || submitter.dataset.confirmMessage)
                ? submitter
                : formElement;

            if (submitter && submitter.name === 'accion' && submitter.value === 'cancelar') {
                const observacionField = formElement.querySelector('textarea[name="observacion"]');
                if (!observacionField || observacionField.value.trim() === '') {
                    event.preventDefault();
                    if (observacionField) {
                        observacionField.focus();
                    }
                    return;
                }
            }

            if (submitter && submitter.name === 'accion' && submitter.value === 'enviar_coordinador') {
                const coordinatorField = formElement.querySelector('select[name="id_coordinador"]');
                if (coordinatorField && (!coordinatorField.value || coordinatorField.value === '0')) {
                    event.preventDefault();
                    coordinatorField.focus();
                    return;
                }
            }

            if (!source.dataset.confirmTitle && !source.dataset.confirmMessage) {
                return;
            }

            event.preventDefault();
            openActionModal({
                kicker: source.dataset.confirmKicker || 'Confirmacion',
                title: source.dataset.confirmTitle || 'Confirmar accion',
                message: source.dataset.confirmMessage || 'Revisa la informacion antes de continuar.',
                confirmText: source.dataset.confirmText || 'Confirmar'
            }, function () {
                if (submitter && submitter.name) {
                    const hiddenSubmitter = document.createElement('input');
                    hiddenSubmitter.type = 'hidden';
                    hiddenSubmitter.name = submitter.name;
                    hiddenSubmitter.value = submitter.value;
                    formElement.appendChild(hiddenSubmitter);
                }
                formElement.submit();
            });
        });
    });

    const applyTheme = function (theme) {
        const nextTheme = theme === 'dark' ? 'dark' : 'light';
        document.body.dataset.theme = nextTheme;
        localStorage.setItem('sica-theme', nextTheme);

        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', nextTheme === 'dark' ? 'true' : 'false');
        }
    };

    const savedTheme = localStorage.getItem('sica-theme');
    const preferredTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    applyTheme(savedTheme || preferredTheme);

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            applyTheme(document.body.dataset.theme === 'dark' ? 'light' : 'dark');
        });
    }

    if (themeLight) {
        themeLight.addEventListener('click', function () {
            applyTheme('light');
        });
    }

    if (themeDark) {
        themeDark.addEventListener('click', function () {
            applyTheme('dark');
        });
    }

    if (recoverForm) {
        recoverForm.addEventListener('submit', function (event) {
            if (!recoverForm.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }

            recoverForm.classList.add('was-validated');
        });
    }

    if (!form) {
        return;
    }

    [emailInput, passwordInput].forEach(function (field) {
        if (!field) {
            return;
        }

        const unlockField = function () {
            field.removeAttribute('readonly');
        };

        field.addEventListener('focus', unlockField);
        field.addEventListener('pointerdown', unlockField);
        field.addEventListener('keydown', unlockField);
    });

    const setEmailMessage = function () {
        if (!emailInput) {
            return;
        }

        const feedback = emailInput.parentElement.querySelector('.invalid-feedback');
        const value = emailInput.value.trim();
        let message = 'Ingresa un correo personal v\u00e1lido, m\u00e1ximo 60 caracteres.';

        emailInput.setCustomValidity('');

        if (value.length > 60) {
            message = 'El correo no puede superar 60 caracteres.';
            emailInput.setCustomValidity(message);
        } else if (value.length > 0 && !value.includes('@')) {
            message = 'El correo debe incluir @.';
            emailInput.setCustomValidity(message);
        } else if (value.length > 0) {
            const parts = value.split('@');
            const domain = parts[1] || '';

            if (parts.length !== 2 || parts[0].length === 0 || domain.length === 0) {
                message = 'Escribe un correo v\u00e1lido, por ejemplo nombre@gmail.com.';
                emailInput.setCustomValidity(message);
            } else if (!domain.includes('.')) {
                message = 'Al dominio le falta el punto, por ejemplo gmail.com.';
                emailInput.setCustomValidity(message);
            } else if (!emailInput.validity.valid) {
                message = 'Revisa el formato del correo.';
            }
        }

        if (feedback) {
            feedback.textContent = message;
        }
    };
    const setPasswordMessage = function () {
        if (!passwordInput) {
            return;
        }

        const feedback = passwordInput.parentElement.querySelector('.invalid-feedback');
        const value = passwordInput.value;
        const missing = [];
        let message = 'La contraseña es válida.';

        passwordInput.setCustomValidity('');

        if (value.length === 0) {
            message = 'La contraseña no puede estar vacía.';
            passwordInput.setCustomValidity(message);
        } else if (value.length < 8) {
            message = 'La contrasena debe tener minimo 8 caracteres.';
            passwordInput.setCustomValidity(message);
        } else if (value.length > 72) {
            message = 'La contraseña no puede superar 72 caracteres.';
            passwordInput.setCustomValidity(message);
        } else {
            if (!/[A-Z]/.test(value)) {
                missing.push('una mayuscula');
            }
            if (!/[a-z]/.test(value)) {
                missing.push('una minuscula');
            }
            if (!/\d/.test(value)) {
                missing.push('un numero');
            }
            if (!/[^A-Za-z0-9]/.test(value)) {
                missing.push('un caracter especial');
            }

            if (missing.length > 0) {
                message = 'Falta ' + missing.join(', ') + '.';
                passwordInput.setCustomValidity(message);
            }
        }

        if (feedback) {
            feedback.textContent = message;
        }
    };

    const validateField = function (field) {
        if (!field) {
            return;
        }

        if (field === emailInput) {
            setEmailMessage();
        }

        if (field === passwordInput) {
            setPasswordMessage();
        }

        field.classList.toggle('is-invalid', field.value.length > 0 && !field.checkValidity());
        field.classList.toggle('is-valid', field.value.length > 0 && field.checkValidity());
    };

    [emailInput, passwordInput].forEach(function (field) {
        if (!field) {
            return;
        }

        field.addEventListener('input', function () {
            validateField(field);
        });
    });

    form.addEventListener('submit', function (event) {
        if (emailInput) {
            emailInput.removeAttribute('readonly');
        }

        if (passwordInput) {
            passwordInput.removeAttribute('readonly');
        }

        validateField(emailInput);
        validateField(passwordInput);

        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }

        form.classList.add('was-validated');
    });

    if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener('click', function () {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            passwordToggle.setAttribute('aria-label', isPassword ? 'Ocultar contrase\u00f1a' : 'Mostrar contrase\u00f1a');
        });
    }

    document.querySelectorAll('textarea[data-char-counter]').forEach(function (textarea) {
        var max = parseInt(textarea.getAttribute('maxlength') || '220', 10);
        var counter = textarea.parentElement && textarea.parentElement.querySelector('.char-counter');
        if (!counter) {
            return;
        }
        function updateCounter() {
            var len = textarea.value.length;
            var remaining = max - len;
            counter.textContent = remaining + ' caracter' + (remaining === 1 ? '' : 'es') + ' disponible' + (remaining === 1 ? '' : 's');
            counter.style.color = remaining < 30 ? 'var(--ins-amber, #d98b09)' : '';
            if (remaining < 10) {
                counter.style.color = 'var(--ins-red, #dc3545)';
            }
        }
        textarea.addEventListener('input', updateCounter);
        updateCounter();
    });

    document.querySelectorAll('input[pattern]').forEach(function (input) {
        if (input.readOnly) {
            return;
        }
        input.addEventListener('invalid', function () {
            if (input.validity.patternMismatch) {
                input.setCustomValidity(input.getAttribute('title') || 'Formato inválido.');
            } else if (input.validity.tooShort) {
                input.setCustomValidity('Mínimo ' + input.getAttribute('minlength') + ' caracteres.');
            } else {
                input.setCustomValidity('');
            }
        });
        input.addEventListener('input', function () {
            input.setCustomValidity('');
        });
    });
});
