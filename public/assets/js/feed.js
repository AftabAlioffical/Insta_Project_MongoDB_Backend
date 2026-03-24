// feed.js - Instagram-like Interactive Feed

import { fetchFeed, addComment, toggleLike, getLikes, rateMedia, request, searchUsers, fetchPeoplePresent } from './api.js';
import { logout, requireAuth } from './auth.js';

const likedPosts = new Set();
const commentData = {};
const clickTimers = new Map();
let currentUser = null;

function canViewPeoplePresent(user) {
    const role = String(user?.role || '').toUpperCase();
    return role === 'ADMIN' || role === 'CREATOR';
}

function formatAverageRating(value) {
    const numeric = Number(value || 0);
    return Number.isFinite(numeric) ? numeric.toFixed(1) : '0.0';
}

window.logout = logout;

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function avatarFallback(label) {
    const initial = (String(label || 'U').trim().charAt(0) || 'U').toUpperCase();
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 56 56"><rect width="56" height="56" rx="28" fill="#667eea"/><text x="50%" y="58%" text-anchor="middle" font-family="Arial, sans-serif" font-size="24" fill="#ffffff">${initial}</text></svg>`;
    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
}

function getPostOwnerId(post) {
    return post.creator_id || post.creatorId || post.user_id || post.userId || 0;
}

function getPostAuthorName(post) {
    return post.creatorName || post.creator_display_name || post.creatorDisplayName || post.creator_email?.split('@')[0] || post.creatorEmail?.split('@')[0] || 'User';
}

function getPostAuthorAvatar(post) {
    return post.creatorAvatarUrl || post.creator_avatar_url || avatarFallback(getPostAuthorName(post));
}

function renderPostMedia(post) {
    const mediaUrl = post.url || '';

    if (!mediaUrl) {
        return '<div class="post-image d-flex align-items-center justify-content-center text-muted" style="background:#f3f3f3;">Media unavailable</div>';
    }

    if (post.type === 'video') {
        return `<video src="${mediaUrl}" class="post-image" controls muted playsinline preload="metadata" data-hover-play="true"></video>`;
    }

    return `<img src="${mediaUrl}" class="post-image" alt="${escapeHtml(post.title || 'Post image')}" onerror="this.style.display='none'; this.insertAdjacentHTML('afterend', '<div class=&quot;post-image d-flex align-items-center justify-content-center text-muted&quot; style=&quot;background:#f3f3f3;&quot;>Image unavailable</div>')">`;
}

function bindHoverPreviewVideo(video) {
    if (!video || video.dataset.hoverBound === '1') {
        return;
    }

    video.dataset.hoverBound = '1';

    video.addEventListener('mouseenter', () => {
        video.play().catch(() => {
            // Autoplay can be blocked by the browser before user interaction.
        });
    });

    video.addEventListener('mouseleave', () => {
        video.pause();
        video.currentTime = 0;
    });

    // Keep video controls usable without triggering card navigation.
    video.addEventListener('click', (event) => event.stopPropagation());
    video.addEventListener('dblclick', (event) => event.stopPropagation());
}

function updatePostRatingUI(postId, selectedValue, averageValue, totalRatings) {
    document.querySelectorAll(`#ratingStars-${postId} .star`).forEach((star) => {
        const starValue = Number(star.dataset.value || 0);
        star.classList.toggle('text-warning', starValue <= selectedValue);
    });

    const meta = document.getElementById(`ratingMeta-${postId}`);
    if (meta) {
        meta.textContent = `${formatAverageRating(averageValue)} ★ (${Number(totalRatings || 0)})`;
    }
}

function hydrateNavbar(user) {
    const profileDropdown = document.getElementById('profileDropdown');
    if (profileDropdown) {
        profileDropdown.src = user.avatarUrl || avatarFallback(user.displayName || user.email || 'User');
    }

    const myProfileLink = document.querySelector('.dropdown-item[href="user-profile.html"]');
    if (myProfileLink && user.id) {
        myProfileLink.href = `/user-profile.html?id=${user.id}`;
    }

    const adminLink = document.getElementById('adminMenuLink');
    if (adminLink) {
        adminLink.style.display = user.role === 'ADMIN' ? 'block' : 'none';
    }
}

function setupGlobalSearch() {
    const input = document.getElementById('globalSearch');
    const suggestionsBox = document.getElementById('searchSuggestions');
    if (!input) {
        return;
    }

    let debounce = null;

    function hideSuggestions() {
        if (suggestionsBox) {
            suggestionsBox.classList.remove('show');
            suggestionsBox.innerHTML = '';
        }
    }

    function escapeHtml(text) {
        return String(text ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    }

    function renderSuggestions(users) {
        if (!suggestionsBox) return;
        suggestionsBox.innerHTML = '';

        if (!users || users.length === 0) {
            suggestionsBox.innerHTML = '<div class="search-suggestion-msg">No accounts found</div>';
            suggestionsBox.classList.add('show');
            return;
        }

        users.forEach((user) => {
            const displayName = user.displayName || user.email.split('@')[0];
            const initial = displayName.charAt(0).toUpperCase();
            const avatarHtml = user.avatarUrl
                ? `<img class="search-suggestion-avatar" src="${escapeHtml(user.avatarUrl)}" alt="${escapeHtml(displayName)}">`
                : `<div class="search-suggestion-avatar-placeholder">${escapeHtml(initial)}</div>`;

            const item = document.createElement('a');
            item.className = 'search-suggestion-item';
            item.href = `/user-profile.html?id=${user.id}`;
            item.innerHTML = `
                ${avatarHtml}
                <div>
                    <div class="search-suggestion-name">${escapeHtml(displayName)}</div>
                    <div class="search-suggestion-role">${escapeHtml(user.role || '')}</div>
                </div>
            `;
            item.addEventListener('mousedown', (e) => e.preventDefault());
            suggestionsBox.appendChild(item);
        });

        suggestionsBox.classList.add('show');
    }

    async function fetchSuggestions(query) {
        try {
            const res = await searchUsers(query);
            const users = Array.isArray(res.data) ? res.data : [];
            renderSuggestions(users);
        } catch (_) {
            hideSuggestions();
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(debounce);
        const value = input.value.trim();
        if (value.length < 1) {
            hideSuggestions();
            return;
        }
        debounce = setTimeout(() => fetchSuggestions(value), 200);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideSuggestions();
            return;
        }
        if (event.key !== 'Enter') {
            return;
        }
        hideSuggestions();
        const value = input.value.trim();
        if (!value) {
            window.location.href = '/search.html';
            return;
        }
        window.location.href = `/search.html?q=${encodeURIComponent(value)}`;
    });

    input.addEventListener('blur', () => {
        setTimeout(hideSuggestions, 150);
    });

    input.addEventListener('focus', () => {
        const value = input.value.trim();
        if (value.length >= 1) {
            fetchSuggestions(value);
        }
    });
}

function formatPresence(minutesAgo) {
    const value = Number(minutesAgo || 0);
    if (value <= 1) {
        return 'Just now';
    }
    if (value < 60) {
        return `${value}m ago`;
    }

    const hours = Math.floor(value / 60);
    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

function ensurePeoplePresentContainer() {
    let panel = document.getElementById('peoplePresentPanel');
    if (panel) {
        return panel;
    }

    panel = document.createElement('aside');
    panel.id = 'peoplePresentPanel';
    panel.className = 'people-present-panel';
    panel.innerHTML = `
        <div class="people-present-header">
            <h6>People Present</h6>
            <small>Recently active</small>
        </div>
        <div id="peoplePresentList" class="people-present-list">
            <div class="people-present-empty">Loading...</div>
        </div>
    `;

    document.body.appendChild(panel);
    return panel;
}

function renderPeoplePresent(users) {
    const list = document.getElementById('peoplePresentList');
    if (!list) {
        return;
    }

    if (!Array.isArray(users) || users.length === 0) {
        list.innerHTML = '<div class="people-present-empty">No active users right now</div>';
        return;
    }

    list.innerHTML = users.map((user) => {
        const displayName = escapeHtml(user.displayName || user.email?.split('@')[0] || 'User');
        const role = escapeHtml((user.role || '').toUpperCase());
        const statusClass = user.isActive ? 'online' : 'away';
        const avatar = user.avatarUrl
            ? `<img class="people-present-avatar" src="${escapeHtml(user.avatarUrl)}" alt="${displayName}">`
            : `<div class="people-present-avatar people-present-avatar-fallback">${escapeHtml((displayName.charAt(0) || 'U').toUpperCase())}</div>`;

        return `
            <a class="people-present-item" href="/user-profile.html?id=${Number(user.id || 0)}">
                <div class="people-present-avatar-wrap">
                    ${avatar}
                    <span class="people-present-dot ${statusClass}"></span>
                </div>
                <div class="people-present-meta">
                    <div class="people-present-name">${displayName}</div>
                    <div class="people-present-sub">${role} • ${formatPresence(user.minutesAgo)}</div>
                </div>
            </a>
        `;
    }).join('');
}

async function loadPeoplePresent() {
    if (!canViewPeoplePresent(currentUser)) {
        const panel = document.getElementById('peoplePresentPanel');
        if (panel) {
            panel.remove();
        }
        return;
    }

    ensurePeoplePresentContainer();

    try {
        const res = await fetchPeoplePresent(8, 24);
        renderPeoplePresent(Array.isArray(res.data) ? res.data : []);
    } catch (_) {
        const list = document.getElementById('peoplePresentList');
        if (list) {
            list.innerHTML = '<div class="people-present-empty">Unable to load presence right now</div>';
        }
    }
}

function showShareToast(message, isError = false) {
    let container = document.getElementById('shareToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'shareToastContainer';
        container.className = 'share-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `share-toast ${isError ? 'error' : 'success'}`;
    toast.textContent = message;
    container.appendChild(toast);

    window.setTimeout(() => {
        toast.classList.add('fade-out');
        window.setTimeout(() => toast.remove(), 260);
    }, 2200);
}

async function fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.setAttribute('readonly', 'readonly');
    textArea.style.position = 'fixed';
    textArea.style.top = '-1000px';
    textArea.style.left = '-1000px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch (_) {
        copied = false;
    } finally {
        textArea.remove();
    }

    return copied;
}

async function sharePost(postId) {
    const shareUrl = `${window.location.origin}/media-detail.html?id=${postId}`;
    const shareText = 'Check out this post on InstaShare';

    if (navigator.share) {
        try {
            await navigator.share({
                title: 'InstaShare Post',
                text: shareText,
                url: shareUrl
            });
            return;
        } catch (err) {
            if (err && err.name === 'AbortError') {
                return;
            }
        }
    }

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(shareUrl);
            showShareToast('Post link copied to clipboard');
            return;
        }
    } catch (_) {
        // Clipboard permissions can be blocked in some browsers.
    }

    const copied = await fallbackCopyToClipboard(shareUrl);
    if (copied) {
        showShareToast('Post link copied to clipboard');
        return;
    }

    showShareToast('Unable to copy automatically. Open media detail and copy URL.', true);
}

async function renderFeed(page = 1) {
    try {
        const res = await fetchFeed(page);
        displayFeed(res, page);
    } catch (err) {
        console.log('API fetch failed:', err.message);
        // Show error message
        const container = document.getElementById('feedContainer');
        if (container) {
            container.innerHTML = '<div class="alert alert-warning">Unable to load feed. Please refresh.</div>';
        }
    }
}


function displayFeed(res, page = 1) {
    try {
        const container = document.getElementById('feedContainer');
        const pagination = document.getElementById('pagination');
        
        if (!container) {
            console.error('Feed container not found');
            return;
        }
        
        container.innerHTML = '';
        if (pagination) pagination.innerHTML = '';

        if (!res.data || res.data.length === 0) {
            container.innerHTML = '<div class="alert alert-info text-center mt-4">No posts available</div>';
            return;
        }

        // Add feed-container class for proper padding
        container.className = 'feed-container';

        res.data.forEach((item, index) => {
            const postCard = createPostCard(item);
            container.appendChild(postCard);
            
            // Load like count and check if user already liked
            loadLikeStatus(item.id);
            // Load comments
            loadComments(item.id);

            // Load rating stats and current user's rating
            loadRatingStatus(item.id);
        });

        // Render pagination
        if (pagination && res.pagination && res.pagination.totalPages > 1) {
            renderPagination(pagination, page, res.pagination.totalPages);
        }
    } catch (err) {
        console.error('Error displaying feed:', err);
    }
}

function createPostCard(post) {
    const card = document.createElement('div');
    card.className = 'post-card';
    card.id = `post-${post.id}`;
    const ownerId = getPostOwnerId(post);
    const authorName = getPostAuthorName(post);
    const creatorAvatar = getPostAuthorAvatar(post);
    const ratingAverage = formatAverageRating(post.ratings?.average);
    const ratingCount = Number(post.ratings?.count || 0);
    
    card.innerHTML = `
        <!-- Post Header -->
        <div class="post-header">
            <div class="post-author" onclick="goToProfile(${ownerId})">
                <img src="${creatorAvatar}" class="post-author-avatar" alt="Avatar" onerror="this.src='${avatarFallback(authorName)}'">
                <div class="post-author-info">
                    <h6>${escapeHtml(authorName)}</h6>
                    <small>${escapeHtml(post.location || 'No location')}</small>
                </div>
            </div>
        </div>

        <!-- Post Image -->
        <div style="position: relative; cursor: pointer; user-select: none;" onclick="handleMediaClick(${post.id}, event)" ondblclick="handleMediaDoubleClick(${post.id}, event, this)">
            ${renderPostMedia(post)}
            <div id="doubleClickHeart-${post.id}" style="position: absolute; top: 50%; left: 50%; display: none;"></div>
        </div>

        <!-- Post Actions -->
        <div class="post-actions">
            <div class="actions-left">
                <button class="action-btn" id="likeBtn-${post.id}" onclick="toggleLikePost(${post.id})" title="Like">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                </button>
                <button class="action-btn" onclick="openCommentModal(${post.id})" title="Comment">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                </button>
                <button class="action-btn" onclick="sharePost(${post.id})" title="Share">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="18" cy="5" r="3"></circle>
                        <circle cx="6" cy="12" r="3"></circle>
                        <circle cx="18" cy="19" r="3"></circle>
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                    </svg>
                </button>
            </div>
            <div class="actions-right">
                <button class="action-btn" id="saveBtn-${post.id}" onclick="toggleSavePost(${post.id})" title="Save">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Like Count -->
        <div class="like-count">
            <span id="likeCount-${post.id}">${post.likesCount || 0}</span> likes
        </div>

        <!-- Post Caption -->
        <div class="post-caption">
            <div class="caption-text">
                <span class="author-name" onclick="goToProfile(${ownerId})">${escapeHtml(authorName)}</span>
                ${escapeHtml(post.caption || '')}
            </div>
        </div>

        <div class="rating-display" style="padding: 0 16px 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span>Rate:</span>
            <div id="ratingStars-${post.id}">
                <span class="star" data-value="1" onclick="setPostRating(${post.id}, 1, event)">★</span>
                <span class="star" data-value="2" onclick="setPostRating(${post.id}, 2, event)">★</span>
                <span class="star" data-value="3" onclick="setPostRating(${post.id}, 3, event)">★</span>
                <span class="star" data-value="4" onclick="setPostRating(${post.id}, 4, event)">★</span>
                <span class="star" data-value="5" onclick="setPostRating(${post.id}, 5, event)">★</span>
            </div>
            <small id="ratingMeta-${post.id}" class="text-muted">${ratingAverage} ★ (${ratingCount})</small>
        </div>

        <!-- View Comments Link -->
        <div class="view-comments" onclick="openCommentModal(${post.id})">
            View all <span id="commentCount-${post.id}">${post.commentsCount || 0}</span> comments
        </div>

        <!-- Comments Preview -->
        <div class="comments-preview" id="commentsPreview-${post.id}"></div>

        <!-- Add Comment Section -->
        <div class="add-comment-section">
            <div class="comment-input-wrapper">
                <input type="text" placeholder="Add a comment..." id="commentInput-${post.id}" onkeypress="handleCommentKeypress(event, ${post.id})">
            </div>
            <button class="submit-comment-btn" onclick="submitComment(${post.id})">Post</button>
        </div>
    `;

    card.querySelectorAll('video[data-hover-play="true"]').forEach(bindHoverPreviewVideo);
    
    return card;
}

async function loadRatingStatus(postId) {
    try {
        const res = await request(`/api/media/${postId}/ratings`, { method: 'GET' });
        const ratings = Array.isArray(res.data?.ratings) ? res.data.ratings : [];
        const stats = res.data?.statistics || {};

        const existing = ratings.find((rating) => Number(rating.user_id) === Number(currentUser?.id));
        const selectedValue = Number(existing?.value || 0);

        updatePostRatingUI(
            postId,
            selectedValue,
            stats.averageRating || 0,
            stats.totalRatings || 0
        );
    } catch (err) {
        console.log('Rating data not available for post', postId);
    }
}

async function setPostRating(postId, value, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    try {
        await rateMedia(postId, value);
        await loadRatingStatus(postId);
    } catch (err) {
        showShareToast('Unable to rate this post right now.', true);
    }
}


async function loadLikeStatus(postId) {
    try {
        const res = await getLikes(postId);
        const btn = document.getElementById(`likeBtn-${postId}`);
        const countSpan = document.getElementById(`likeCount-${postId}`);
        
        if (res.data) {
            countSpan.textContent = res.data.count || 0;
            
            if (res.data.userLiked) {
                btn.classList.add('liked');
                likedPosts.add(postId);
            }
        }
    } catch (err) {
        console.error('Error loading like status:', err);
    }
}

async function loadComments(postId) {
    try {
        const token = localStorage.getItem('token');
        const res = await fetch(`/api/media/${postId}/comments`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const data = await res.json();
        
        if (data.data && Array.isArray(data.data) && data.data.length > 0) {
            commentData[postId] = data.data;
            
            // Show first 2 comments
            const previewContainer = document.getElementById(`commentsPreview-${postId}`);
            if (previewContainer) {
                const commentsToShow = data.data.slice(0, 2);
                
                previewContainer.innerHTML = commentsToShow.map(comment => `
                    <div class="comment-item">
                        <span class="comment-author" onclick="goToProfile(${comment.user_id})">${escapeHtml(comment.user_name || comment.user_email || 'User')}</span>
                        <span class="comment-text">${escapeHtml(comment.text)}</span>
                    </div>
                `).join('');
                
                const countSpan = document.getElementById(`commentCount-${postId}`);
                if (countSpan) {
                    countSpan.textContent = data.pagination?.total || data.data.length;
                }
            }
        }
    } catch (err) {
        console.log('Note: Comments not available for post', postId);
        // Don't fail the feed if comments can't be loaded
    }
}

async function toggleLikePost(mediaId, doubleClickElement = null) {
    try {
        // Double-click animation
        if (doubleClickElement) {
            const heart = document.createElement('div');
            heart.className = 'heart-float';
            heart.innerHTML = '❤';
            const rect = doubleClickElement.getBoundingClientRect();
            heart.style.left = (rect.width / 2 - 24) + 'px';
            heart.style.top = (rect.height / 2 - 24) + 'px';
            doubleClickElement.appendChild(heart);
            setTimeout(() => heart.remove(), 600);
        }
        
        const res = await toggleLike(mediaId);
        const btn = document.getElementById(`likeBtn-${mediaId}`);
        const countSpan = document.getElementById(`likeCount-${mediaId}`);
        
        countSpan.textContent = res.data.count;
        
        // Add animation
        btn.classList.add('liked-animation');
        setTimeout(() => btn.classList.remove('liked-animation'), 300);
        
        if (res.data.liked) {
            btn.classList.add('liked');
            likedPosts.add(mediaId);
        } else {
            btn.classList.remove('liked');
            likedPosts.delete(mediaId);
        }
    } catch (err) {
        console.error('Error toggling like:', err);
        showShareToast(err.message || 'Unable to like post', true);
    }
}

async function submitComment(postId) {
    try {
        const input = document.getElementById(`commentInput-${postId}`);
        const text = input.value.trim();
        
        if (!text) return;
        
        const res = await addComment(postId, text);
        
        if (res.success || res.data) {
            input.value = '';
            await loadComments(postId);
        }
    } catch (err) {
        console.error('Error adding comment:', err);
        showShareToast(err.message || 'Unable to post comment', true);
    }
}

function handleCommentKeypress(event, postId) {
    if (event.key === 'Enter') {
        submitComment(postId);
    }
}

function toggleSavePost(postId) {
    const btn = document.getElementById(`saveBtn-${postId}`);
    btn.classList.toggle('liked');
    // TODO: Implement save functionality
}

function openCommentModal(postId) {
    // Navigate to detail page
    window.location.href = `/media-detail.html?id=${postId}`;
}

function openRatingModal(postId) {
    // Navigate to detail page
    window.location.href = `/media-detail.html?id=${postId}`;
}

function goToProfile(userId) {
    if (!userId && currentUser?.id) {
        userId = currentUser.id;
    }
    window.location.href = `/user-profile.html?id=${userId}`;
}

function handleMediaClick(postId, event) {
    if (event?.target?.closest('video')) {
        return;
    }

    const existingTimer = clickTimers.get(postId);
    if (existingTimer) {
        clearTimeout(existingTimer);
    }

    const timer = window.setTimeout(() => {
        window.location.href = `/media-detail.html?id=${postId}`;
        clickTimers.delete(postId);
    }, 220);

    clickTimers.set(postId, timer);
}

function handleMediaDoubleClick(postId, event, element) {
    event.preventDefault();
    event.stopPropagation();

    const existingTimer = clickTimers.get(postId);
    if (existingTimer) {
        clearTimeout(existingTimer);
        clickTimers.delete(postId);
    }

    toggleLikePost(postId, element);
}

function renderPagination(paginationContainer, currentPage, totalPages) {
    paginationContainer.innerHTML = '';
    
    const createPageLink = (pageNum) => {
        const li = document.createElement('li');
        li.className = 'page-item' + (pageNum === currentPage ? ' active' : '');
        const link = document.createElement('a');
        link.className = 'page-link';
        link.href = '#';
        link.textContent = pageNum;
        link.addEventListener('click', (e) => {
            e.preventDefault();
            renderFeed(pageNum);
            window.scrollTo(0, 0);
        });
        li.appendChild(link);
        return li;
    };
    
    // Previous button
    if (currentPage > 1) {
        const li = document.createElement('li');
        li.className = 'page-item';
        const link = document.createElement('a');
        link.className = 'page-link';
        link.href = '#';
        link.textContent = '← Previous';
        link.addEventListener('click', (e) => {
            e.preventDefault();
            renderFeed(currentPage - 1);
            window.scrollTo(0, 0);
        });
        li.appendChild(link);
        paginationContainer.appendChild(li);
    }
    
    // Page numbers
    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    
    if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        paginationContainer.appendChild(createPageLink(i));
    }
    
    // Next button
    if (currentPage < totalPages) {
        const li = document.createElement('li');
        li.className = 'page-item';
        const link = document.createElement('a');
        link.className = 'page-link';
        link.href = '#';
        link.textContent = 'Next →';
        link.addEventListener('click', (e) => {
            e.preventDefault();
            renderFeed(currentPage + 1);
            window.scrollTo(0, 0);
        });
        li.appendChild(link);
        paginationContainer.appendChild(li);
    }
}

// Make functions globally available
window.toggleLikePost = toggleLikePost;
window.openCommentModal = openCommentModal;
window.openRatingModal = openRatingModal;
window.goToProfile = goToProfile;
window.submitComment = submitComment;
window.handleCommentKeypress = handleCommentKeypress;
window.toggleSavePost = toggleSavePost;
window.handleMediaClick = handleMediaClick;
window.handleMediaDoubleClick = handleMediaDoubleClick;
window.setPostRating = setPostRating;
window.sharePost = sharePost;

// Initialize on page load
document.addEventListener('DOMContentLoaded', async () => {
    currentUser = await requireAuth();
    if (!currentUser) {
        return;
    }

    hydrateNavbar(currentUser);
    setupGlobalSearch();
    if (canViewPeoplePresent(currentUser)) {
        loadPeoplePresent();
    }
    renderFeed(1);
});

// Auto-refresh feed every 30 seconds
setInterval(() => {
    const currentPage = document.querySelector('.pagination .page-item.active a')?.textContent || 1;
    renderFeed(parseInt(currentPage));
    if (canViewPeoplePresent(currentUser)) {
        loadPeoplePresent();
    }
}, 30000);
