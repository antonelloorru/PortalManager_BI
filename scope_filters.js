/**
 * certV 2.4 — scope_filters.js
 * Libreria riutilizzabile per Select dipendenti (Scope Filtering).
 *
 * USO DICHIARATIVO (attributi HTML):
 *   <select id="company" data-cascade="location" data-entity="locations" data-param="company_id">
 *   <select id="location">
 *
 *   Quando #company cambia, #location viene ricaricato via AJAX
 *   con api_filters.php?entity=locations&company_id=VALORE_SELEZIONATO
 *
 * USO PROGRAMMATICO:
 *   ScopeFilter.bind('company', 'location', 'locations', 'company_id');
 *   ScopeFilter.bind('brand', 'certification', 'certifications', 'brand_id');
 *
 * CATENE:
 *   ScopeFilter.chain([
 *     { source: 'company', target: 'location', entity: 'locations', param: 'company_id' },
 *     { source: 'brand', target: 'cert', entity: 'certifications', param: 'brand_id' },
 *   ]);
 *
 * REVERSE LOOKUP (auto-set un campo da un altro):
 *   ScopeFilter.reverse('cert', 'brand', 'brand_for_cert', 'certification_id');
 *   → selezionando una certificazione, auto-seleziona il brand corrispondente
 */

var ScopeFilter = (function() {
    'use strict';

    var API_URL = 'api_filters.php';
    var bindings = [];
    var cache = {};

    /**
     * Popola un <select> con i dati ricevuti.
     * Mantiene il placeholder iniziale se presente.
     */
    function populateSelect(selectEl, data, keepValue) {
        var currentVal = keepValue || selectEl.value;
        var placeholder = selectEl.querySelector('option[value=""]');
        var phText = placeholder ? placeholder.textContent : '— Seleziona —';

        selectEl.innerHTML = '';
        // Ripristina placeholder
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = phText;
        selectEl.appendChild(ph);

        (data || []).forEach(function(item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.label;
            // Copia eventuali attributi extra (brand_id, planned_date, ecc.)
            Object.keys(item).forEach(function(k) {
                if (k !== 'id' && k !== 'label') opt.dataset[k] = item[k];
            });
            selectEl.appendChild(opt);
        });

        // Ripristina valore se ancora presente
        if (currentVal) {
            var found = selectEl.querySelector('option[value="' + currentVal + '"]');
            if (found) selectEl.value = currentVal;
        }

        // Trigger change per catene successive
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Carica dati filtrati via AJAX.
     */
    function fetchFiltered(entity, params, callback) {
        var cacheKey = entity + '|' + JSON.stringify(params);
        if (cache[cacheKey]) {
            callback(cache[cacheKey]);
            return;
        }

        var url = API_URL + '?entity=' + encodeURIComponent(entity);
        Object.keys(params).forEach(function(k) {
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        });

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    cache[cacheKey] = data;
                    callback(data);
                } catch(e) {
                    console.error('[ScopeFilter] JSON parse error for ' + entity + ':', e);
                    callback([]);
                }
            } else if (xhr.status === 401 || xhr.status === 403) {
                console.error('[ScopeFilter] ' + xhr.status + ' su ' + entity +
                              ' — sessione scaduta o non autenticato. Ricaricare la pagina e rieffettuare il login.');
                callback([]);
            } else {
                console.error('[ScopeFilter] HTTP ' + xhr.status + ' su ' + entity);
                callback([]);
            }
        };
        xhr.onerror = function() {
            console.error('[ScopeFilter] Errore rete su ' + entity);
            callback([]);
        };
        xhr.onerror = function() { callback([]); };
        xhr.send();
    }

    /**
     * Collega un select sorgente a un select target.
     */
    function bind(sourceId, targetId, entity, paramName, options) {
        options = options || {};
        var sourceEl = document.getElementById(sourceId);
        var targetEl = document.getElementById(targetId);

        if (!sourceEl || !targetEl) return;

        var handler = function(e) {
            var val = sourceEl.value;
            if (!val) {
                // Sorgente vuota → mostra tutti o svuota
                if (options.showAllWhenEmpty !== false) {
                    fetchFiltered(entity, {}, function(data) {
                        populateSelect(targetEl, data);
                    });
                } else {
                    populateSelect(targetEl, []);
                }
                return;
            }

            // Mostra loading
            targetEl.disabled = true;
            var params = {};
            params[paramName] = val;

            fetchFiltered(entity, params, function(data) {
                populateSelect(targetEl, data, options.keepValue ? targetEl.value : null);
                targetEl.disabled = false;

                // Callback opzionale
                if (typeof options.onLoad === 'function') {
                    options.onLoad(data, sourceEl, targetEl);
                }
            });
        };

        sourceEl.addEventListener('change', handler);
        bindings.push({ source: sourceId, target: targetId, handler: handler });

        // Esegui subito se il sorgente ha già un valore
        if (options.triggerNow && sourceEl.value) {
            handler();
        }
    }

    /**
     * Reverse lookup: selezionando il target, auto-imposta il sorgente.
     */
    function reverse(sourceId, targetId, entity, paramName) {
        var sourceEl = document.getElementById(sourceId);
        var targetEl = document.getElementById(targetId);
        if (!sourceEl || !targetEl) return;

        sourceEl.addEventListener('change', function() {
            var val = sourceEl.value;
            if (!val) return;
            var params = {};
            params[paramName] = val;

            fetchFiltered(entity, params, function(data) {
                if (data && data.length === 1) {
                    targetEl.value = data[0].id;
                    targetEl.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    }

    /**
     * Configura una catena di filtri.
     */
    function chain(defs) {
        defs.forEach(function(d) {
            if (d.reverse) {
                reverse(d.source, d.target, d.entity, d.param);
            } else {
                bind(d.source, d.target, d.entity, d.param, d.options || {});
            }
        });
    }

    /**
     * Auto-init da attributi HTML data-cascade.
     * <select id="x" data-cascade="y" data-entity="locations" data-param="company_id">
     */
    function autoInit() {
        document.querySelectorAll('[data-cascade]').forEach(function(el) {
            var targetId = el.dataset.cascade;
            var entity = el.dataset.entity;
            var param = el.dataset.param;
            if (targetId && entity && param) {
                bind(el.id, targetId, entity, param, {
                    triggerNow: !!el.value,
                    showAllWhenEmpty: el.dataset.showAll !== 'false'
                });
            }
        });
        // Reverse lookups
        document.querySelectorAll('[data-reverse]').forEach(function(el) {
            var targetId = el.dataset.reverse;
            var entity = el.dataset.reverseEntity;
            var param = el.dataset.reverseParam;
            if (targetId && entity && param) {
                reverse(el.id, targetId, entity, param);
            }
        });
    }

    /**
     * Invalida cache (dopo CRUD).
     */
    function clearCache(entity) {
        if (entity) {
            Object.keys(cache).forEach(function(k) {
                if (k.indexOf(entity) === 0) delete cache[k];
            });
        } else {
            cache = {};
        }
    }

    // Auto-init al DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    return {
        bind: bind,
        reverse: reverse,
        chain: chain,
        fetch: fetchFiltered,
        populate: populateSelect,
        clearCache: clearCache,
        autoInit: autoInit
    };
})();
