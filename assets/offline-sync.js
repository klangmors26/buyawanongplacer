/* assets/offline-sync.js
 * Server-downtime handling for the application wizard.
 *  - Forms marked data-offline="queue": if the server can't be reached on submit, the answers are
 *    stored in IndexedDB on this device, then sent automatically (in order) when the server is back.
 *  - Forms marked data-offline="block" (final submit, payment): never queued; the user is told to retry.
 *  - Document uploads can be queued with OfflineSync.queueUpload().
 * Payments and final submission are deliberately NOT queued: they must be confirmed by the server.
 */
(function () {
    if (window.OfflineSync) return;

    var USER = String(window.__OFFLINE_USER__ || '');
    var PING = 'ping.php';
    var DB_NAME = 'placer-offline', STORE = 'queue';
    var nativeSubmit = HTMLFormElement.prototype.submit;
    var syncing = false, lastKnownUp = true;

    /* ---------------- IndexedDB ---------------- */
    function db() {
        return new Promise(function (res, rej) {
            var r = indexedDB.open(DB_NAME, 1);
            r.onupgradeneeded = function () { r.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true }); };
            r.onsuccess = function () { res(r.result); };
            r.onerror = function () { rej(r.error); };
        });
    }
    function run(mode, fn) {
        return db().then(function (d) {
            return new Promise(function (res, rej) {
                var t = d.transaction(STORE, mode), req = fn(t.objectStore(STORE));
                t.oncomplete = function () { res(req ? req.result : undefined); };
                t.onerror = function () { rej(t.error); };
            });
        });
    }
    function allEntries() { return run('readonly', function (s) { return s.getAll(); }).then(function (a) { return a || []; }); }
    function addEntry(e) { return run('readwrite', function (s) { return s.add(e); }); }
    function delEntry(id) { return run('readwrite', function (s) { return s.delete(id); }); }
    function putEntry(e) { return run('readwrite', function (s) { return s.put(e); }); }
    function mine() { return allEntries().then(function (a) { return a.filter(function (x) { return x.userId === USER; }); }); }

    /* ---------------- UI ---------------- */
    var bar;
    function banner(kind, html) {
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'offline-banner';
            bar.setAttribute('role', 'status');
            bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;padding:.55rem 1rem;font:600 .82rem/1.35 Inter,system-ui,sans-serif;text-align:center;display:none;box-shadow:0 2px 8px rgba(0,0,0,.15)';
            document.body.appendChild(bar);
        }
        var colors = { down: ['#fff3cd', '#664d03'], sync: ['#cfe2ff', '#052c65'], done: ['#d1e7dd', '#0a3622'], warn: ['#f8d7da', '#58151c'] };
        var c = colors[kind] || colors.down;
        bar.style.background = c[0]; bar.style.color = c[1];
        bar.innerHTML = html;
        bar.style.display = 'block';
        if (kind === 'done') setTimeout(function () { if (bar.innerHTML === html) bar.style.display = 'none'; }, 5000);
    }
    function hideBanner() { if (bar) bar.style.display = 'none'; }
    function showDown(n) {
        banner('down', '⚠ Server unavailable. You can keep working — your answers are saved on this device and will be sent automatically when the connection returns.' + (n ? ' (' + n + ' waiting)' : ''));
    }
    function say(title, text, icon) {
        if (window.Swal) { Swal.fire({ icon: icon || 'info', title: title, text: text, confirmButtonColor: '#1e293b' }); }
        else { alert(title + '\n' + text); }
    }
    function blocked() {
        say('Server unavailable', 'This step needs a live connection to the server. Your saved answers are safe — please try again once the connection is back.', 'warning');
    }

    /* ---------------- connectivity ---------------- */
    function serverUp() {
        if (!navigator.onLine) return Promise.resolve(false);
        var c = new AbortController(), t = setTimeout(function () { c.abort(); }, 4000);
        return fetch(PING + '?t=' + Date.now(), { cache: 'no-store', credentials: 'omit', signal: c.signal })
            .then(function (r) { return r.status === 204 || r.ok; })
            .catch(function () { return false; })
            .then(function (ok) { clearTimeout(t); return ok; });
    }

    /* ---------------- queueing ---------------- */
    function serialize(fd) {
        var out = [];
        fd.forEach(function (v, k) {
            if (v instanceof File) { if (v.name === '' && v.size === 0) return; out.push([k, v]); }
            else out.push([k, String(v)]);
        });
        return out;
    }
    function pathOf(u) { try { return new URL(u, location.href).pathname; } catch (e) { return u; } }
    function fieldVal(fields, name) { for (var i = 0; i < fields.length; i++) if (fields[i][0] === name) return fields[i][1]; return null; }

    function queueForm(form) {
        var url = new URL(form.action || location.href, location.href).href;
        return mine().then(function (list) {
            return Promise.all(list.filter(function (x) { return x.kind === 'form' && x.url === url; }).map(function (x) { return delEntry(x.id); }));
        }).then(function () {
            return addEntry({ userId: USER, kind: 'form', url: url, path: location.pathname, label: document.title,
                              fields: serialize(new FormData(form)), createdAt: Date.now(), status: 'pending' });
        }).then(function () { return afterQueue(false); });
    }
    function queueUpload(url, formData, label) {
        var abs = new URL(url, location.href).href, fields = serialize(formData), docType = fieldVal(fields, 'document_type');
        return mine().then(function (list) {
            return Promise.all(list.filter(function (x) { return x.kind === 'upload' && fieldVal(x.fields, 'document_type') === docType; })
                                   .map(function (x) { return delEntry(x.id); }));
        }).then(function () {
            return addEntry({ userId: USER, kind: 'upload', url: abs, path: location.pathname, label: label || 'Document',
                              fields: fields, createdAt: Date.now(), status: 'pending' });
        }).then(function () { return afterQueue(true); });
    }
    function afterQueue(silent) {
        lastKnownUp = false;
        return mine().then(function (l) {
            var n = l.filter(function (x) { return x.status === 'pending'; }).length;
            showDown(n);
            if (silent === true) return;
            say('Saved on this device', 'The server can’t be reached right now. Your answers are stored safely and will be sent automatically when it is back.', 'success');
        });
    }

    /* ---------------- restore saved answers into a form ---------------- */
    function restore() {
        var forms = document.querySelectorAll('form[data-offline="queue"]');
        if (!forms.length) return;
        mine().then(function (list) {
            forms.forEach(function (form) {
                var url = new URL(form.action || location.href, location.href).href;
                var e = list.filter(function (x) { return x.kind === 'form' && x.url === url; }).pop();
                if (!e) return;
                var map = {};
                e.fields.forEach(function (p) { if (!(p[1] instanceof File)) (map[p[0]] = map[p[0]] || []).push(p[1]); });
                Object.keys(map).forEach(function (name) {
                    form.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]').forEach(function (el) {
                        if (el.type === 'checkbox' || el.type === 'radio') el.checked = map[name].indexOf(el.value) !== -1;
                        else if (el.type !== 'file' && el.type !== 'hidden') el.value = map[name][0];
                        else if (el.type === 'hidden') el.value = map[name][0];
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
                banner('down', 'Showing answers saved on this device (not yet sent to the server).');
            });
        });
    }

    /* ---------------- sync ---------------- */
    function isLoginPage(u) { return /\/(login|register|index|forgot_password)\.php$/.test(new URL(u).pathname) || /\/$/.test(new URL(u).pathname); }

    function syncAll() {
        if (syncing) return Promise.resolve();
        syncing = true;
        return mine().then(function (list) {
            var items = list.filter(function (x) { return x.status === 'pending'; }).sort(function (a, b) { return a.id - b.id; });
            if (!items.length) { return; }
            banner('sync', '🔄 Back online — sending your saved data…');
            var done = 0, fix = [], nextUrl = null, touchedHere = false, kinds = {};

            function step(i) {
                if (i >= items.length) return Promise.resolve('finished');
                var it = items[i], fd = new FormData();
                it.fields.forEach(function (p) { fd.append(p[0], p[1]); });
                return fetch(it.url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (res) {
                    if (res.status >= 500) return 'down';
                    if (res.redirected && isLoginPage(res.url) && !isLoginPage(it.url)) return 'login';

                    if (it.kind === 'upload') {
                        return res.json().catch(function () { return null; }).then(function (j) {
                            if (j && j.success) { done++; kinds.upload = 1; if (it.path === location.pathname) touchedHere = true; return delEntry(it.id).then(function () { return step(i + 1); }); }
                            it.status = 'needs_fix'; it.error = (j && j.error) || 'Upload was rejected';
                            fix.push(it); return putEntry(it).then(function () { return step(i + 1); });
                        });
                    }
                    if (res.redirected) {
                        done++; nextUrl = res.url; if (it.path === location.pathname) touchedHere = true;
                        return delEntry(it.id).then(function () { return step(i + 1); });
                    }
                    return res.text().then(function (t) {
                        if (t.length < 400 && t.indexOf('Connection failed') !== -1) return 'down';
                        it.status = 'needs_fix'; it.error = 'The server asked for corrections';
                        fix.push(it); return putEntry(it).then(function () { return step(i + 1); });
                    });
                }).catch(function () { return 'down'; });
            }

            return step(0).then(function (state) {
                if (state === 'down') { lastKnownUp = false; return mine().then(function (l) { showDown(l.filter(function (x) { return x.status === 'pending'; }).length); }); }
                if (state === 'login') { banner('warn', 'Your session expired. Please <a href="login.php" style="color:inherit;text-decoration:underline">log in again</a> — your saved data will be sent afterwards.'); return; }
                if (fix.length) {
                    banner('warn', 'Some saved answers need your attention: <a href="' + fix[0].path + '" style="color:inherit;text-decoration:underline">open ' + (fix[0].label || 'the form') + '</a> to review them.');
                    return;
                }
                banner('done', '✓ Back online. All your saved data was sent (' + done + ').');
                if (touchedHere) {
                    setTimeout(function () { if (nextUrl) location.href = nextUrl; else location.reload(); }, 1200);
                }
            });
        }).catch(function () {}).then(function () { syncing = false; });
    }

    function tick() {
        serverUp().then(function (up) {
            if (!up) {
                lastKnownUp = false;
                mine().then(function (l) { showDown(l.filter(function (x) { return x.status === 'pending'; }).length); });
                return;
            }
            var wasDown = !lastKnownUp; lastKnownUp = true;
            mine().then(function (l) {
                var pending = l.filter(function (x) { return x.status === 'pending'; }).length;
                if (pending) syncAll();
                else if (wasDown) hideBanner();
            });
        });
    }

    /* ---------------- form interception ---------------- */
    function handleSubmit(form) {
        var mode = form.dataset.offline;
        var btns = form.querySelectorAll('button[type="submit"],input[type="submit"]');
        btns.forEach(function (b) { b.disabled = true; });
        return serverUp().then(function (up) {
            if (up) {
                var url = new URL(form.action || location.href, location.href).href;
                return mine().then(function (l) {
                    return Promise.all(l.filter(function (x) { return x.url === url && x.kind === 'form'; }).map(function (x) { return delEntry(x.id); }));
                }).then(function () { form.dataset.offlineBypass = '1'; btns.forEach(function (b) { b.disabled = false; }); nativeSubmit.call(form); });
            }
            btns.forEach(function (b) { b.disabled = false; });
            if (mode === 'block') { blocked(); return; }
            return queueForm(form);
        });
    }
    HTMLFormElement.prototype.submit = function () {
        if (this.dataset && this.dataset.offline && !this.dataset.offlineBypass) { handleSubmit(this); return; }
        return nativeSubmit.call(this);
    };
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.dataset || !f.dataset.offline || f.dataset.offlineBypass || e.defaultPrevented) return;
        e.preventDefault();
        handleSubmit(f);
    });

    /* ---------------- start ---------------- */
    window.OfflineSync = { serverUp: serverUp, queueUpload: queueUpload, blocked: blocked, syncNow: syncAll,
                           pending: function () { return mine().then(function (l) { return l.filter(function (x) { return x.status === 'pending'; }).length; }); } };
    window.addEventListener('online', tick);
    window.addEventListener('offline', function () { mine().then(function (l) { showDown(l.length); }); });
    function init() {
        restore();
        mine().then(function (l) { if (l.length || !navigator.onLine) tick(); });
        setInterval(tick, 10000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
