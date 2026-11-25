/**
 * Rotar Privacidad & Cookies Chile (Ley N° 21.719) — Motor Cliente CMP
 * - Gestiona el estado del consentimiento (Opt-in / Opt-out por GeoIP)
 * - Desbloquea en caliente <script type="text/plain" data-rpc-category="..."> e iframes sin recargar página
 * - Actualiza Google Consent Mode v2 y Google Tag Manager dataLayer en tiempo real
 * - Limpia cookies analíticas/marketing al revocar o rechazar
 * - Envía registro probatorio auditable al servidor
 */
(function () {
    'use strict';

    if (typeof window.rpcConfig === 'undefined') {
        return;
    }

    var cfg = window.rpcConfig;
    var currentRegime = cfg.initialRegime || {
        country_code: 'CL',
        law_code: 'CHL_21719',
        law_label: 'Ley N° 21.719 (Chile)',
        regime: 'opt_in',
        show_banner: true
    };

    function generateUUID() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        if (!match) return null;
        try {
            return JSON.parse(decodeURIComponent(match[2]));
        } catch (e) {
            return null;
        }
    }

    function setCookie(name, valueObj, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        var encoded = encodeURIComponent(JSON.stringify(valueObj));
        document.cookie = name + '=' + encoded + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + secure;
    }

    function purgeRejectedCookies(prefs) {
        var patterns = [];
        if (!prefs.analytics) {
            patterns.push('_ga', '_gid', '_gat', '_clck', '_clsk', '_hj');
        }
        if (!prefs.marketing) {
            patterns.push('_fbp', '_fbc', '_gcl_', '_ttp', 'vk_');
        }
        if (!patterns.length) return;

        var cookies = document.cookie.split(';');
        var hostParts = window.location.hostname.split('.');
        var rootDomain = hostParts.length >= 2 ? '.' + hostParts.slice(-2).join('.') : window.location.hostname;

        for (var i = 0; i < cookies.length; i++) {
            var cookieName = cookies[i].split('=')[0].trim();
            for (var j = 0; j < patterns.length; j++) {
                if (cookieName.indexOf(patterns[j]) === 0) {
                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + window.location.hostname;
                    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + rootDomain;
                }
            }
        }
    }

    /**
     * Actualiza Google Consent Mode v2 y lanza evento para Google Tag Manager / WP Consent API
     */
    function syncIntegrations(prefs, statusLabel) {
        var analyticsGranted = prefs.analytics ? 'granted' : 'denied';
        var marketingGranted = prefs.marketing ? 'granted' : 'denied';
        var prefsGranted = prefs.preferences ? 'granted' : 'denied';

        window.dataLayer = window.dataLayer || [];
        function gtag() { window.dataLayer.push(arguments); }

        if (cfg.gcmEnabled) {
            gtag('consent', 'update', {
                'ad_storage': marketingGranted,
                'ad_user_data': marketingGranted,
                'ad_personalization': marketingGranted,
                'analytics_storage': analyticsGranted,
                'functionality_storage': prefsGranted,
                'personalization_storage': prefsGranted,
                'security_storage': 'granted'
            });
        }

        if (cfg.gtmEvents) {
            window.dataLayer.push({
                'event': 'rpc_consent_update',
                'rpc_consent_status': statusLabel,
                'rpc_consent_uuid': prefs.uuid,
                'rpc_cat_preferences': !!prefs.preferences,
                'rpc_cat_analytics': !!prefs.analytics,
                'rpc_cat_marketing': !!prefs.marketing,
                'rpc_country': currentRegime.country_code,
                'rpc_law': currentRegime.law_code
            });
        }

        // Compatibilidad con WP Consent API en JS si algún plugin tercero la escucha
        if (typeof window.wp_set_consent === 'function') {
            window.wp_set_consent('functional', 'allow');
            window.wp_set_consent('preferences', prefs.preferences ? 'allow' : 'deny');
            window.wp_set_consent('statistics', prefs.analytics ? 'allow' : 'deny');
            window.wp_set_consent('marketing', prefs.marketing ? 'allow' : 'deny');
        }

        try {
            document.dispatchEvent(new CustomEvent('rpcConsentUpdated', { detail: prefs }));
        } catch (e) { }
    }

    /**
     * Desbloquea dinámicamente scripts (<script type="text/plain" data-rpc-category="...">) e iframes retenidos
     */
    function unlockScriptsAndIframes(prefs) {
        // 1. Scripts bloqueados
        var blockedScripts = document.querySelectorAll('script[type="text/plain"][data-rpc-category]');
        blockedScripts.forEach(function (oldScript) {
            var cat = oldScript.getAttribute('data-rpc-category');
            if (!prefs[cat]) {
                return;
            }

            var newScript = document.createElement('script');
            // Copiar atributos excepto type y data-rpc-*
            for (var i = 0; i < oldScript.attributes.length; i++) {
                var attr = oldScript.attributes[i];
                if (attr.name === 'type' || attr.name === 'data-rpc-category' || attr.name === 'data-rpc-src' || attr.name === 'data-rpc-orig-type') {
                    continue;
                }
                newScript.setAttribute(attr.name, attr.value);
            }

            var origType = oldScript.getAttribute('data-rpc-orig-type');
            newScript.type = origType && origType !== 'text/plain' ? origType : 'text/javascript';

            var realSrc = oldScript.getAttribute('data-rpc-src');
            if (realSrc) {
                newScript.src = realSrc;
            } else {
                var inlineCode = oldScript.text || oldScript.textContent || '';
                // Si el documento ya terminó de cargar, los listeners a DOMContentLoaded nunca se dispararían;
                // reemplazamos de forma segura document.addEventListener('DOMContentLoaded', fn) para que corra de inmediato.
                if (document.readyState !== 'loading' && inlineCode.indexOf('DOMContentLoaded') !== -1) {
                    inlineCode = inlineCode.replace(
                        /document\.addEventListener\(\s*(['"])DOMContentLoaded\1\s*,\s*/g,
                        '(function(cb){ if(document.readyState!=="loading"){setTimeout(cb,0);}else{document.addEventListener("DOMContentLoaded",cb);} })('
                    );
                }
                newScript.text = inlineCode;
            }

            if (oldScript.parentNode) {
                oldScript.parentNode.replaceChild(newScript, oldScript);
            }
        });

        // 2. Iframes bloqueados (YouTube, Google Maps, Vimeo)
        var blockedIframes = document.querySelectorAll('iframe[data-rpc-iframe-src][data-rpc-category]');
        blockedIframes.forEach(function (iframe) {
            var cat = iframe.getAttribute('data-rpc-category');
            if (!prefs[cat]) {
                return;
            }
            var realSrc = iframe.getAttribute('data-rpc-iframe-src');
            if (realSrc && iframe.getAttribute('src') === 'about:blank') {
                iframe.setAttribute('src', realSrc);
                iframe.style.display = 'block';
                var wrapper = iframe.closest('.rpc-iframe-wrapper');
                if (wrapper) {
                    var placeholder = wrapper.querySelector('.rpc-iframe-placeholder');
                    if (placeholder) {
                        placeholder.style.display = 'none';
                    }
                }
            }
        });
    }

    /**
     * Registra en la base de datos del servidor propio la prueba auditable del consentimiento
     */
    function sendConsentAuditLog(prefs, statusLabel) {
        var fd = new FormData();
        fd.append('action', 'rpc_log_consent');
        fd.append('nonce', cfg.nonce);
        fd.append('consent_uuid', prefs.uuid);
        fd.append('status', statusLabel);
        fd.append('preferences', prefs.preferences ? '1' : '0');
        fd.append('analytics', prefs.analytics ? '1' : '0');
        fd.append('marketing', prefs.marketing ? '1' : '0');
        fd.append('country_code', currentRegime.country_code || 'CL');
        fd.append('applicable_law', currentRegime.law_code || 'CHL_21719');
        fd.append('page_url', window.location.href);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).catch(function () { });
    }

    /**
     * Aplica y persiste la decisión del usuario
     */
    function saveDecision(categories, statusLabel) {
        var existing = getCookie(cfg.cookieName);
        var uuid = (existing && existing.uuid) ? existing.uuid : generateUUID();

        var record = {
            uuid: uuid,
            version: cfg.consentVersion,
            status: statusLabel,
            necessary: true,
            preferences: !!categories.preferences,
            analytics: !!categories.analytics,
            marketing: !!categories.marketing,
            country: currentRegime.country_code || 'CL',
            law: currentRegime.law_code || 'CHL_21719',
            ts: new Date().toISOString()
        };

        setCookie(cfg.cookieName, record, cfg.expiryDays);
        purgeRejectedCookies(record);
        syncIntegrations(record, statusLabel);
        unlockScriptsAndIframes(record);
        sendConsentAuditLog(record, statusLabel);
        updateUIState(record);
    }

    function updateUIState(savedRecord) {
        var banner = document.getElementById('rpc-cookie-banner');
        var modal = document.getElementById('rpc-prefs-modal');
        var floatBtn = document.getElementById('rpc-floating-trigger');
        var receiptBox = document.getElementById('rpc-audit-receipt');
        var receiptUuid = document.getElementById('rpc-receipt-uuid');

        if (banner) banner.hidden = true;
        if (modal) modal.hidden = true;
        if (floatBtn) {
            // Si está renderizado porque el admin lo activó expresamente, mostrar solo cuando no haya aceptado todo; si aceptó, ocultar siempre
            if (savedRecord && savedRecord.status === 'accepted_all') {
                floatBtn.hidden = true;
            } else {
                floatBtn.hidden = false;
            }
        }

        if (savedRecord) {
            var toggles = document.querySelectorAll('.rpc-cat-toggle');
            toggles.forEach(function (input) {
                var cat = input.getAttribute('data-rpc-toggle');
                input.checked = !!savedRecord[cat];
            });

            if (receiptBox && receiptUuid && savedRecord.uuid) {
                receiptBox.hidden = false;
                receiptUuid.textContent = 'UUID: ' + savedRecord.uuid + ' · Ley: ' + (savedRecord.law || 'CHL_21719');
            }

            var arsopUuidInput = document.getElementById('rpc_req_uuid');
            if (arsopUuidInput && savedRecord.uuid) {
                arsopUuidInput.value = savedRecord.uuid;
            }
        }
    }

    function openModal() {
        var modal = document.getElementById('rpc-prefs-modal');
        if (!modal) return;

        var saved = getCookie(cfg.cookieName);
        var toggles = document.querySelectorAll('.rpc-cat-toggle');
        toggles.forEach(function (input) {
            var cat = input.getAttribute('data-rpc-toggle');
            // Casillas NO premarcadas si aún no hay consentimiento previo (obligatorio Ley 21.719)
            input.checked = saved ? !!saved[cat] : false;
        });

        modal.hidden = false;
    }

    function applyGeoAdaptation(regime) {
        if (!regime) return;
        currentRegime = regime;

        var lawBadge = document.getElementById('rpc-law-badge');
        if (lawBadge && regime.law_label) {
            lawBadge.textContent = regime.law_label;
        }

        var descEl = document.getElementById('rpc-banner-desc');
        if (descEl && regime.law_code === 'EU_GDPR' && cfg.messages && cfg.messages.gdpr) {
            descEl.textContent = cfg.messages.gdpr;
        }
    }

    function initFormsAndEvents() {
        var btnAccept = document.getElementById('rpc-btn-accept-all');
        var btnReject = document.getElementById('rpc-btn-reject-all');
        var btnPrefs = document.getElementById('rpc-btn-open-prefs');
        var floatBtn = document.getElementById('rpc-floating-trigger');

        var modalClose = document.getElementById('rpc-modal-close');
        var modalAccept = document.getElementById('rpc-modal-accept-all');
        var modalReject = document.getElementById('rpc-modal-reject-all');
        var modalSave = document.getElementById('rpc-modal-save-prefs');
        var modal = document.getElementById('rpc-prefs-modal');

        if (btnAccept) {
            btnAccept.addEventListener('click', function () {
                saveDecision({ preferences: true, analytics: true, marketing: true }, 'accepted_all');
            });
        }
        if (modalAccept) {
            modalAccept.addEventListener('click', function () {
                saveDecision({ preferences: true, analytics: true, marketing: true }, 'accepted_all');
            });
        }

        if (btnReject) {
            btnReject.addEventListener('click', function () {
                saveDecision({ preferences: false, analytics: false, marketing: false }, 'rejected_all');
            });
        }
        if (modalReject) {
            modalReject.addEventListener('click', function () {
                saveDecision({ preferences: false, analytics: false, marketing: false }, 'rejected_all');
            });
        }

        if (btnPrefs) {
            btnPrefs.addEventListener('click', openModal);
        }
        if (floatBtn) {
            floatBtn.addEventListener('click', openModal);
        }
        if (modalClose && modal) {
            modalClose.addEventListener('click', function () {
                modal.hidden = true;
            });
            modal.addEventListener('click', function (ev) {
                if (ev.target === modal) {
                    modal.hidden = true;
                }
            });
        }

        if (modalSave) {
            modalSave.addEventListener('click', function () {
                var selected = { preferences: false, analytics: false, marketing: false };
                document.querySelectorAll('.rpc-cat-toggle').forEach(function (input) {
                    var cat = input.getAttribute('data-rpc-toggle');
                    selected[cat] = !!input.checked;
                });
                var allTrue = selected.preferences && selected.analytics && selected.marketing;
                var allFalse = !selected.preferences && !selected.analytics && !selected.marketing;
                var label = allTrue ? 'accepted_all' : (allFalse ? 'rejected_all' : 'custom');
                saveDecision(selected, label);
            });
        }

        // Botones de desbloqueo individual en iframes (YouTube / Google Maps)
        document.addEventListener('click', function (ev) {
            var unlockBtn = ev.target.closest('.rpc-btn-unlock-iframe');
            if (!unlockBtn) return;
            var catToAccept = unlockBtn.getAttribute('data-rpc-accept-cat');
            var saved = getCookie(cfg.cookieName) || { preferences: false, analytics: false, marketing: false };
            saved[catToAccept] = true;
            saveDecision(saved, 'custom');
        });

        // Envío AJAX del formulario ARSOP+ / Opt-Out si está presente en la página
        var arsopForm = document.getElementById('rpc-arsop-form');
        if (arsopForm) {
            arsopForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var feedback = document.getElementById('rpc-arsop-feedback');
                var fd = new FormData(arsopForm);
                fd.append('action', 'rpc_submit_privacy_request');
                fd.append('nonce', cfg.nonce);

                fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!feedback) return;
                        feedback.hidden = false;
                        if (res && res.success) {
                            feedback.className = 'rpc-arsop-feedback is-ok';
                            feedback.textContent = res.data.message;
                            arsopForm.reset();
                        } else {
                            feedback.className = 'rpc-arsop-feedback is-err';
                            feedback.textContent = (res && res.data && res.data.message) ? res.data.message : 'Error al enviar la solicitud.';
                        }
                    })
                    .catch(function () {
                        if (feedback) {
                            feedback.hidden = false;
                            feedback.className = 'rpc-arsop-feedback is-err';
                            feedback.textContent = 'Error de conexión. Inténtalo nuevamente.';
                        }
                    });
            });
        }
    }

    function boot() {
        initFormsAndEvents();

        var saved = getCookie(cfg.cookieName);
        if (saved && String(saved.version) === String(cfg.consentVersion)) {
            syncIntegrations(saved, saved.status || 'custom');
            unlockScriptsAndIframes(saved);
            updateUIState(saved);
            return;
        }

        // Sin consentimiento registrado aún: verificar GeoIP dinámico si corresponde
        var banner = document.getElementById('rpc-cookie-banner');
        applyGeoAdaptation(currentRegime);

        if (cfg.geoMode !== 'worldwide_strict') {
            var fd = new FormData();
            fd.append('action', 'rpc_detect_geo');
            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success && res.data) {
                        applyGeoAdaptation(res.data);
                        if (!res.data.show_banner) {
                            // País sin requerimiento de banner en modo selectivo
                            unlockScriptsAndIframes({ preferences: true, analytics: true, marketing: true });
                            return;
                        }
                        if (res.data.regime === 'opt_out') {
                            unlockScriptsAndIframes({ preferences: true, analytics: true, marketing: true });
                        }
                        if (banner) banner.hidden = false;
                    } else if (banner) {
                        banner.hidden = false;
                    }
                })
                .catch(function () {
                    if (banner) banner.hidden = false;
                });
        } else {
            if (banner) banner.hidden = false;
        }
    }

    // Exponer API pública para botones personalizados o shortcodes
    window.RotarCMP = {
        openModal: openModal,
        acceptAll: function () { saveDecision({ preferences: true, analytics: true, marketing: true }, 'accepted_all'); },
        rejectAll: function () { saveDecision({ preferences: false, analytics: false, marketing: false }, 'rejected_all'); },
        getConsent: function () { return getCookie(cfg.cookieName); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

