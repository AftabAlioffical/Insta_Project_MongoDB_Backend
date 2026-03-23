// api.js

const API_BASE = '/api';

export async function request(url, options = {}) {
    const token = localStorage.getItem('token');
    const hasFormDataBody = options.body instanceof FormData;

    const headers = {
        ...(options.headers || {})
    };

    if (!hasFormDataBody && !headers['Content-Type'] && !headers['content-type']) {
        headers['Content-Type'] = 'application/json';
    }

    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(url, {
        ...options,
        headers
    });

    const data = await response.json();
    if (!response.ok) {
        throw data.error || { message: 'API request failed' };
    }
    return data;
}


export async function login(email, password) {
    return request(`${API_BASE}/auth/login`, {
        method: 'POST',
        body: JSON.stringify({ email, password })
    });
}

export async function register(email, password, displayName = '') {
    const payload = { email, password };
    if (displayName && String(displayName).trim() !== '') {
        payload.displayName = String(displayName).trim();
    }

    return request(`${API_BASE}/auth/register`, {
        method: 'POST',
        body: JSON.stringify(payload)
    });
}

export async function me() {
    return request(`${API_BASE}/auth/me`, { method: 'GET' });
}

export async function fetchFeed(page = 1) {
    return request(`${API_BASE}/media?page=${page}`, { method: 'GET' });
}

export async function fetchMedia(id) {
    return request(`${API_BASE}/media/${id}`, { method: 'GET' });
}

export async function addComment(mediaId, text) {
    return request(`${API_BASE}/media/${mediaId}/comments`, {
        method: 'POST',
        body: JSON.stringify({ text })
    });
}

export async function rateMedia(mediaId, value) {
    return request(`${API_BASE}/media/${mediaId}/ratings`, {
        method: 'POST',
        body: JSON.stringify({ value })
    });
}

export async function searchMedia(query, id, name, location, person, page = 1) {
    const params = new URLSearchParams();
    if (query) params.append('q', query);
    if (id) params.append('id', id);
    if (name) params.append('name', name);
    if (location) params.append('location', location);
    if (person) params.append('person', person);
    params.append('page', page);
    return request(`${API_BASE}/search?${params.toString()}`, { method: 'GET' });
}

export async function getLikes(mediaId) {
    return request(`${API_BASE}/media/${mediaId}/likes`, { method: 'GET' });
}

export async function toggleLike(mediaId) {
    return request(`${API_BASE}/media/${mediaId}/likes`, { method: 'POST' });
}

export async function getUserProfile(userId) {
    return request(`${API_BASE}/users/${userId}`, { method: 'GET' });
}

export async function searchUsers(query) {
    return request(`${API_BASE}/users/search?q=${encodeURIComponent(query)}`, { method: 'GET' });
}

export async function updateMyProfile(payload) {
    return request(`${API_BASE}/users/me`, {
        method: 'PUT',
        body: JSON.stringify(payload)
    });
}

export async function uploadMyAvatar(file) {
    const formData = new FormData();
    formData.append('avatar', file);

    return request(`${API_BASE}/users/me/avatar`, {
        method: 'POST',
        body: formData
    });
}
