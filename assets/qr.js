/* assets/qr.js — offline QR code generator for the permit portal.
 * Uses the bundled assets/vendor/qrcode-generator.js (no external QR service needed).
 *
 *   <img data-qr="https://site/track.php?ref=2026-11-001" width="150" alt="QR Code">
 *     -> rendered automatically on page load
 *   PlacerQR.render(imgElement, text)   // render into an <img> later (e.g. after an AJAX call)
 *   PlacerQR.svgDataUri(text)           // returns a data: URI you can use anywhere
 */
(function () {
    'use strict';

    function svgDataUri(text, opts) {
        opts = opts || {};
        if (typeof qrcode !== 'function') throw new Error('QR generator library not loaded');
        qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];       // handle non-ASCII safely
        var qr = qrcode(0, opts.ecl || 'M');                             // type 0 = pick the smallest size that fits
        qr.addData(String(text), 'Byte');
        qr.make();
        var n = qr.getModuleCount(), margin = opts.margin == null ? 4 : opts.margin, size = n + margin * 2, d = '';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) d += 'M' + (c + margin) + ' ' + (r + margin) + 'h1v1h-1z';
            }
        }
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges">' +
                  '<rect width="100%" height="100%" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    }

    function render(img, text) {
        if (!img || !text) return false;
        try {
            img.src = svgDataUri(text);
            img.setAttribute('data-qr-rendered', '1');
            if (!img.getAttribute('alt')) img.setAttribute('alt', 'QR Code');
            return true;
        } catch (e) {
            console.error('QR render failed:', e);
            return false;
        }
    }

    function renderAll() {
        document.querySelectorAll('img[data-qr]:not([data-qr-rendered])').forEach(function (img) {
            render(img, img.getAttribute('data-qr'));
        });
    }

    window.PlacerQR = { svgDataUri: svgDataUri, render: render, renderAll: renderAll };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', renderAll);
    else renderAll();
})();
