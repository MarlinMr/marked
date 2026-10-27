"""End-to-end checks against the local Docker site, using only Python's stdlib.

Run: python3 tests/e2e.py http://localhost:8080
Temporary accounts and their data are removed on completion.
"""
import base64
import http.cookiejar
import json
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from concurrent.futures import ThreadPoolExecutor

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080").rstrip("/")
PREFIX = "test_" + uuid.uuid4().hex[:12]
PASSWORD = "A-valid-test-password-2026"


def helper(command):
    return subprocess.check_output(["docker", "compose", "exec", "-T", "web", "php", "tests/support.php", command, PREFIX], text=True)


class Browser:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, path, data=None, expected=200, files=None):
        headers = {}
        if files:
            boundary = "MarkedTestBoundary"
            parts = []
            for key, value in data.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            for key, name, mime, content in files:
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + content + b"\r\n")
            payload = b"".join(parts) + f"--{boundary}--\r\n".encode()
            headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
        else:
            payload = urllib.parse.urlencode(data).encode() if data is not None else None
        request = urllib.request.Request(BASE + path, data=payload, headers=headers)
        try:
            response = self.opener.open(request)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read().decode("utf-8", errors="replace")
        assert response.code == expected, (path, response.code, expected, body[:500])
        return body, response.geturl()

    def get(self, path, expected=200):
        return self.request(path, expected=expected)[0]

    def post(self, action, data=None, expected=200, files=None):
        csrf = re.search(r'name="csrf" value="([^"]+)"', self.get("/login")).group(1)
        return self.request("/", {"csrf": csrf, "action": action, **(data or {})}, expected, files)


def register(browser, suffix):
    email = f"{PREFIX}_{suffix}@example.test"
    browser.post("register", {"name": PREFIX + "_" + suffix, "email": email, "password": PASSWORD})
    # Read the queued delivery, then follow exactly the emailed verification link.
    mail = next(m for m in json.loads(helper("mail")) if m["recipient"] == email)
    token = re.search(r"token=([a-f0-9]{64})", mail["body"]).group(1)
    browser.post("verify", {"token": token})
    return email


def offer_ids(body):
    return [int(x) for x in re.findall(r'name="offer_id" value="(\d+)"', body)]


def run():
    helper("prepare")
    public, seller, buyer, stranger = Browser(), Browser(), Browser(), Browser()
    assert 'lang="nn"' in public.get("/")
    assert "Noko fint for deg" in public.get("/")
    seller_email = register(seller, "seller")
    buyer_email = register(buyer, "buyer")
    register(stranger, "stranger")
    seller_page = seller.get("/account")
    seller_id = int(re.search(r'href="/profile\?id=(\d+)"', seller_page).group(1))
    buyer_id = int(re.search(r'href="/profile\?id=(\d+)"', buyer.get("/account")).group(1))
    category = re.search(r'<select name="category_id"><option value="(\d+)"', seller.get("/new")).group(1)
    listing_data = {"title": PREFIX + " teststol", "description": "Ein god stol som er pent brukt.", "county": "Vestland", "municipality": "Bergen", "kind": "sell", "category_id": category, "item_condition": "Brukt", "delivery": "Henting", "price": "1000", "buyout": "1500"}
    png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGNsAAAAASUVORK5CYII=")
    _, location = seller.post("listing", listing_data, files=[("photos[]", "stol.png", "image/png", png)])
    lid = int(urllib.parse.parse_qs(urllib.parse.urlparse(location).query)["id"][0])
    path = "/listing?id=" + str(lid)
    html = public.get(path)
    assert "teststol" in html and re.search(r"/media\?id=\d+", html)
    public.request("/", {"action": "offer", "csrf": "wrong"}, expected=403)
    assert "Ingen annonsar her enno" in public.get("/?q=" + PREFIX + "&county=Oslo")
    assert "teststol" in public.get("/?q=" + PREFIX + "&county=Vestland&municipality=Bergen")
    buyer.post("favorite", {"listing_id": lid})
    assert "teststol" in buyer.get("/account")
    buyer.post("save_search", {"q": PREFIX})
    helper("digest")
    assert any(m["subject"] == "Nye treff på Marked" for m in json.loads(helper("mail")))
    seller.post("offer", {"listing_id": lid, "kind": "bid", "amount": 100}, expected=400)
    buyer.post("offer", {"listing_id": lid, "kind": "bid", "amount": 900})
    first = offer_ids(buyer.get(path))[0]
    stranger.post("respond", {"offer_id": first, "decision": "accepted"}, expected=403)
    buyer.post("respond", {"offer_id": first, "decision": "withdrawn"})
    assert "Trekt tilbake" in public.get(path)
    buyer.post("offer", {"listing_id": lid, "kind": "bid", "amount": "800.25"})
    assert "800,25 kr" in public.get(path)
    seller.post("offer", {"listing_id": lid, "kind": "counter", "buyer_id": buyer_id, "amount": 950})
    public_history = public.get(path)
    assert "950 kr" in public_history and "Motbod" in public_history
    assert PREFIX + "_buyer" not in public_history and str(buyer_id) not in re.findall(r'profile\?id=(\d+)', public_history)
    assert PREFIX + "_buyer" in seller.get(path)
    counter = offer_ids(buyer.get(path))[0]
    buyer.post("respond", {"offer_id": counter, "decision": "accepted"})
    stranger.post("offer", {"listing_id": lid, "kind": "bid", "amount": 2000}, expected=400)
    seller.post("trade", {"listing_id": lid, "decision": "reopen"})
    # Buyout requires approval and ignores any client-supplied price.
    buyer.post("offer", {"listing_id": lid, "kind": "buyout", "amount": 1})
    assert "Tilgjengeleg" in public.get(path)
    buyout = offer_ids(seller.get(path))[0]
    seller.post("respond", {"offer_id": buyout, "decision": "accepted"})
    assert "1 500 kr" in buyer.get(path)
    buyer.post("review", {"listing_id": lid, "rating": 5, "body": "God handel"}, expected=403)
    buyer.post("trade", {"listing_id": lid, "decision": "confirm"})
    assert "Reservert" in public.get(path)
    seller.post("trade", {"listing_id": lid, "decision": "confirm"})
    assert "Seld" in public.get(path)
    buyer.post("review", {"listing_id": lid, "rating": 5, "body": "Veldig god handel!"})
    buyer.post("review", {"listing_id": lid, "rating": 5, "body": "Duplikat"}, expected=400)
    assert "Veldig god handel!" in public.get("/profile?id=" + str(seller_id))
    _, location = buyer.post("conversation", {"listing_id": lid})
    cid = int(urllib.parse.parse_qs(urllib.parse.urlparse(location).query)["id"][0])
    buyer.post("message", {"conversation_id": cid, "body": "Hei! <script>alert(1)</script>"}, files=[("photo", "test.png", "image/png", png)])
    stranger.get(f"/api/messages?id={cid}", expected=404)
    messages = json.loads(seller.get(f"/api/messages?id={cid}"))["messages"]
    assert len(messages) == 1 and messages[0]["body"].startswith("Hei!")
    stranger.get(messages[0]["image"], expected=404)
    buyer.post("block", {"user_id": seller_id})
    seller.post("message", {"conversation_id": cid, "body": "Test"}, expected=400)
    buyer.post("block", {"user_id": seller_id})
    buyer.post("report", {"listing_id": lid, "reason": "Test av rapportering"})
    buyer.get("/admin", expected=403)
    # Two seller sessions accept competing offers at the same time: exactly one wins.
    _, race_location = seller.post("listing", {**listing_data, "title": PREFIX + " samtidig handel"})
    race_id = int(urllib.parse.parse_qs(urllib.parse.urlparse(race_location).query)["id"][0])
    for b in (buyer, stranger):
        b.post("offer", {"listing_id": race_id, "kind": "bid", "amount": 500})
    race_offers = list(dict.fromkeys(offer_ids(seller.get(f"/listing?id={race_id}"))))
    seller2 = Browser()
    seller2.post("login", {"email": seller_email, "password": PASSWORD})
    def accept(pair):
        browser, oid = pair
        token = re.search(r'name="csrf" value="([^"]+)"', browser.get("/login")).group(1)
        try:
            browser.opener.open(urllib.request.Request(BASE + "/", data=urllib.parse.urlencode({"action": "respond", "csrf": token, "offer_id": oid, "decision": "accepted"}).encode()))
            return 200
        except urllib.error.HTTPError as error:
            return error.code
    with ThreadPoolExecutor(max_workers=2) as pool:
        results = sorted(pool.map(accept, [(seller, race_offers[0]), (seller2, race_offers[1])]))
    assert results == [200, 400], results
    helper("admin")
    admin_page = stranger.get("/admin")
    assert "Test av rapportering" in admin_page
    assert PREFIX + "_buyer" in stranger.get(path)
    stranger.post("admin_suspend", {"id": seller_id})
    public.get(path, expected=404)
    seller.post("offer", {"listing_id": race_id, "kind": "bid", "amount": 1})  # Redirected to login when suspended.
    stranger.post("admin_suspend", {"id": seller_id})
    public.get(path)
    stranger.post("admin_remove", {"id": race_id})
    public.get(f"/listing?id={race_id}", expected=404)
    report = re.search(r'name="action" value="admin_resolve"><input type="hidden" name="id" value="(\d+)"', admin_page).group(1)
    stranger.post("admin_resolve", {"id": report})
    assert "Test av rapportering" not in stranger.get("/admin")
    stranger.post("admin_category", {"name": PREFIX + " kategori"})
    assert PREFIX + " kategori" in public.get("/")
    # Password reset uses a one-time token.
    buyer.post("logout")
    buyer.post("forgot", {"email": buyer_email})
    reset_mail = [m for m in json.loads(helper("mail")) if m["recipient"] == buyer_email and "Nytt passord" in m["subject"]][-1]
    token = re.search(r"token=([a-f0-9]{64})", reset_mail["body"]).group(1)
    buyer.post("reset", {"token": token, "password": PASSWORD + "-new"})
    buyer.post("reset", {"token": token, "password": PASSWORD}, expected=400)
    buyer.post("login", {"email": buyer_email, "password": PASSWORD + "-new"})
    subprocess.check_call(["docker", "compose", "exec", "-T", "web", "php", "bin/console.php", "mail"])
    assert any(m["sent_at"] for m in json.loads(helper("mail")))
    print("PASS: registration, verification, reset, uploads, filters, favorites, digest, anonymous history, offers, counteroffers, buyout, reservation, completion, reviews, chat access, blocking, reports, moderation, email delivery, CSRF, authorization and concurrent acceptance")


try:
    run()
finally:
    helper("cleanup")
