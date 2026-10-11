(function () {
    'use strict';

    var STORAGE_KEY = 'gst_cookie_consent';
    var banner = document.getElementById('cookie-consent');
    if (!banner) {
        return;
    }

    var statisticsInput = document.getElementById('cookie-statistics');
    var marketingInput = document.getElementById('cookie-marketing');
    var btnAcceptAll = document.getElementById('cookie-accept-all');
    var btnSave = document.getElementById('cookie-save');
    var btnNecessary = document.getElementById('cookie-necessary-only');
    var reopenLinks = document.querySelectorAll('[data-cookie-settings]');

    function readConsent() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return null;
            }
            var data = JSON.parse(raw);
            if (!data || typeof data !== 'object') {
                return null;
            }
            return {
                necessary: true,
                statistics: !!data.statistics,
                marketing: !!data.marketing,
                ts: data.ts || null
            };
        } catch (e) {
            return null;
        }
    }

    function writeConsent(statistics, marketing) {
        var payload = {
            necessary: true,
            statistics: !!statistics,
            marketing: !!marketing,
            ts: new Date().toISOString()
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
        applyConsent(payload);
        hideBanner();
        document.dispatchEvent(new CustomEvent('gst:cookie-consent', { detail: payload }));
    }

    function applyConsent(consent) {
        document.documentElement.dataset.cookieStatistics = consent.statistics ? '1' : '0';
        document.documentElement.dataset.cookieMarketing = consent.marketing ? '1' : '0';
        window.gstCookieConsent = consent;
    }

    function showBanner() {
        banner.hidden = false;
        banner.setAttribute('aria-hidden', 'false');
    }

    function hideBanner() {
        banner.hidden = true;
        banner.setAttribute('aria-hidden', 'true');
    }

    function syncInputs(consent) {
        if (statisticsInput) {
            statisticsInput.checked = !!(consent && consent.statistics);
        }
        if (marketingInput) {
            marketingInput.checked = !!(consent && consent.marketing);
        }
    }

    var existing = readConsent();
    if (existing) {
        applyConsent(existing);
        syncInputs(existing);
        hideBanner();
    } else {
        syncInputs({ statistics: false, marketing: false });
        showBanner();
    }

    if (btnAcceptAll) {
        btnAcceptAll.addEventListener('click', function () {
            writeConsent(true, true);
            syncInputs({ statistics: true, marketing: true });
        });
    }

    if (btnSave) {
        btnSave.addEventListener('click', function () {
            writeConsent(
                statisticsInput ? statisticsInput.checked : false,
                marketingInput ? marketingInput.checked : false
            );
        });
    }

    if (btnNecessary) {
        btnNecessary.addEventListener('click', function () {
            writeConsent(false, false);
            syncInputs({ statistics: false, marketing: false });
        });
    }

    reopenLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            syncInputs(readConsent() || { statistics: false, marketing: false });
            showBanner();
            banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
})();
