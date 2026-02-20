(function() {
  // Session cache helpers
  var CACHE_PREFIX = 'riviantrackr_';
  var CACHE_VERSION_KEY = 'riviantrackr_version';
  var CACHE_TTL = 30 * 60 * 1000; // 30 minutes in milliseconds

  function checkCacheVersion() {
    // Invalidate browser cache if server cache version changed (e.g., model changed)
    try {
      var serverVersion = window.RivianTrackrAI && window.RivianTrackrAI.cacheVersion;
      if (!serverVersion) return;

      var storedVersion = sessionStorage.getItem(CACHE_VERSION_KEY);
      if (storedVersion && storedVersion !== String(serverVersion)) {
        // Version changed - clear all our cached data
        var keysToRemove = [];
        for (var i = 0; i < sessionStorage.length; i++) {
          var key = sessionStorage.key(i);
          if (key && key.indexOf(CACHE_PREFIX) === 0) {
            keysToRemove.push(key);
          }
        }
        keysToRemove.forEach(function(key) {
          sessionStorage.removeItem(key);
        });
      }
      sessionStorage.setItem(CACHE_VERSION_KEY, String(serverVersion));
    } catch (e) {
      // Fail silently
    }
  }

  function getCacheKey(query) {
    return CACHE_PREFIX + btoa(encodeURIComponent(query)).replace(/[^a-zA-Z0-9]/g, '');
  }

  function getFromCache(query) {
    try {
      var key = getCacheKey(query);
      var cached = sessionStorage.getItem(key);
      if (!cached) return null;

      var data = JSON.parse(cached);
      if (Date.now() > data.expires) {
        sessionStorage.removeItem(key);
        return null;
      }
      return data.response;
    } catch (e) {
      return null;
    }
  }

  function saveToCache(query, response) {
    try {
      var key = getCacheKey(query);
      var data = {
        response: response,
        expires: Date.now() + CACHE_TTL
      };
      sessionStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
      // Storage full or unavailable - fail silently
    }
  }

  function logSessionCacheHit(query, resultsCount) {
    // Fire and forget - log session cache hit to analytics
    if (!window.RivianTrackrAI || !window.RivianTrackrAI.endpoint) return;
    var logEndpoint = window.RivianTrackrAI.endpoint.replace('/summary', '/log-session-hit');
    try {
      fetch(logEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'q=' + encodeURIComponent(query) + '&results_count=' + (resultsCount || 0)
      });
    } catch (e) {
      // Fail silently - analytics logging is not critical
    }
  }

  function showSkeleton(container) {
    container.classList.add('riviantrackr-loading');
    container.innerHTML =
      '<div class="riviantrackr-skeleton" aria-hidden="true">' +
        '<div class="riviantrackr-skeleton-line riviantrackr-skeleton-line-full"></div>' +
        '<div class="riviantrackr-skeleton-line riviantrackr-skeleton-line-full"></div>' +
        '<div class="riviantrackr-skeleton-line riviantrackr-skeleton-line-medium"></div>' +
        '<div class="riviantrackr-skeleton-line riviantrackr-skeleton-line-short"></div>' +
      '</div>';
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function() {
    if (!window.RivianTrackrAI) return;

    // Check if server cache was cleared (model changed, etc.) and invalidate browser cache
    checkCacheVersion();

    var container = document.getElementById('riviantrackr-search-summary-content');
    if (!container) return;

    var q = (window.RivianTrackrAI.query || '').trim();
    if (!q) return;

    // Show skeleton loading immediately
    showSkeleton(container);

    // Check session cache first
    var cached = getFromCache(q);
    if (cached) {
      container.classList.remove('riviantrackr-loading');
      container.classList.add('riviantrackr-loaded');
      if (cached.answer_html) {
        container.innerHTML = cached.answer_html;
        showFeedback();
      } else if (cached.error) {
        var errorP = document.createElement('p');
        errorP.setAttribute('role', 'alert');
        errorP.style.cssText = 'margin:0; opacity:0.8;';
        errorP.textContent = String(cached.error);
        container.innerHTML = '';
        container.appendChild(errorP);
      }
      // Log session cache hit to analytics (fire and forget)
      logSessionCacheHit(q, cached.results_count);
      return;
    }

    var endpoint = window.RivianTrackrAI.endpoint + '?q=' + encodeURIComponent(q);

    // Append JS challenge token for bot detection hardening
    if (window.RivianTrackrAI.botToken && window.RivianTrackrAI.botTokenTs) {
      endpoint += '&bt=' + encodeURIComponent(window.RivianTrackrAI.botToken) + '&bts=' + encodeURIComponent(window.RivianTrackrAI.botTokenTs);
    }

    // Set timeout with AbortController to actually cancel the request
    var timeoutMs = (window.RivianTrackrAI.requestTimeout || 60) * 1000;
    var abortController = new AbortController();
    var timeoutId = setTimeout(function() {
      abortController.abort();
      container.classList.remove('riviantrackr-loading');
      container.classList.add('riviantrackr-loaded');
      container.innerHTML = '<p role="alert" style="margin:0; opacity:0.8;">Request timed out. Please refresh the page to try again.</p>';
    }, timeoutMs);

    // Show progressive status messages for slow responses
    var progressMessages = [
      { delay: 10000, text: 'Still working on your summary...' },
      { delay: 20000, text: 'Taking a bit longer than usual...' },
      { delay: 30000, text: 'Almost there, please wait...' }
    ];
    var progressTimers = progressMessages.map(function(msg) {
      return setTimeout(function() {
        var skeleton = container.querySelector('.riviantrackr-skeleton');
        if (!skeleton) return;
        var status = skeleton.querySelector('.riviantrackr-skeleton-status');
        if (!status) {
          status = document.createElement('p');
          status.className = 'riviantrackr-skeleton-status';
          status.style.cssText = 'margin:0.5rem 0 0; font-size:0.8rem; opacity:0.7;';
          status.setAttribute('role', 'status');
          status.setAttribute('aria-live', 'polite');
          skeleton.appendChild(status);
        }
        status.textContent = msg.text;
      }, msg.delay);
    });

    fetch(endpoint, { credentials: 'same-origin', signal: abortController.signal })
      .then(function(response) {
        progressTimers.forEach(clearTimeout);
        clearTimeout(timeoutId);

        // Handle specific HTTP error codes
        if (response.status === 429) {
          return {
            error: 'Too many requests. Please wait a moment and try again.'
          };
        }

        if (response.status === 403) {
          return {
            error: 'Access denied. AI search is not available for this request.'
          };
        }

        if (!response.ok) {
          throw new Error('Network response was not ok');
        }

        return response.json();
      })
      .then(function(data) {
        clearTimeout(timeoutId);
        container.classList.remove('riviantrackr-loading');
        container.classList.add('riviantrackr-loaded');

        if (data && data.answer_html) {
          // Cache successful responses
          saveToCache(q, data);
          container.innerHTML = data.answer_html;
          // Show feedback prompt
          showFeedback();
          return;
        }

        if (data && data.error) {
          // Cache no-results responses so we don't re-hit the server
          var noResultsCode = (window.RivianTrackrAI && window.RivianTrackrAI.errorCodes && window.RivianTrackrAI.errorCodes.noResults) || 'no_results';
          if (data.error_code === noResultsCode) {
            saveToCache(q, data);
          }
          var errorP = document.createElement('p');
          errorP.setAttribute('role', 'alert');
          errorP.style.cssText = 'margin:0; opacity:0.8;';
          errorP.textContent = String(data.error);
          container.innerHTML = '';
          container.appendChild(errorP);
          return;
        }

        container.innerHTML = '<p role="alert" style="margin:0; opacity:0.8;">AI summary is not available right now.</p>';
      })
      .catch(function(error) {
        clearTimeout(timeoutId);
        progressTimers.forEach(clearTimeout);
        // Don't show error if request was intentionally aborted (timeout already handled)
        if (error.name === 'AbortError') {
          return;
        }
        container.classList.remove('riviantrackr-loading');
        container.classList.add('riviantrackr-loaded');
        container.innerHTML = '<p role="alert" style="margin:0; opacity:0.8;">AI summary is not available right now.</p>';
      });

    // Sources toggle handler (persists expanded state in localStorage)
    var SOURCES_STATE_KEY = 'riviantrackr_sources_expanded';

    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.riviantrackr-sources-toggle');
      if (!btn) return;

      var wrapper = btn.closest('.riviantrackr-sources');
      if (!wrapper) return;

      var list = wrapper.querySelector('.riviantrackr-sources-list');
      if (!list) return;

      var isHidden = list.hasAttribute('hidden');
      var showLabel = btn.getAttribute('data-label-show') || 'Show sources';
      var hideLabel = btn.getAttribute('data-label-hide') || 'Hide sources';

      if (isHidden) {
        list.removeAttribute('hidden');
        btn.textContent = hideLabel;
        btn.setAttribute('aria-expanded', 'true');
        try { localStorage.setItem(SOURCES_STATE_KEY, '1'); } catch (e) {}
      } else {
        list.setAttribute('hidden', 'hidden');
        btn.textContent = showLabel;
        btn.setAttribute('aria-expanded', 'false');
        try { localStorage.removeItem(SOURCES_STATE_KEY); } catch (e) {}
      }
    });

    // Restore sources toggle state from previous visit
    try {
      if (localStorage.getItem(SOURCES_STATE_KEY) === '1') {
        var observer = new MutationObserver(function(mutations, obs) {
          var btn = document.querySelector('.riviantrackr-sources-toggle');
          if (btn) {
            obs.disconnect();
            var list = btn.closest('.riviantrackr-sources') && btn.closest('.riviantrackr-sources').querySelector('.riviantrackr-sources-list');
            if (list && list.hasAttribute('hidden')) {
              list.removeAttribute('hidden');
              btn.textContent = btn.getAttribute('data-label-hide') || 'Hide sources';
              btn.setAttribute('aria-expanded', 'true');
            }
          }
        });
        observer.observe(container, { childList: true, subtree: true });
      }
    } catch (e) {}

    // Feedback button handler
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('.riviantrackr-feedback-btn');
      if (!btn) return;

      var feedbackContainer = document.getElementById('riviantrackr-feedback');
      if (!feedbackContainer) return;

      var helpful = btn.getAttribute('data-helpful') === '1';
      var q = (window.RivianTrackrAI.query || '').trim();
      var feedbackEndpoint = window.RivianTrackrAI.feedbackEndpoint;

      if (!q || !feedbackEndpoint) return;

      // Disable buttons immediately
      var buttons = feedbackContainer.querySelectorAll('.riviantrackr-feedback-btn');
      buttons.forEach(function(b) { b.disabled = true; });

      fetch(feedbackEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.RivianTrackrAI.nonce || ''
        },
        body: JSON.stringify({ q: q, helpful: helpful ? 1 : 0 })
      })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        var prompt = feedbackContainer.querySelector('.riviantrackr-feedback-prompt');
        var thanks = feedbackContainer.querySelector('.riviantrackr-feedback-thanks');
        if (prompt) prompt.style.display = 'none';
        if (thanks) {
          thanks.style.display = 'block';
          if (data.message) thanks.textContent = data.message;
        }
      })
      .catch(function() {
        // Re-enable on error
        buttons.forEach(function(b) { b.disabled = false; });
      });
    });
  });

  /**
   * Show the feedback prompt after a successful summary load.
   */
  function showFeedback() {
    var feedback = document.getElementById('riviantrackr-feedback');
    if (feedback) {
      feedback.style.display = 'block';
    }
  }

  // Expose for use after fetch completes
  window.riviantrackrShowFeedback = showFeedback;
})();
