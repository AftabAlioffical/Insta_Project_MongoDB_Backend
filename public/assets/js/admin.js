// admin.js

import { logout, requireAuth, getToken } from './auth.js';

async function loadUsers() {
    try {
        const token = getToken();
        const res = await fetch('/api/admin/users', {
            headers: { 'Authorization': `Bearer ${token}` }
        }).then(r => r.json());
        const container = document.getElementById('usersContainer');
        container.innerHTML = '';
        if (!res.data || res.data.length === 0) {
            container.innerHTML = '<p class="text-muted">No users.</p>';
            return;
        }
        res.data.forEach(user => {
            const div = document.createElement('div');
            div.className = 'card';
            div.innerHTML = `
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${user.email}</strong> <span class="text-muted">(${user.role})</span><br>
                        <small>${user.created_at}</small>
                    </div>
                    <button class="btn btn-sm btn-danger" onclick="deleteUser(${user.id})">Delete</button>
                </div>
            `;
            container.appendChild(div);
        });
    } catch (err) {
        console.error(err);
    }
}

async function deleteUser(id) {
    if (!confirm('Delete this user?')) return;
    try {
        const token = getToken();
        await fetch(`/api/admin/users/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        loadUsers();
    } catch (err) {
        console.error(err);
    }
}

if (document.getElementById('createUserForm')) {
    document.getElementById('createUserForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        const email = document.getElementById('userEmail').value;
        const password = document.getElementById('userPassword').value;
        const role = document.getElementById('userRole').value;
        const alertContainer = document.getElementById('alertContainer');
        alertContainer.innerHTML = '';

        try {
            const token = getToken();
            const res = await fetch('/api/admin/users', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email, password, role })
            }).then(r => r.json());

            if (!res.success) {
                throw res.error;
            }
            alertContainer.innerHTML = '<div class="alert alert-success">User created successfully</div>';
            document.getElementById('createUserForm').reset();
            loadUsers();
        } catch (err) {
            const msg = err.message || 'Failed to create user';
            alertContainer.innerHTML = `<div class="alert alert-danger">${msg}</div>`;
            console.error(err);
        }
    });
}

requireAuth().then(() => loadUsers());
