// search.js

import { searchMedia, addComment, rateMedia, searchUsers } from './api.js';
import { logout, requireAuth } from './auth.js';

let selectedRating = 0;
let searchDebounce = null;
let suggestionDebounce = null;

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
        query: document.getElementById('searchQuery').value.trim()
    };
}

function parseUnifiedSearchInput(input) {
    const raw = String(input || '').trim();
    const lower = raw.toLowerCase();

    if (!raw) {
        return { query: '', id: '', name: '', location: '', person: '', broadTerm: '' };
    }

    if (raw.startsWith('#')) {
        return { query: raw, id: '', name: '', location: '', person: '', broadTerm: '' };
    }

    if (raw.startsWith('@')) {
        return { query: '', id: '', name: raw.slice(1).trim(), location: '', person: '', broadTerm: '' };
    }

    if (lower.startsWith('id:')) {
        return { query: '', id: raw.slice(3).trim(), name: '', location: '', person: '', broadTerm: '' };
    }

    if (lower.startsWith('name:') || lower.startsWith('user:')) {
        const value = raw.includes(':') ? raw.slice(raw.indexOf(':') + 1).trim() : '';
        return { query: '', id: '', name: value, location: '', person: '', broadTerm: '' };
    }

    if (lower.startsWith('loc:') || lower.startsWith('location:')) {
        const value = raw.includes(':') ? raw.slice(raw.indexOf(':') + 1).trim() : '';
        return { query: '', id: '', name: '', location: value, person: '', broadTerm: '' };
    }

    if (lower.startsWith('tag:') || lower.startsWith('person:')) {
        const value = raw.includes(':') ? raw.slice(raw.indexOf(':') + 1).trim() : '';
        return { query: '', id: '', name: '', location: '', person: value, broadTerm: '' };
    }

    if (/^\d+$/.test(raw)) {
        return { query: '', id: raw, name: '', location: '', person: '', broadTerm: '' };
    }

    // Plain text fallback: try across fields in sequence.
    return { query: '', id: '', name: '', location: '', person: '', broadTerm: raw };
}

function hasSpecificCriteria(criteria) {
    return Boolean(criteria.query || criteria.id || criteria.name || criteria.location || criteria.person);
}

function isEmptySearchResult(result) {
    return !Array.isArray(result?.data) || result.data.length === 0;
}

async function runSearchAttempts(attempts, page) {
    for (const attempt of attempts) {
        const candidate = await searchMedia(attempt.query, attempt.id, attempt.name, attempt.location, attempt.person, page);
        if (!isEmptySearchResult(candidate)) {
            return candidate;
        }
    }

    if (attempts.length === 0) {
        return { data: [], pagination: { totalPages: 0 } };
    }

    return searchMedia(attempts[0].query, attempts[0].id, attempts[0].name, attempts[0].location, attempts[0].person, page);
}

function extractMatchingHashtags(items, term) {
    const lookup = new Set();
    const normalizedTerm = String(term || '').replace(/^#/, '').toLowerCase();

    (items || []).forEach((item) => {
        const source = `${item.title || ''} ${item.caption || ''}`;
        const matches = source.match(/#[\w-]+/g) || [];
        matches.forEach((match) => {
            if (!normalizedTerm || match.toLowerCase().includes(`#${normalizedTerm}`)) {
                lookup.add(match);
            }
        });
    });

    return Array.from(lookup).slice(0, 5);
}

function buildSuggestionItem(label, meta, value) {
    return { label, meta, value };
}

function renderSuggestions(items) {
    const box = document.getElementById('searchSuggestions');
    if (!box) {
        return;
    }

    if (!items || items.length === 0) {
        box.classList.remove('show');
        box.innerHTML = '';
        return;
    }

    box.innerHTML = items.map((item) => `
        <button type="button" class="search-suggestion-item" data-value="${escapeHtml(item.value)}" style="width:100%;border:none;background:transparent;text-align:left;">
            <div class="search-suggestion-avatar-placeholder">${escapeHtml((item.label.charAt(0) || '?').toUpperCase())}</div>
            <div>
                <div class="search-suggestion-name">${escapeHtml(item.label)}</div>
                <div class="search-suggestion-role">${escapeHtml(item.meta || '')}</div>
            </div>
        </button>
    `).join('');

    box.classList.add('show');

    box.querySelectorAll('[data-value]').forEach((element) => {
        element.addEventListener('mousedown', (event) => event.preventDefault());
        element.addEventListener('click', () => {
            const input = document.getElementById('searchQuery');
            if (!input) {
                return;
            }

            input.value = element.dataset.value || '';
            box.classList.remove('show');
            box.innerHTML = '';
            performSearch(1);
        });
    });
}

async function updateSuggestions() {
    const input = document.getElementById('searchQuery');
    const raw = String(input?.value || '').trim();

    if (!raw) {
        renderSuggestions([]);
        return;
    }

    const suggestions = [];
    const parsed = parseUnifiedSearchInput(raw);
    const broadTerm = parsed.broadTerm || raw.replace(/^[@#]/, '').trim();
    const normalizedTerm = broadTerm.toLowerCase();

    if (raw.startsWith('#')) {
        suggestions.push(buildSuggestionItem(raw, 'Hashtag search', raw));
    } else if (raw.startsWith('@')) {
        suggestions.push(buildSuggestionItem(raw, 'User search', raw));
    } else if (raw.toLowerCase().startsWith('id:') || /^\d+$/.test(raw)) {
        const idValue = raw.toLowerCase().startsWith('id:') ? raw : `id:${raw}`;
        suggestions.push(buildSuggestionItem(idValue, 'Post ID search', idValue));
    } else if (raw.toLowerCase().startsWith('loc:') || raw.toLowerCase().startsWith('location:')) {
        suggestions.push(buildSuggestionItem(raw, 'Location search', raw));
    } else if (raw.toLowerCase().startsWith('tag:') || raw.toLowerCase().startsWith('person:')) {
        suggestions.push(buildSuggestionItem(raw, 'Person tag search', raw));
    } else {
        suggestions.push(buildSuggestionItem(`#${raw}`, 'Search as hashtag', `#${raw}`));
        suggestions.push(buildSuggestionItem(`@${raw}`, 'Search as user', `@${raw}`));
        suggestions.push(buildSuggestionItem(`loc:${raw}`, 'Search as location', `loc:${raw}`));
        suggestions.push(buildSuggestionItem(`tag:${raw}`, 'Search as person tag', `tag:${raw}`));
        if (/^\d+$/.test(raw)) {
            suggestions.push(buildSuggestionItem(`id:${raw}`, 'Search as post ID', `id:${raw}`));
        }
    }

    try {
        const tasks = [];

        if (normalizedTerm) {
            tasks.push(searchUsers(normalizedTerm).catch(() => ({ data: [] })));
            tasks.push(searchMedia(`#${normalizedTerm}`, '', '', '', '', 1).catch(() => ({ data: [] })));
            tasks.push(searchMedia('', '', '', normalizedTerm, '', 1).catch(() => ({ data: [] })));
            tasks.push(searchMedia('', '', '', '', normalizedTerm, 1).catch(() => ({ data: [] })));
        }

        const [userRes, hashtagRes, locationRes, personRes] = await Promise.all(tasks);

        (Array.isArray(userRes?.data) ? userRes.data : []).slice(0, 4).forEach((user) => {
            const name = user.displayName || user.email?.split('@')[0] || 'User';
            suggestions.push(buildSuggestionItem(`@${name}`, 'Matching user', `@${name}`));
        });

        extractMatchingHashtags(hashtagRes?.data || [], normalizedTerm).forEach((tag) => {
            suggestions.push(buildSuggestionItem(tag, 'Matching hashtag', tag));
        });

        const seenLocations = new Set();
        (Array.isArray(locationRes?.data) ? locationRes.data : []).forEach((item) => {
            const location = String(item.location || '').trim();
            if (location && !seenLocations.has(location.toLowerCase())) {
                seenLocations.add(location.toLowerCase());
                suggestions.push(buildSuggestionItem(`loc:${location}`, 'Matching location', `loc:${location}`));
            }
        });

        const seenTags = new Set();
        (Array.isArray(personRes?.data) ? personRes.data : []).forEach((item) => {
            (Array.isArray(item.tags) ? item.tags : []).forEach((tag) => {
                const name = String(tag.name || '').trim();
                if (name && name.toLowerCase().includes(normalizedTerm) && !seenTags.has(name.toLowerCase())) {
                    seenTags.add(name.toLowerCase());
                    suggestions.push(buildSuggestionItem(`tag:${name}`, 'Matching person tag', `tag:${name}`));
                }
            });
        });
    } catch (_) {
        // Ignore suggestion lookup failures.
    }

    const deduped = [];
    const seen = new Set();
    suggestions.forEach((item) => {
        const key = item.value.toLowerCase();
        if (!seen.has(key)) {
            seen.add(key);
            deduped.push(item);
        }
    });

    renderSuggestions(deduped.slice(0, 8));
}

function updateSearchUrl(page = 1) {
    const params = new URLSearchParams();
    const criteria = readSearchCriteria();

    if (criteria.query) params.set('q', criteria.query);
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
    const { query } = readSearchCriteria();
    const container = document.getElementById('searchResults');
    const pagination = document.getElementById('pagination');

    container.innerHTML = '';
    pagination.innerHTML = '';

    if (!query) {
        container.innerHTML = '<p class="text-center text-muted">Enter a hashtag like #travel to search.</p>';
        updateSearchUrl();
        return;
    }

    updateSearchUrl(page);
    container.innerHTML = '<p class="text-center text-muted">Searching...</p>';

    try {
        const criteria = parseUnifiedSearchInput(query);
        let res = null;

        if (hasSpecificCriteria(criteria)) {
            const attempts = [
                { query: criteria.query, id: criteria.id, name: criteria.name, location: criteria.location, person: criteria.person }
            ];

            const normalized = String(query || '').trim();
            const stripped = normalized
                .replace(/^[@#]/, '')
                .replace(/^(id|name|user|loc|location|tag|person):/i, '')
                .trim();

            if (normalized.startsWith('#') && stripped) {
                attempts.push({ query: '', id: '', name: stripped, location: '', person: '' });
                attempts.push({ query: '', id: '', name: '', location: '', person: stripped });
                attempts.push({ query: '', id: '', name: '', location: stripped, person: '' });
            }

            if (normalized.startsWith('@') && stripped) {
                attempts.push({ query: '', id: '', name: '', location: '', person: stripped });
                attempts.push({ query: `#${stripped}`, id: '', name: '', location: '', person: '' });
            }

            if (/^(tag|person):/i.test(normalized) && stripped) {
                attempts.push({ query: '', id: '', name: stripped, location: '', person: '' });
            }

            if (/^(loc|location):/i.test(normalized) && stripped) {
                attempts.push({ query: '', id: '', name: stripped, location: '', person: '' });
            }

            res = await runSearchAttempts(attempts, page);
        } else {
            // For plain terms, try each dimension until we get matches.
            const attempts = [
                { query: `#${criteria.broadTerm}`, id: '', name: '', location: '', person: '' },
                { query: '', id: '', name: criteria.broadTerm, location: '', person: '' },
                { query: '', id: '', name: '', location: criteria.broadTerm, person: '' },
                { query: '', id: '', name: '', location: '', person: criteria.broadTerm }
            ];

            res = await runSearchAttempts(attempts, page);
        }
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

    if (suggestionDebounce) {
        clearTimeout(suggestionDebounce);
    }

    suggestionDebounce = window.setTimeout(() => {
        updateSuggestions();
    }, 180);
}

window.searchPage = (page) => {
    performSearch(page);
};

document.getElementById('searchForm')?.addEventListener('submit', (event) => {
    event.preventDefault();
    performSearch(1);
});

['searchQuery'].forEach((fieldId) => {
    document.getElementById(fieldId)?.addEventListener('input', scheduleLiveSearch);
    document.getElementById(fieldId)?.addEventListener('focus', updateSuggestions);
    document.getElementById(fieldId)?.addEventListener('blur', () => {
        window.setTimeout(() => renderSuggestions([]), 150);
    });
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

    if (params.get('q')) {
        performSearch(Number(params.get('page') || 1));
    }
});
