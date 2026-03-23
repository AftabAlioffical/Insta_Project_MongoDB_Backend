// search.js

import { searchMedia, addComment, rateMedia } from './api.js';
import { logout, requireAuth } from './auth.js';

let selectedRating = 0;
let searchDebounce = null;

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

function readSearchCriteria() {
    return {
        query: document.getElementById('searchQuery').value.trim(),
        id: document.getElementById('searchId').value.trim(),
        name: document.getElementById('searchName').value.trim(),
        location: document.getElementById('searchLocation').value.trim(),
        person: document.getElementById('searchPerson').value.trim()
    };
}

function updateSearchUrl(page = 1) {
    const params = new URLSearchParams();
    const criteria = readSearchCriteria();

    if (criteria.query) params.set('q', criteria.query);
    if (criteria.id) params.set('id', criteria.id);
    if (criteria.name) params.set('name', criteria.name);
    if (criteria.location) params.set('location', criteria.location);
    if (criteria.person) params.set('person', criteria.person);
    if (page > 1) params.set('page', String(page));

    const queryString = params.toString();
    const nextUrl = queryString ? `${window.location.pathname}?${queryString}` : window.location.pathname;
    window.history.replaceState({}, '', nextUrl);
}

function renderSearchMedia(item) {
    if (!item.url) {
        return '<div class="d-flex align-items-center justify-content-center text-muted" style="height: 320px; background: #f3f3f3;">Media unavailable</div>';
    }

    if (item.type === 'video') {
        return `<video src="${item.url}" class="card-img-top" style="height: 320px; object-fit: cover;" controls muted playsinline preload="metadata" data-hover-play="true"></video>`;
    }

    return `<img src="${item.url}" class="card-img-top" alt="${escapeHtml(item.title || 'Search result')}" style="height: 320px; object-fit: cover;">`;
}

function bindHoverPreviewVideos(root = document) {
    root.querySelectorAll('video[data-hover-play="true"]').forEach((video) => {
        if (video.dataset.hoverBound === '1') {
            return;
        }

        video.dataset.hoverBound = '1';

        video.addEventListener('mouseenter', () => {
            video.play().catch(() => {
                // Browser may block playback before user interaction.
            });
        });

        video.addEventListener('mouseleave', () => {
            video.pause();
            video.currentTime = 0;
        });
    });
}

async function performSearch(page = 1) {
    const { query, id, name, location, person } = readSearchCriteria();
    const container = document.getElementById('searchResults');
    const pagination = document.getElementById('pagination');

    container.innerHTML = '';
    pagination.innerHTML = '';

    if (!query && !id && !name && !location && !person) {
        container.innerHTML = '<p class="text-center text-muted">Enter search criteria (title, ID, creator name, location, or person tag).</p>';
        updateSearchUrl();
        return;
    }

    updateSearchUrl(page);
    container.innerHTML = '<p class="text-center text-muted">Searching...</p>';

    try {
        const res = await searchMedia(query, id, name, location, person, page);
        container.innerHTML = '';

        if (!Array.isArray(res.data) || res.data.length === 0) {
            container.innerHTML = '<p class="text-center text-muted">No results found.</p>';
            return;
        }

        res.data.forEach((item) => {
            const card = document.createElement('div');
            card.className = 'card';
            const mediaMarkup = renderSearchMedia(item);
            const mediaSection = item.type === 'video'
                ? mediaMarkup
                : `<a href="/media-detail.html?id=${item.id}">${mediaMarkup}</a>`;

            card.innerHTML = `
                ${mediaSection}
                <div class="card-body">
                    <h5 class="card-title"><a href="/media-detail.html?id=${item.id}" class="text-dark text-decoration-none">${escapeHtml(item.title || 'Untitled')}</a></h5>
                    <p class="card-text">${escapeHtml(item.caption || 'No caption')}</p>
                    <p class="card-text"><small class="text-muted">${escapeHtml(item.location || 'No location')}</small></p>
                    <p class="card-text">
                        <small class="text-muted">
                            ${item.commentsCount || 0} comments &middot;
                            ${item.ratings && item.ratings.average ? parseFloat(item.ratings.average).toFixed(1) : '0.0'} ★
                        </small>
                    </p>
                    <button class="btn btn-sm btn-outline-primary me-2" onclick="openCommentModal(${item.id})">Comment</button>
                    <button class="btn btn-sm btn-outline-warning" onclick="openRatingModal(${item.id})">Rate</button>
                </div>
            `;
            container.appendChild(card);
        });

        bindHoverPreviewVideos(container);

        const totalPages = res.pagination?.totalPages || 1;
        for (let i = 1; i <= totalPages; i += 1) {
            const li = document.createElement('li');
            li.className = 'page-item' + (i === page ? ' active' : '');
            li.innerHTML = `<a class="page-link" href="#" onclick="searchPage(${i})">${i}</a>`;
            pagination.appendChild(li);
        }
    } catch (err) {
        console.error(err);
        container.innerHTML = `<p class="text-center text-danger">${escapeHtml(err.message || 'Search failed')}</p>`;
    }
}

function scheduleLiveSearch() {
    if (searchDebounce) {
        clearTimeout(searchDebounce);
    }

    searchDebounce = window.setTimeout(() => {
        performSearch(1);
    }, 250);
}

window.searchPage = (page) => {
    performSearch(page);
};

document.getElementById('searchForm')?.addEventListener('submit', (event) => {
    event.preventDefault();
    performSearch(1);
});

['searchQuery', 'searchLocation', 'searchPerson'].forEach((fieldId) => {
    document.getElementById(fieldId)?.addEventListener('input', scheduleLiveSearch);
});

// comment & rating modals reused from feed
window.openCommentModal = (mediaId) => {
    const modal = new bootstrap.Modal(document.getElementById('commentModal'));
    document.getElementById('commentMediaId').value = mediaId;
    modal.show();
};

window.openRatingModal = (mediaId) => {
    const modal = new bootstrap.Modal(document.getElementById('ratingModal'));
    selectedRating = 0;
    document.getElementById('ratingMediaId').value = mediaId;
    document.getElementById('ratingStatus').innerText = 'Click to rate';
    document.querySelectorAll('#starRating .star').forEach(star => {
        star.classList.remove('text-warning');
    });
    modal.show();
};

function selectStar(value) {
    selectedRating = Number(value);
    document.querySelectorAll('#starRating .star').forEach(star => {
        star.classList.toggle('text-warning', Number(star.dataset.value) <= selectedRating);
    });
    document.getElementById('ratingStatus').innerText = `You selected ${selectedRating} star${selectedRating > 1 ? 's' : ''}`;
}

document.querySelectorAll('#starRating .star').forEach((star) => {
    star.addEventListener('click', () => selectStar(star.dataset.value));
});

document.getElementById('submitComment')?.addEventListener('click', async () => {
    const mediaId = document.getElementById('commentMediaId').value;
    const text = document.getElementById('commentText').value.trim();
    if (!text) {
        return;
    }
    try {
        await addComment(mediaId, text);
        bootstrap.Modal.getInstance(document.getElementById('commentModal')).hide();
        document.getElementById('commentText').value = '';
        performSearch();
    } catch (err) {
        console.error(err);
    }
});

document.getElementById('submitRating')?.addEventListener('click', async () => {
    const mediaId = document.getElementById('ratingMediaId').value;

    if (selectedRating < 1 || selectedRating > 5) {
        document.getElementById('ratingStatus').innerText = 'Please select a star rating first';
        return;
    }

    try {
        await rateMedia(mediaId, selectedRating);
        bootstrap.Modal.getInstance(document.getElementById('ratingModal')).hide();
        performSearch();
    } catch (err) {
        console.error(err);
    }
});

document.addEventListener('DOMContentLoaded', async () => {
    const user = await requireAuth();
    if (!user) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    document.getElementById('searchQuery').value = params.get('q') || '';
    document.getElementById('searchId').value = params.get('id') || '';
    document.getElementById('searchName').value = params.get('name') || '';
    document.getElementById('searchLocation').value = params.get('location') || '';
    document.getElementById('searchPerson').value = params.get('person') || '';

    if (params.get('q') || params.get('id') || params.get('name') || params.get('location') || params.get('person')) {
        performSearch(Number(params.get('page') || 1));
    }
});
