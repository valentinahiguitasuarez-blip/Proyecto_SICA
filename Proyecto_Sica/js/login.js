document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('loginForm');
    const recoverForm = document.getElementById('recoverForm');
    const emailInput = document.getElementById('correo');
    const passwordInput = document.getElementById('contrasena');
    const passwordToggle = document.querySelector('.password-eye');
    const themeToggle = document.querySelector('.theme-toggle');
    const themeLight = document.querySelector('.theme-light');
    const themeDark = document.querySelector('.theme-dark');

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
        let message = 'La contrase\u00f1a debe tener entre 6 y 72 caracteres.';

        passwordInput.setCustomValidity('');

        if (value.length > 72) {
            message = 'La contrase\u00f1a no puede superar 72 caracteres.';
            passwordInput.setCustomValidity(message);
        } else if (value.length > 0 && value.length < 6) {
            message = 'La contrase\u00f1a debe tener al menos 6 caracteres.';
            passwordInput.setCustomValidity(message);
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
});
