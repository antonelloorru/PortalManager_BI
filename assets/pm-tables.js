/**
 * pm-tables.js — avvolge le tabelle in un contenitore scorrevole (v1.9.3)
 *
 * Agisce sul DOM invece di richiedere una modifica in ogni pagina: le viste sono
 * una quindicina, e toccarle una per una avrebbe significato quindici occasioni
 * di dimenticarne una.
 *
 * Non tocca le tabelle gia' avvolte, quelle dentro un report di stampa, e quelle
 * con poche righe — su cinque righe l'intestazione fissa non serve e il bordo
 * aggiunto e' solo rumore.
 */
(function () {
    'use strict';

    var MIN_RIGHE = 8;

    function avvolgi(tab) {
        if (!tab || tab.closest('.pm-scroll')) return;          // gia' fatto
        if (tab.closest('.nostampa, .pm-print')) return;         // report di stampa
        if (!tab.tHead || !tab.tHead.rows.length) return;        // senza intestazione
        var corpo = tab.tBodies[0];
        if (!corpo || corpo.rows.length < MIN_RIGHE) return;

        var box = document.createElement('div');
        box.className = 'pm-scroll';
        tab.parentNode.insertBefore(box, tab);
        box.appendChild(tab);

        // altezza reale della prima riga di intestazione, per posizionare la
        // seconda: un valore fisso si scollerebbe al primo cambio di corpo
        var h = tab.tHead.rows[0].offsetHeight;
        if (h) box.style.setProperty('--pm-th-h', h + 'px');

        // la prima colonna resta ferma solo se la tabella e' piu' larga del
        // contenitore: su una tabella stretta toglierebbe spazio senza dare nulla
        if (tab.scrollWidth > box.clientWidth + 4) tab.classList.add('pm-fix1');

        segnala(box);
        box.addEventListener('scroll', function () { segnala(box); }, { passive: true });
    }

    function segnala(box) {
        var altro = box.scrollHeight - box.clientHeight - box.scrollTop > 8;
        box.classList.toggle('has-more', altro);
    }

    function applica() {
        var tabs = document.querySelectorAll('table.data-table, table.pm-table');
        Array.prototype.forEach.call(tabs, avvolgi);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applica);
    } else {
        applica();
    }

    // le larghezze cambiano con la finestra: la colonna fissa va rivalutata
    var t = null;
    window.addEventListener('resize', function () {
        clearTimeout(t);
        t = setTimeout(function () {
            document.querySelectorAll('.pm-scroll').forEach(function (box) {
                var tab = box.querySelector('table');
                if (!tab) return;
                if (tab.scrollWidth > box.clientWidth + 4) tab.classList.add('pm-fix1');
                else tab.classList.remove('pm-fix1');
                segnala(box);
            });
        }, 150);
    }, { passive: true });
})();
