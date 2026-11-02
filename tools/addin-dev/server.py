#!/usr/bin/env python3
"""Local HTTPS server for testing Outlook add-in changes on your own machine.

Serves public/addin-preview/ straight from disk, so a saved file is live on the
next compose, and forwards /api/* to a portal of your choice. That way you test
your add-in changes against real rules and templates without any local database,
and nothing changes for anyone else.

    python3 tools/addin-dev/server.py --upstream https://portal.example.com

Then either open the printed harness URL (no Outlook needed) or sideload the
"Local dev manifest" from the portal's tenant page. See SETUP.md.

Development only: listens on localhost, standard library only, not deployed.
"""
import argparse
import http.server
import pathlib
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
HERE = pathlib.Path(__file__).resolve().parent
CERT_DIR = pathlib.Path.home() / ".signatureportal-addin-dev"

# Only what the add-in loads. Everything else in public/ stays out of reach.
STATIC_PREFIXES = ("/addin/", "/addin-preview/", "/assets/css/")
FORWARDED_HEADERS = ("Accept", "X-Sig-Key", "X-Sig-Email", "X-Sig-Primary", "X-Sig-Recipients")
RETURNED_HEADERS = ("Content-Type", "Cache-Control", "Retry-After",
                    "X-Sig-Shared", "X-Sig-Profile-Source")


class Handler(http.server.SimpleHTTPRequestHandler):
    upstream = ""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT / "public"), **kwargs)

    def do_GET(self):
        path = urllib.parse.urlsplit(self.path).path
        if path.startswith("/api/"):
            return self.forward()
        if path in ("/__harness", "/__harness/"):
            return self.send_file(HERE / "harness.html")
        if path.startswith(STATIC_PREFIXES) and "/." not in path:
            return super().do_GET()
        self.send_error(404)

    def do_HEAD(self):
        self.send_error(405)

    def forward(self):
        request = urllib.request.Request(self.upstream + self.path)
        for name in FORWARDED_HEADERS:
            if self.headers.get(name):
                request.add_header(name, self.headers[name])
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                self.relay(response.status, response.headers, response.read())
        except urllib.error.HTTPError as error:
            self.relay(error.code, error.headers, error.read())
        except OSError as error:
            sys.stderr.write(f"upstream unreachable: {error}\n")
            self.relay(502, {"Content-Type": "text/plain"}, b"upstream unreachable")

    def relay(self, status, headers, body):
        self.send_response(status)
        for name in RETURNED_HEADERS:
            if headers.get(name):
                self.send_header(name, headers[name])
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_file(self, path):
        body = path.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def end_headers(self):
        # Outlook caches add-in files hard. While developing, never let it.
        if not self.path.startswith("/api/"):
            self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, format, *args):
        # The path only: query strings can carry addresses from older add-ins.
        first = str(args[0]).split(" ")
        path = urllib.parse.urlsplit(first[1]).path if len(first) > 1 else ""
        sys.stderr.write(f"{first[0]} {path} {args[1] if len(args) > 1 else ''}\n")


def main():
    parser = argparse.ArgumentParser(description="Local HTTPS server for the Outlook add-in.")
    parser.add_argument("--upstream", required=True,
                        help="portal that answers /api/*, e.g. https://portal.example.com")
    parser.add_argument("--port", type=int, default=8443,
                        help="must match the Local dev manifest (default: 8443)")
    parser.add_argument("--cert", default=str(CERT_DIR / "cert.pem"))
    parser.add_argument("--key", default=str(CERT_DIR / "key.pem"))
    args = parser.parse_args()

    if not pathlib.Path(args.cert).is_file():
        sys.exit("No certificate found. Run tools/addin-dev/setup-cert.sh once first.")

    Handler.upstream = args.upstream.rstrip("/")
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(args.cert, args.key)

    server = http.server.ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    server.socket = context.wrap_socket(server.socket, server_side=True)

    print(f"Serving public/addin-preview/ on https://localhost:{args.port}")
    print(f"Forwarding /api/* to {Handler.upstream}")
    print(f"Harness (no Outlook needed): https://localhost:{args.port}/__harness")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
