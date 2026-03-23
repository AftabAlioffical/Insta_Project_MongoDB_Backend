// auth.js

import { login, register, me } from './api.js';

export function saveToken(token) {
    localStorage.setItem('token', token);
}

export function saveUser(user) {
    const existingUser = getUser();
    localStorage.setItem('user', JSON.stringify({ ...existingUser, ...user }));
}

export function getToken() {
    return localStorage.getItem('token');
}

export function getUser() {
    try {
        return JSON.parse(localStorage.getItem('user') || '{}');
    } catch (err) {
        return {};
    }
}

export function logout() {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    window.location.href = '/login.html';
}

export async function requireAuth() {
    const token = getToken();
    if (!token) {
        logout();
        return null;
    }
    try {
        const res = await me();
        saveUser(res.data || {});
        return res.data || null;
    } catch (err) {
        logout();
        return null;
    }
}

// Handle Google OAuth callback token in URL
if (typeof window !== 'undefined') {
    const urlParams = new URLSearchParams(window.location.search);
    const tokenFromGoogle = urlParams.get('token');
    if (tokenFromGoogle && !getToken()) {
        saveToken(tokenFromGoogle);
        // Fetch user info with the token
        try {
            me().then(res => {
                saveUser(res.data || {});
                // Clean up URL and redirect
                window.history.replaceState({}, document.title, window.location.pathname);
                window.location.href = '/consumer-feed.html';
            }).catch(err => {
                console.error('Failed to fetch user info after Google OAuth:', err);
                logout();
            });
        } catch (err) {
            console.error('Error handling Google OAuth callback:', err);
            logout();
        }
    }
}

// When login form is submitted
if (document.getElementById('loginForm')) {
    const form = document.getElementById('loginForm');
    const alertContainer = document.getElementById('alertContainer');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertContainer.innerHTML = '';

        const email = form.email.value;
        const password = form.password.value;

        try {
            const res = await login(email, password);
            saveToken(res.data.token);
            saveUser(res.data.user);
            window.location.href = '/consumer-feed.html';
        } catch (err) {
            const message = err.message || 'Login failed';
            alertContainer.innerHTML = `<div class="alert alert-danger" role="alert">${message}</div>`;
        }
    });
}

// When signup form is submitted
if (document.getElementById('signupForm')) {
    const form = document.getElementById('signupForm');
    const alertContainer = document.getElementById('alertContainer');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertContainer.innerHTML = '';

        const email = form.email.value;
        const password = form.password.value;
        const displayName = form.displayName ? form.displayName.value.trim() : '';

        try {
            const res = await register(email, password, displayName);
            saveToken(res.data.token);
            saveUser(res.data.user);
            window.location.href = '/consumer-feed.html';
        } catch (err) {
            const message = err.message || 'Registration failed';
            alertContainer.innerHTML = `<div class="alert alert-danger" role="alert">${message}</div>`;
        }
    });
}

// Make logout available globally for onclick handlers
window.logout = logout;
