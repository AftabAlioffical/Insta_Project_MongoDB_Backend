const THEME_STORAGE_KEY = 'insta_theme';
const CONTACT_EMAIL = 'aftabaliofficial7652482@gmail.com';

function injectSettingsStyles() {
    if (document.getElementById('globalSettingsStyles')) {
        return;
    }

    const style = document.createElement('style');
    style.id = 'globalSettingsStyles';
    style.textContent = `
        .settings-nav-host {
            position: relative;
            display: flex;
            align-items: center;
            margin-left: 10px;
        }

        .settings-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #ffffff;
            color: #111827;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
        }

        .settings-toggle:hover {
            transform: translateY(-1px);
            background: #f8fafc;
            border-color: rgba(15, 23, 42, 0.18);
        }

        .settings-toggle svg {
            flex: 0 0 auto;
        }

        .settings-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(270px, calc(100vw - 24px));
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 16px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.14);
            padding: 10px;
            z-index: 1200;
            display: none;
        }

        .settings-nav-host.open .settings-menu {
            display: block;
        }

        .settings-menu-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7280;
            padding: 6px 10px 10px;
        }

        .settings-menu-section {
            padding: 4px 0;
        }

        .settings-menu-label {
            display: block;
            padding: 4px 10px 8px;
            font-size: 12px;
            color: #6b7280;
        }

        .settings-menu-item,
        .settings-menu-link {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border: none;
            background: transparent;
            color: #111827;
            text-decoration: none;
            padding: 10px;
            border-radius: 12px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.18s ease, color 0.18s ease;
        }

        .settings-menu-item:hover,
        .settings-menu-link:hover {
            background: #f3f4f6;
            color: #111827;
        }

        .settings-menu-item.is-active {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(99, 102, 241, 0.14));
            color: #1d4ed8;
            font-weight: 700;
        }

        .settings-menu-divider {
            height: 1px;
            background: rgba(15, 23, 42, 0.08);
            margin: 6px 0;
        }

        .settings-check {
            font-size: 12px;
            opacity: 0;
        }

        .settings-menu-item.is-active .settings-check {
            opacity: 1;
        }

        html[data-theme="dark"] {
            color-scheme: dark;
        }

        body[data-theme="dark"] {
            background: #0b1220 !important;
            color: #e5e7eb !important;
        }

        body[data-theme="dark"] .instagram-navbar,
        body[data-theme="dark"] .navbar,
        body[data-theme="dark"] .navbar.bg-white,
        body[data-theme="dark"] nav.bg-white {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.03) !important;
        }

        body[data-theme="dark"] .navbar-brand,
        body[data-theme="dark"] .nav-link,
        body[data-theme="dark"] .nav-icon,
        body[data-theme="dark"] .dropdown-item,
        body[data-theme="dark"] .dropdown-toggle,
        body[data-theme="dark"] .text-dark,
        body[data-theme="dark"] h1,
        body[data-theme="dark"] h2,
        body[data-theme="dark"] h3,
        body[data-theme="dark"] h4,
        body[data-theme="dark"] h5,
        body[data-theme="dark"] h6,
        body[data-theme="dark"] label,
        body[data-theme="dark"] strong,
        body[data-theme="dark"] .post-author-info h6,
        body[data-theme="dark"] .people-present-name,
        body[data-theme="dark"] .author-name {
            color: #f9fafb !important;
        }

        body[data-theme="dark"] p,
        body[data-theme="dark"] small,
        body[data-theme="dark"] li,
        body[data-theme="dark"] .text-muted,
        body[data-theme="dark"] .post-author-info small,
        body[data-theme="dark"] .caption-text,
        body[data-theme="dark"] .view-comments,
        body[data-theme="dark"] .people-present-sub,
        body[data-theme="dark"] .rating-display,
        body[data-theme="dark"] .app-copyright-footer {
            color: #cbd5e1 !important;
        }

        body[data-theme="dark"] .card,
        body[data-theme="dark"] .post-card,
        body[data-theme="dark"] .people-present-panel,
        body[data-theme="dark"] .modal-content,
        body[data-theme="dark"] .stat-card,
        body[data-theme="dark"] .settings-section,
        body[data-theme="dark"] .search-suggestions,
        body[data-theme="dark"] .app-copyright-footer,
        body[data-theme="dark"] .comment-input-wrapper,
        body[data-theme="dark"] .search-icon,
        body[data-theme="dark"] .settings-toggle,
        body[data-theme="dark"] .settings-menu,
        body[data-theme="dark"] .profile-card,
        body[data-theme="dark"] .feature-card,
        body[data-theme="dark"] .contact-card,
        body[data-theme="dark"] .about-hero-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            color: #e5e7eb !important;
        }

        body[data-theme="dark"] .comment-input-wrapper input,
        body[data-theme="dark"] .search-icon input,
        body[data-theme="dark"] .form-control,
        body[data-theme="dark"] .form-select,
        body[data-theme="dark"] textarea,
        body[data-theme="dark"] input,
        body[data-theme="dark"] select {
            background: #0f172a !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            color: #f8fafc !important;
        }

        body[data-theme="dark"] .form-control::placeholder,
        body[data-theme="dark"] .search-icon input::placeholder,
        body[data-theme="dark"] .comment-input-wrapper input::placeholder {
            color: #94a3b8 !important;
        }

        body[data-theme="dark"] .btn-outline-secondary,
        body[data-theme="dark"] .page-link {
            background: #0f172a;
            border-color: rgba(255, 255, 255, 0.08);
            color: #e5e7eb;
        }

        body[data-theme="dark"] .page-item.active .page-link,
        body[data-theme="dark"] .btn-primary,
        body[data-theme="dark"] .submit-comment-btn {
            color: #fff !important;
        }

        body[data-theme="dark"] .settings-menu-item:hover,
        body[data-theme="dark"] .settings-menu-link:hover,
        body[data-theme="dark"] .people-present-item:hover,
        body[data-theme="dark"] .search-suggestion-item:hover,
        body[data-theme="dark"] .presence-user:hover {
            background: #1f2937 !important;
        }

        body[data-theme="dark"] footer,
        body[data-theme="dark"] footer.bg-dark,
        body[data-theme="dark"] footer .bg-black {
            background: #030712 !important;
        }

        body[data-theme="dark"] a {
            color: #93c5fd;
        }

        @media (max-width: 768px) {
            .settings-toggle-label {
                display: none;
            }

            .settings-nav-host {
                margin-left: 8px;
            }

            .settings-toggle {
                padding: 8px 10px;
            }
        }

        @media (max-width: 576px) {
            .settings-menu {
                right: -8px;
            }
        }
    `;

    document.head.appendChild(style);
}

function getStoredTheme() {
    return localStorage.getItem(THEME_STORAGE_KEY) === 'dark' ? 'dark' : 'light';
}

function applyTheme(theme) {
    const normalized = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', normalized);
    document.body.setAttribute('data-theme', normalized);
    localStorage.setItem(THEME_STORAGE_KEY, normalized);

    document.querySelectorAll('[data-theme-choice]').forEach((item) => {
        const isActive = item.getAttribute('data-theme-choice') === normalized;
        item.classList.toggle('is-active', isActive);
        item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

function createSettingsMenu() {
    const host = document.createElement('div');
    host.className = 'settings-nav-host';
    host.innerHTML = `
        <button type="button" class="settings-toggle" aria-expanded="false" aria-label="Open settings menu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01A1.65 1.65 0 0 0 10.91 3H11a2 2 0 1 1 4 0h.09a1.65 1.65 0 0 0 1.51 1h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01A1.65 1.65 0 0 0 21 10.91V11a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span class="settings-toggle-label">Settings</span>
        </button>
        <div class="settings-menu" role="menu">
            <div class="settings-menu-title">Settings</div>
            <div class="settings-menu-section">
                <span class="settings-menu-label">Theme</span>
                <button type="button" class="settings-menu-item" data-theme-choice="light" role="menuitemradio">
                    <span>Day Mode</span>
                    <span class="settings-check">Active</span>
                </button>
                <button type="button" class="settings-menu-item" data-theme-choice="dark" role="menuitemradio">
                    <span>Dark Mode</span>
                    <span class="settings-check">Active</span>
                </button>
            </div>
            <div class="settings-menu-divider"></div>
            <a class="settings-menu-link" href="/about-us.html" role="menuitem">
                <span>About Us</span>
                <span>Open</span>
            </a>
            <a class="settings-menu-link" href="mailto:${CONTACT_EMAIL}" role="menuitem">
                <span>Contact Support</span>
                <span>Email</span>
            </a>
        </div>
    `;

    const toggle = host.querySelector('.settings-toggle');
    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        const isOpen = host.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    host.querySelectorAll('[data-theme-choice]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            applyTheme(button.getAttribute('data-theme-choice'));
            host.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });

    host.querySelectorAll('.settings-menu-link').forEach((link) => {
        link.addEventListener('click', () => {
            host.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });

    return host;
}

function attachSettingsMenu() {
    if (document.querySelector('.settings-nav-host')) {
        return;
    }

    let target = document.querySelector('.instagram-navbar .navbar-right');
    let hostElement = createSettingsMenu();

    if (target) {
        target.insertBefore(hostElement, target.querySelector('button[title="Logout"]') || null);
        return;
    }

    target = document.querySelector('.navbar-nav.ms-auto');
    if (target) {
        const item = document.createElement('li');
        item.className = 'nav-item d-flex align-items-center settings-list-item';
        item.appendChild(hostElement);
        target.appendChild(item);
        return;
    }

    target = document.querySelector('.navbar .container-fluid, .instagram-navbar .container-fluid');
    if (target) {
        const wrapper = document.createElement('div');
        wrapper.className = 'ms-auto d-flex align-items-center';
        wrapper.appendChild(hostElement);
        target.appendChild(wrapper);
    }
}

function closeMenusOnOutsideClick() {
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.settings-nav-host.open').forEach((host) => {
            if (!host.contains(event.target)) {
                host.classList.remove('open');
                const toggle = host.querySelector('.settings-toggle');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            }
        });
    });
}

function initializeSettings() {
    injectSettingsStyles();
    applyTheme(getStoredTheme());
    attachSettingsMenu();
    closeMenusOnOutsideClick();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSettings);
} else {
    initializeSettings();
}