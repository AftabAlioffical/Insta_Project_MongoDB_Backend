import './settings.js';

const FOOTER_TEXT = '| All Rights Reserved by aftab ali';
const CONTACT_EMAIL = 'aftabaliofficial7652482@gmail.com';

function injectStyles() {
    if (document.getElementById('globalFooterStyles')) {
        return;
    }

    const style = document.createElement('style');
    style.id = 'globalFooterStyles';
    style.textContent = `
        .app-copyright-footer {
            margin-top: 24px;
            padding: 14px 16px;
            text-align: center;
            font-size: 13px;
            color: #6b7280;
            background: rgba(255, 255, 255, 0.92);
            border-top: 1px solid rgba(0, 0, 0, 0.08);
        }

        .app-copyright-footer a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .app-copyright-footer a:hover {
            text-decoration: underline;
        }

        footer .app-copyright-footer {
            margin-top: 0;
            background: transparent;
            color: rgba(255, 255, 255, 0.82);
            border-top: 1px solid rgba(255, 255, 255, 0.12);
        }

        footer .app-copyright-footer a {
            color: #c7d2fe;
        }

        @media (max-width: 576px) {
            .app-copyright-footer {
                font-size: 12px;
                padding: 12px;
            }
        }
    `;

    document.head.appendChild(style);
}

function createFooterLine() {
    const line = document.createElement('div');
    line.className = 'app-copyright-footer';
    line.innerHTML = `${FOOTER_TEXT} | Contact Us: <a href="mailto:${CONTACT_EMAIL}">${CONTACT_EMAIL}</a>`;
    return line;
}

function attachFooter() {
    if (document.querySelector('.app-copyright-footer')) {
        return;
    }

    injectStyles();

    const existingFooter = document.querySelector('footer');
    if (existingFooter) {
        existingFooter.appendChild(createFooterLine());
        return;
    }

    document.body.appendChild(createFooterLine());
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachFooter);
} else {
    attachFooter();
}