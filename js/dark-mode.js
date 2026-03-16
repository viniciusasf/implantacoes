/* js/dark-mode.js - Lógica do Modo Escuro */
document.addEventListener('DOMContentLoaded', () => {
    const toggleButton = document.getElementById('theme-toggle');
    const toggleIcon = document.getElementById('theme-toggle-icon');
    const currentTheme = localStorage.getItem('theme');

    // Função para aplicar o tema
    const applyTheme = (theme) => {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            if (toggleIcon) {
                toggleIcon.classList.remove('bi-moon-stars');
                toggleIcon.classList.add('bi-sun-fill');
            }
            if (toggleButton) {
                toggleButton.setAttribute('title', 'Alternar Tema Claro');
            }
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (toggleIcon) {
                toggleIcon.classList.remove('bi-sun-fill');
                toggleIcon.classList.add('bi-moon-stars');
            }
            if (toggleButton) {
                toggleButton.setAttribute('title', 'Alternar Tema Escuro');
            }
        }
    };

    // Detecta preferência do sistema se não houver preferência salva
    if (!currentTheme) {
        const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
        if (prefersDarkScheme.matches) {
            applyTheme('dark');
            localStorage.setItem('theme', 'dark');
        } else {
            applyTheme('light');
        }
    } else {
        applyTheme(currentTheme);
    }

    // Listener do botão de alternar
    if (toggleButton) {
        toggleButton.addEventListener('click', () => {
            const currentDataTheme = document.documentElement.getAttribute('data-theme');
            let newTheme = 'light';

            if (currentDataTheme !== 'dark') {
                newTheme = 'dark';
            }

            localStorage.setItem('theme', newTheme);
            applyTheme(newTheme);
        });
    }

    // Opcional: ouvir mudanças no sistema
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (!localStorage.getItem('theme')) {
            const newTheme = e.matches ? "dark" : "light";
            applyTheme(newTheme);
        }
    });
});
