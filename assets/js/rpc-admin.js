/**
 * Rotar Privacidad & Cookies Chile (Ley N° 21.719) — Admin Interactivity
 */
(function () {
    'use strict';

    if (typeof window.rpcAdmin === 'undefined') return;

    function postAjax(action, formData) {
        formData.append('action', action);
        formData.append('nonce', window.rpcAdmin.nonce);
        return fetch(window.rpcAdmin.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (r) { return r.json(); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // 1. Escáner automático en 1 clic
        var btnScan = document.getElementById('rpc-btn-run-scan');
        var scanProgress = document.getElementById('rpc-scan-progress');

        if (btnScan) {
            btnScan.addEventListener('click', function () {
                btnScan.disabled = true;
                if (scanProgress) scanProgress.hidden = false;

                postAjax('rpc_run_scan', new FormData())
                    .then(function (res) {
                        if (res && res.success) {
                            window.location.reload();
                        } else {
                            alert('No se pudo completar el escaneo.');
                            btnScan.disabled = false;
                            if (scanProgress) scanProgress.hidden = true;
                        }
                    })
                    .catch(function () {
                        btnScan.disabled = false;
                        if (scanProgress) scanProgress.hidden = true;
                    });
            });
        }

        // 2. Agregar cookie manual
        var addCookieForm = document.getElementById('rpc-add-cookie-form');
        if (addCookieForm) {
            addCookieForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var fd = new FormData(addCookieForm);
                postAjax('rpc_save_cookie_item', fd).then(function (res) {
                    if (res && res.success) {
                        window.location.reload();
                    } else {
                        alert((res && res.data && res.data.message) || 'Error al guardar.');
                    }
                });
            });
        }

        // 3. Eliminar cookie del inventario
        document.querySelectorAll('.rpc-delete-cookie').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('¿Eliminar esta entrada del inventario de cookies?')) return;
                var fd = new FormData();
                fd.append('id', btn.getAttribute('data-id'));
                postAjax('rpc_delete_cookie_item', fd).then(function (res) {
                    if (res && res.success) window.location.reload();
                });
            });
        });

        // 4. Navegación por pasos del Asistente Legal
        function showStep(stepNum) {
            document.querySelectorAll('.rpc-wizard-step').forEach(function (el) {
                el.hidden = el.getAttribute('data-step') !== String(stepNum);
            });
            document.querySelectorAll('.rpc-wstep').forEach(function (ind) {
                if (ind.getAttribute('data-step-ind') === String(stepNum)) {
                    ind.classList.add('is-current');
                } else {
                    ind.classList.remove('is-current');
                }
            });
        }

        document.querySelectorAll('.rpc-next-step').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showStep(btn.getAttribute('data-next'));
            });
        });

        document.querySelectorAll('.rpc-prev-step').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showStep(btn.getAttribute('data-prev'));
            });
        });

        var wizardForm = document.getElementById('rpc-legal-wizard-form');
        var wizardResult = document.getElementById('rpc-wizard-result');
        var btnGenLegal = document.getElementById('rpc-btn-generate-legal');

        if (wizardForm) {
            wizardForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (btnGenLegal) {
                    btnGenLegal.disabled = true;
                    btnGenLegal.textContent = 'Generando páginas legales...';
                }

                var fd = new FormData(wizardForm);
                postAjax('rpc_generate_legal_pages', fd)
                    .then(function (res) {
                        if (btnGenLegal) {
                            btnGenLegal.disabled = false;
                            btnGenLegal.textContent = '⚖️ Redactar y Publicar Páginas Legales Ahora';
                        }
                        if (res && res.success && wizardResult) {
                            var html = '<strong>✅ ' + res.data.message + '</strong><ul style="margin-top:10px;">';
                            (res.data.pages || []).forEach(function (p) {
                                html += '<li><strong>' + p.title + ':</strong> <a href="' + p.url + '" target="_blank">Ver página publicada</a> · <a href="' + p.edit + '">Editar en WordPress</a></li>';
                            });
                            html += '</ul>';
                            wizardResult.innerHTML = html;
                            wizardResult.hidden = false;
                        } else {
                            alert('Error al generar las páginas legales.');
                        }
                    })
                    .catch(function () {
                        if (btnGenLegal) btnGenLegal.disabled = false;
                    });
            });
        }

        // 5. Actualizar estado de solicitudes ARSOP+
        document.querySelectorAll('.rpc-req-status-select').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var fd = new FormData();
                fd.append('id', sel.getAttribute('data-id'));
                fd.append('status', sel.value);
                postAjax('rpc_update_request_status', fd);
            });
        });
    });
})();

