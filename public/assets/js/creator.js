// creator.js

import { logout, requireAuth, getToken } from './auth.js';

async function loadUploads() {
    try {
        const token = getToken();
        // call /api/media?creator_id=
        const res = await fetch('/api/media?page=1&creator_id=me', {
            headers: { 'Authorization': `Bearer ${token}` }
        }).then(r => r.json());

        const container = document.getElementById('uploadsContainer');
        container.innerHTML = '';
        if (!res.data || res.data.length === 0) {
            container.innerHTML = '<p class="text-muted">No uploads yet.</p>';
            return;
        }
        res.data.forEach(item => {
            const div = document.createElement('div');
            div.className = 'card';
            div.innerHTML = `
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${item.title}</strong><br>
                        <small>${item.created_at}</small>
                    </div>
                    <button class="btn btn-sm btn-danger" onclick="deleteMedia(${item.id})">Delete</button>
                </div>
            `;
            container.appendChild(div);
        });
    } catch (err) {
        console.error(err);
    }
}

async function deleteMedia(id) {
    if (!confirm('Delete this media?')) return;
    try {
        const token = getToken();
        await fetch(`/api/media/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        loadUploads();
    } catch (err) {
        console.error(err);
    }
}

if (document.getElementById('uploadForm')) {
    document.getElementById('uploadForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const data = new FormData(form);
        try {
            const token = getToken();
            const res = await fetch('/api/media', {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${token}` },
                body: data
            });
            const json = await res.json();
            if (!res.ok) {
                throw json.error;
            }
            form.reset();
            loadUploads();
        } catch (err) {
            console.error(err);
            alert(err.message || 'Upload failed');
        }
    });
}

requireAuth().then(() => loadUploads());
