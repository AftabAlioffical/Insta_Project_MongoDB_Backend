// media-detail.js

import { fetchMedia, addComment, request, getLikes, toggleLike, rateMedia } from './api.js';
import { requireAuth } from './auth.js';

let currentMediaId = null;
let isLiked = false;
let currentUser = null;
let selectedRating = 0;

function avatarFallback(label) {
    const initial = (String(label || 'U').trim().charAt(0) || 'U').toUpperCase();
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><rect width="50" height="50" rx="25" fill="#667eea"/><text x="50%" y="58%" text-anchor="middle" font-family="Arial, sans-serif" font-size="20" fill="#ffffff">${initial}</text></svg>`;
    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
}

function renderMedia(media) {
    const mediaUrl = media.url || '';
    const title = media.title || 'Untitled';

    if (media.type === 'video') {
        return `
            <video src="${mediaUrl}" class="card-img-top" controls style="max-height: 700px; object-fit: contain; background: #000;"></video>
            <div class="card-body border-top">
                <a href="${mediaUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">Open original video</a>
            </div>
        `;
    }

    return `
        <a href="${mediaUrl}" target="_blank" rel="noopener noreferrer" class="d-block bg-light text-center">
            <img src="${mediaUrl}" class="card-img-top" alt="${title}" style="max-height: 700px; object-fit: contain; background: #f8f9fa;">
        </a>
        <div class="card-body border-top">
            <a href="${mediaUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">View original image</a>
        </div>
    `;
}

async function loadMedia() {
    const params = new URLSearchParams(window.location.search);
    const id = params.get('id');
    if (!id) return;

    currentMediaId = id;

    try {
        const res = await fetchMedia(id);
        const media = res.data;
        const container = document.getElementById('mediaContainer');
        const creatorName = media.creatorName || media.creator_email?.split('@')[0] || media.creatorEmail?.split('@')[0] || 'User';
        
        container.innerHTML = `
            <div class="card">
                ${renderMedia(media)}
                <div class="card-body">
                    <h4>${media.title || 'Untitled'}</h4>
                    <p>${media.caption || 'No caption provided.'}</p>
                    <p><small class="text-muted">${media.location || 'No location provided'}</small></p>
                    <p>
                        <small class="text-muted" id="ratingSummary">
                            ${media.ratings && media.ratings.average ? Number(media.ratings.average).toFixed(1) : '0.0'} ★ (${media.ratings && media.ratings.count ? Number(media.ratings.count) : 0} ratings)
                        </small>
                    </p>
                    <p>
                        <small class="text-muted">
                            Tags: ${(media.tags || []).map(t => t.name).join(', ') || 'No tags'}
                        </small>
                    </p>
                    <div class="mb-3" id="ratingStars">
                        <span class="star" data-value="1" onclick="setMediaRating(1)">★</span>
                        <span class="star" data-value="2" onclick="setMediaRating(2)">★</span>
                        <span class="star" data-value="3" onclick="setMediaRating(3)">★</span>
                        <span class="star" data-value="4" onclick="setMediaRating(4)">★</span>
                        <span class="star" data-value="5" onclick="setMediaRating(5)">★</span>
                        <small class="text-muted ms-2" id="userRatingHint">Click a star to rate this post</small>
                    </div>
                    <div class="mt-3">
                        <button class="btn btn-sm btn-outline-danger me-2" id="likeBtn" onclick="toggleLikeMedia()">
                            <span id="likeIcon">♡</span> <span id="likeCount">0</span> Likes
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Load likes
        const likesRes = await getLikes(id);
        document.getElementById('likeCount').textContent = likesRes.data.count;
        isLiked = likesRes.data.userLiked;
        updateLikeButton();

        await loadRatings(id);
        
        const email = media.creator_email || media.creatorEmail || '';
        const creatorAvatar = document.getElementById('creatorInitials').parentElement;
        
        if (media.creatorAvatarUrl || media.creator_avatar_url) {
            const avatarUrl = media.creatorAvatarUrl || media.creator_avatar_url;
            creatorAvatar.innerHTML = `<img src="${avatarUrl}" alt="${creatorName}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
        } else {
            creatorAvatar.innerHTML = `<img src="${avatarFallback(creatorName)}" alt="${creatorName}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
        }

        creatorAvatar.style.cursor = 'pointer';
        creatorAvatar.onclick = () => {
            window.location.href = `/user-profile.html?id=${media.creator_id}`;
        };
        document.getElementById('creatorEmail').textContent = creatorName;
        document.getElementById('creatorEmail').title = email;
        document.getElementById('creatorEmail').style.cursor = 'pointer';
        document.getElementById('creatorEmail').onclick = () => {
            window.location.href = `/user-profile.html?id=${media.creator_id}`;
        };
        document.getElementById('postDate').textContent = new Date(media.created_at).toLocaleDateString();
    } catch (err) {
        console.error(err);
    }
}

function updateRatingStars(value) {
    document.querySelectorAll('#ratingStars .star').forEach((star) => {
        const starValue = Number(star.dataset.value || 0);
        star.classList.toggle('text-warning', starValue <= value);
    });
}

async function loadRatings(mediaId) {
    try {
        const res = await request(`/api/media/${mediaId}/ratings`, { method: 'GET' });
        const ratings = Array.isArray(res.data?.ratings) ? res.data.ratings : [];
        const stats = res.data?.statistics || {};

        const currentUserRating = ratings.find((rating) => Number(rating.user_id) === Number(currentUser?.id));
        selectedRating = Number(currentUserRating?.value || 0);

        updateRatingStars(selectedRating);

        const summary = document.getElementById('ratingSummary');
        if (summary) {
            summary.textContent = `${Number(stats.averageRating || 0).toFixed(1)} ★ (${Number(stats.totalRatings || 0)} ratings)`;
        }

        const hint = document.getElementById('userRatingHint');
        if (hint) {
            hint.textContent = selectedRating > 0
                ? `Your rating: ${selectedRating} star${selectedRating > 1 ? 's' : ''}`
                : 'Click a star to rate this post';
        }
    } catch (err) {
        console.log('Ratings unavailable for this media item');
    }
}

async function setMediaRating(value) {
    if (!currentMediaId) {
        return;
    }

    selectedRating = Number(value || 0);
    if (selectedRating < 1 || selectedRating > 5) {
        return;
    }

    try {
        await rateMedia(currentMediaId, selectedRating);
        await loadRatings(currentMediaId);
    } catch (err) {
        alert('Unable to submit rating right now.');
    }
}

function updateLikeButton() {
    const btn = document.getElementById('likeBtn');
    const icon = document.getElementById('likeIcon');
    if (isLiked) {
        icon.textContent = '♥';
        btn.classList.remove('btn-outline-danger');
        btn.classList.add('btn-danger', 'text-white');
    } else {
        icon.textContent = '♡';
        btn.classList.add('btn-outline-danger');
        btn.classList.remove('btn-danger', 'text-white');
    }
}

async function toggleLikeMedia() {
    try {
        const res = await toggleLike(currentMediaId);
        isLiked = res.data.liked;
        document.getElementById('likeCount').textContent = res.data.count;
        updateLikeButton();
    } catch (err) {
        alert('Error: ' + (err.message || 'Unknown error'));
    }
}

window.toggleLikeMedia = toggleLikeMedia;
window.setMediaRating = setMediaRating;

async function loadComments() {
    const params = new URLSearchParams(window.location.search);
    const id = params.get('id');
    if (!id) return;

    try {
        const res = await request(`/api/media/${id}/comments`, { method: 'GET' });
        const container = document.getElementById('commentsContainer');
        container.innerHTML = '';
        
        if (!res.data || res.data.length === 0) {
            container.innerHTML = '<p class="text-muted">No comments yet. Be the first to comment!</p>';
            return;
        }
        
        res.data.forEach(c => {
            // Get user initials for avatar
            const email = c.user_email || 'Anonymous';
            const displayName = c.user_name || email;
            const initials = email.split('@')[0].substring(0, 2).toUpperCase();
            const avatarColor = `hsl(${email.charCodeAt(0) * 10}, 70%, 60%)`;
            
            const div = document.createElement('div');
            div.className = 'mb-3 pb-2 border-bottom';
            div.innerHTML = `
                <div class="d-flex gap-2">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: ${avatarColor}; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px; flex-shrink: 0; cursor: pointer;" onclick="window.location.href='/user-profile.html?id=${c.user_id}'">
                        ${initials}
                    </div>
                    <div style="flex: 1;">
                        <div>
                            <strong style="font-size: 13px; cursor: pointer; color: #0d6efd;" onclick="window.location.href='/user-profile.html?id=${c.user_id}'">${displayName}</strong>
                            <small class="text-muted ms-2">${new Date(c.created_at).toLocaleDateString()}</small>
                        </div>
                        <p class="mb-0" style="font-size: 14px; margin-top: 4px;">${c.text}</p>
                    </div>
                </div>
            `;
            container.appendChild(div);
        });
    } catch (err) {
        console.error(err);
        const container = document.getElementById('commentsContainer');
        if (container) {
            container.innerHTML = '<p class="text-danger">Failed to load comments</p>';
        }
    }
}

if (document.getElementById('commentForm')) {
    document.getElementById('commentForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = document.getElementById('commentText').value;
        const params = new URLSearchParams(window.location.search);
        const id = params.get('id');
        
        if (!text.trim()) {
            alert('Please enter a comment');
            return;
        }
        
        try {
            console.log('Submitting comment for media:', id);
            const result = await addComment(id, text);
            console.log('Comment added successfully:', result);
            
            document.getElementById('commentText').value = '';
            
            console.log('Loading comments...');
            await loadComments();
            console.log('Comments loaded');
        } catch (err) {
            console.error('Comment submission error:', err);
            alert('Failed to post comment: ' + (err.message || 'Unknown error'));
        }
    });
}

requireAuth().then((user) => {
    if (!user) {
        return;
    }

    currentUser = user;
    loadMedia();
    loadComments();
});
