/* MixCreate website analytics. Cookieless; no personal data stored. */
(function () {
    var s = document.currentScript;
    if (!s || navigator.doNotTrack === '1' || /bot|crawl|spider|headless/i.test(navigator.userAgent)) return;
    var site = s.getAttribute('data-site');
    var endpoint = s.src.replace(/\/assets\/t\.js.*$/, '/collect');
    var pid, start;
    function send(data) {
        var body = JSON.stringify(data);
        if (navigator.sendBeacon) navigator.sendBeacon(endpoint, body);
        else fetch(endpoint, { method: 'POST', body: body, keepalive: true, mode: 'no-cors' });
    }
    function view() {
        pid = Math.random().toString(36).slice(2) + Date.now().toString(36);
        start = Date.now();
        send({ e: 'view', s: site, p: pid, u: location.href, r: document.referrer, t: document.title, w: window.innerWidth });
    }
    function leave() {
        if (!pid) return;
        // Sent every time the tab is hidden; the server keeps the latest total.
        send({ e: 'leave', s: site, p: pid, d: Math.round((Date.now() - start) / 1000) });
    }
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') leave(); });
    // Single-page apps
    var push = history.pushState;
    history.pushState = function () { leave(); push.apply(this, arguments); view(); };
    window.addEventListener('popstate', function () { leave(); view(); });
    view();
})();
