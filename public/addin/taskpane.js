/**
 * SignaturePortal — "Choose signature" taskpane.
 *
 * Lists the templates an admin flagged as manually selectable
 * (GET /api/sig/templates), renders one on click with the user's real
 * profile data (GET /api/sig?...&template_id=N), and inserts the cached
 * HTML via setSignatureAsync.
 *
 * Tenant slug and API key are read from this page's query string — the
 * manifest embeds both in the Taskpane.Url resource, same as commands.html.
 * The key is sent to the API only as the X-Sig-Key header (never a query
 * param) so it stays out of Apache access logs.
 */
(function () {
    "use strict";

    var BASE_URL = location.protocol + "//" + location.host;

    var QUERY = (function () {
        var p = {};
        location.search.replace(/^\?/, "").split("&").forEach(function (pair) {
            if (!pair) return;
            var i = pair.indexOf("=");
            var k = decodeURIComponent(i === -1 ? pair : pair.slice(0, i));
            var v = i === -1 ? "" : decodeURIComponent(pair.slice(i + 1));
            p[k] = v;
        });
        return p;
    })();

    var TENANT = QUERY.tenant || "";
    var API_KEY = QUERY.key || "";

    var STRINGS = {
        en: {
            title:        "Choose signature",
            loading:      "Loading…",
            insert:       "Insert signature",
            inserted:     "Signature inserted.",
            insertFailed: "Could not insert the signature into the message.",
            empty:        "No templates have been made available for manual selection — ask your administrator.",
            outdated:     "This add-in installation is outdated. Ask your administrator to re-deploy the manifest.",
            unauthorized: "The add-in's API key is no longer valid. Ask your administrator to re-deploy the manifest.",
            gone:         "This template is no longer available — the list has been refreshed.",
            rateLimited:  "Too many requests — please try again in a minute.",
            upstream:     "Directory lookup failed. Try again shortly.",
            network:      "Signature service unreachable. Try again shortly.",
            rendering:    "Loading preview…"
        },
        de: {
            title:        "Signatur auswählen",
            loading:      "Wird geladen…",
            insert:       "Signatur einfügen",
            inserted:     "Signatur eingefügt.",
            insertFailed: "Die Signatur konnte nicht eingefügt werden.",
            empty:        "Es wurden keine Vorlagen zur manuellen Auswahl freigegeben — bitte an den Administrator wenden.",
            outdated:     "Diese Add-in-Installation ist veraltet. Bitte den Administrator das Manifest neu verteilen lassen.",
            unauthorized: "Der API-Schlüssel des Add-ins ist nicht mehr gültig. Bitte den Administrator das Manifest neu verteilen lassen.",
            gone:         "Diese Vorlage ist nicht mehr verfügbar — die Liste wurde aktualisiert.",
            rateLimited:  "Zu viele Anfragen — bitte in einer Minute erneut versuchen.",
            upstream:     "Verzeichnisabfrage fehlgeschlagen. Bitte später erneut versuchen.",
            network:      "Signaturdienst nicht erreichbar. Bitte später erneut versuchen.",
            rendering:    "Vorschau wird geladen…"
        }
    };

    var LANG = "en";

    function t(key) {
        return (STRINGS[LANG] && STRINGS[LANG][key]) || STRINGS.en[key] || key;
    }

    var el = {
        title:   document.getElementById("tp-title"),
        status:  document.getElementById("tp-status"),
        list:    document.getElementById("tp-list"),
        preview: document.getElementById("tp-preview"),
        insert:  document.getElementById("tp-insert")
    };

    // Compose context (FROM + primary), needed so the server renders the
    // chosen template with the right profile — including shared-mailbox
    // fallback. Rendered HTML is cached per template id; Insert reuses it.
    var ctx = { fromEmail: "", primary: "" };
    var htmlCache = {};
    var selectedId = null;

    function setStatus(message, isError) {
        el.status.textContent = message || "";
        el.status.className = "tp-status" + (isError ? " error" : "");
    }

    function apiErrorKey(status) {
        if (status === 401) return "unauthorized";
        if (status === 404) return "gone";
        if (status === 429) return "rateLimited";
        if (status === 502) return "upstream";
        return "network";
    }

    function apiGet(path, ok, fail) {
        var xhr = new XMLHttpRequest();
        xhr.open("GET", BASE_URL + path, true);
        xhr.setRequestHeader("X-Sig-Key", API_KEY);
        xhr.timeout = 8000;
        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) return ok(xhr);
            fail(xhr.status);
        };
        xhr.onerror = xhr.ontimeout = function () { fail(0); };
        xhr.send();
    }

    function loadTemplates() {
        setStatus(t("loading"));
        apiGet("/api/sig/templates?tenant=" + encodeURIComponent(TENANT), function (xhr) {
            var templates = [];
            try {
                templates = (JSON.parse(xhr.responseText) || {}).templates || [];
            } catch (_e) { /* treat as empty */ }
            renderList(templates);
        }, function (status) {
            setStatus(t(apiErrorKey(status)), true);
        });
    }

    function renderList(templates) {
        el.list.innerHTML = "";
        if (templates.length === 0) {
            setStatus(t("empty"));
            return;
        }
        setStatus("");
        templates.forEach(function (tpl) {
            var li = document.createElement("li");
            var btn = document.createElement("button");
            btn.type = "button";
            btn.textContent = tpl.name;
            btn.addEventListener("click", function () { selectTemplate(tpl.id, btn); });
            li.appendChild(btn);
            el.list.appendChild(li);
        });
    }

    function selectTemplate(id, btn) {
        Array.prototype.forEach.call(el.list.querySelectorAll("button"), function (b) {
            b.className = b === btn ? "selected" : "";
        });
        selectedId = id;
        el.insert.disabled = true;

        if (htmlCache[id] !== undefined) {
            return showPreview(id);
        }
        setStatus(t("rendering"));
        var path = "/api/sig"
            + "?tenant="      + encodeURIComponent(TENANT)
            + "&email="       + encodeURIComponent(ctx.fromEmail)
            + "&primary="     + encodeURIComponent(ctx.primary)
            + "&template_id=" + encodeURIComponent(id);
        apiGet(path, function (xhr) {
            htmlCache[id] = xhr.responseText;
            if (selectedId === id) showPreview(id);
        }, function (status) {
            setStatus(t(apiErrorKey(status)), true);
            if (status === 404) loadTemplates(); // stale list — refresh
        });
    }

    function showPreview(id) {
        setStatus("");
        el.preview.style.display = "block";
        el.preview.srcdoc = htmlCache[id];
        el.insert.style.display = "inline-block";
        el.insert.disabled = false;
    }

    function insertSelected() {
        if (selectedId === null || htmlCache[selectedId] === undefined) return;
        el.insert.disabled = true;
        Office.context.mailbox.item.body.setSignatureAsync(
            htmlCache[selectedId],
            { coercionType: Office.CoercionType.Html },
            function (res) {
                el.insert.disabled = false;
                if (res && res.status === Office.AsyncResultStatus.Succeeded) {
                    setStatus(t("inserted"));
                } else {
                    setStatus(t("insertFailed"), true);
                }
            }
        );
    }

    function applyLanguage() {
        el.title.textContent = t("title");
        el.insert.textContent = t("insert");
        document.documentElement.lang = LANG;
    }

    function collectComposeContext(cb) {
        var item = Office.context.mailbox.item;
        ctx.primary = (Office.context.mailbox.userProfile &&
                       Office.context.mailbox.userProfile.emailAddress) || "";
        ctx.fromEmail = ctx.primary;
        if (item && item.from && typeof item.from.getAsync === "function") {
            item.from.getAsync(function (res) {
                if (res.status === Office.AsyncResultStatus.Succeeded && res.value && res.value.emailAddress) {
                    ctx.fromEmail = res.value.emailAddress;
                }
                cb();
            });
        } else {
            cb();
        }
    }

    Office.onReady(function () {
        if (/^de/i.test((Office.context && Office.context.displayLanguage) || "")) {
            LANG = "de";
        }
        applyLanguage();

        if (!TENANT || !API_KEY) {
            // Old cached manifest without tenant/key on the taskpane URL.
            setStatus(t("outdated"), true);
            return;
        }

        el.insert.addEventListener("click", insertSelected);
        collectComposeContext(loadTemplates);
    });
})();
