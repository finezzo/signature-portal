/**
 * SignaturePortal — Outlook Add-in handler.
 *
 * Registered as functions for three LaunchEvents declared in the manifest:
 *   - OnNewMessageCompose       (Mailbox 1.10+)
 *   - OnMessageFromChange       (Mailbox 1.14+)
 *   - OnMessageRecipientsChange (Mailbox 1.13+)
 *
 * Tenant slug and API key are read from the URL query string of this
 * file (the manifest embeds both in <bt:Url id="commands.url" .../>).
 *
 * Outlook expects the handler to call event.completed() within ~30s,
 * otherwise it shows a "couldn't run the add-in" prompt to the user.
 */
(function () {
    "use strict";

    var BASE_URL = (function () {
        // The portal base URL is just our origin — same host the manifest
        // points at. Avoids hardcoding it twice.
        var loc = location;
        return loc.protocol + "//" + loc.host;
    })();

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

    Office.onReady(function () {
        if (Office.actions && Office.actions.associate) {
            Office.actions.associate("onMessageCompose",     onMessageCompose);
            Office.actions.associate("onFromChange",         onFromChange);
            Office.actions.associate("onRecipientsChange",   onRecipientsChange);
        }
    });

    function onMessageCompose(event)     { runWithSignature(event); }
    function onFromChange(event)         { runWithSignature(event); }
    function onRecipientsChange(event)   { runWithSignature(event); }

    function runWithSignature(event) {
        if (!TENANT || !API_KEY) {
            // Manifest is malformed / not regenerated after a key rotation.
            // Fail open (do nothing) rather than block the user.
            return event.completed();
        }
        try {
            collectContext(function (ctx) {
                fetchSignature(ctx, function (html, isShared) {
                    if (!html) {
                        // 204 no-match — leave whatever signature is already
                        // in the body alone. We never wipe an existing sig.
                        return event.completed();
                    }
                    Office.context.mailbox.item.body.setSignatureAsync(
                        html,
                        { coercionType: Office.CoercionType.Html },
                        function () { event.completed(); }
                    );
                }, function (_err) {
                    // Network or 5xx — fail closed silently. The user can
                    // resend after the portal recovers; we don't block.
                    event.completed();
                });
            });
        } catch (_e) {
            event.completed();
        }
    }

    function collectContext(cb) {
        var item = Office.context.mailbox.item;
        var primary = (Office.context.mailbox.userProfile &&
                       Office.context.mailbox.userProfile.emailAddress) || "";

        getFromAddress(item, function (fromEmail) {
            getRecipients(item, function (recipients) {
                cb({
                    fromEmail:  fromEmail || primary,
                    primary:    primary,
                    recipients: recipients
                });
            });
        });
    }

    function getFromAddress(item, cb) {
        // item.from is on Compose forms; getAsync is the documented path.
        if (!item.from || typeof item.from.getAsync !== "function") {
            return cb("");
        }
        item.from.getAsync(function (res) {
            if (res.status !== Office.AsyncResultStatus.Succeeded || !res.value) {
                return cb("");
            }
            cb(res.value.emailAddress || "");
        });
    }

    function getRecipients(item, cb) {
        var collected = [];
        var pending = 0;
        var done = function () {
            if (--pending === 0) cb(collected);
        };
        var add = function (recips) {
            (recips || []).forEach(function (r) {
                if (r && r.emailAddress) collected.push(r.emailAddress);
            });
        };

        ["to", "cc", "bcc"].forEach(function (field) {
            if (item[field] && typeof item[field].getAsync === "function") {
                pending++;
                item[field].getAsync(function (res) {
                    if (res.status === Office.AsyncResultStatus.Succeeded) {
                        add(res.value);
                    }
                    done();
                });
            }
        });

        if (pending === 0) cb([]);
    }

    function fetchSignature(ctx, ok, fail) {
        var url = BASE_URL + "/api/sig"
            + "?tenant="     + encodeURIComponent(TENANT)
            + "&email="      + encodeURIComponent(ctx.fromEmail)
            + "&primary="    + encodeURIComponent(ctx.primary)
            + "&recipients=" + encodeURIComponent(ctx.recipients.join(","));

        var xhr = new XMLHttpRequest();
        xhr.open("GET", url, true);
        xhr.setRequestHeader("X-Sig-Key", API_KEY);
        xhr.setRequestHeader("Accept", "text/html");
        xhr.timeout = 8000;
        xhr.onload = function () {
            if (xhr.status === 204) return ok("", false);
            if (xhr.status === 200) {
                var shared = (xhr.getResponseHeader("X-Sig-Shared") || "").toLowerCase() === "true";
                return ok(xhr.responseText, shared);
            }
            return fail(new Error("HTTP " + xhr.status));
        };
        xhr.onerror = xhr.ontimeout = function () { fail(new Error("network")); };
        xhr.send();
    }
})();
