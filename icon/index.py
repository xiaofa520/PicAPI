import ipaddress
import os
import socket
import time
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup
from flask import Flask, Response, request, send_file

app = Flask(__name__)

COMMON_ICON_RELS = [
    "icon",
    "shortcut icon",
    "apple-touch-icon",
    "apple-touch-icon-precomposed",
    "mask-icon",
    "fluid-icon",
]
DEFAULT_HEADERS = {"User-Agent": "Mozilla/5.0"}
ALLOWED_SCHEMES = {"http", "https"}
MAX_REDIRECTS = 3
MAX_ICON_BYTES = 1024 * 1024
REQUEST_TIMEOUT = 5
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DEFAULT_ICON_PATH = os.path.join(BASE_DIR, "default.ico")
REQUEST_COUNT_DIR = os.path.join(BASE_DIR, "log")
REQUEST_COUNT_FILE = os.path.join(REQUEST_COUNT_DIR, "count.txt")
REQUEST_COUNT_LOCK_FILE = os.path.join(REQUEST_COUNT_DIR, "count.lock")

session = requests.Session()


class UnsafeTargetError(ValueError):
    pass


def normalize_target_url(target_url: str) -> str:
    target_url = (target_url or "").strip()
    if not target_url:
        raise ValueError("Missing ?url= parameter")

    if not target_url.startswith(("http://", "https://")):
        target_url = "http://" + target_url

    parsed = urlparse(target_url)
    if parsed.scheme not in ALLOWED_SCHEMES or not parsed.hostname:
        raise ValueError("Only public http/https URLs are allowed")
    if parsed.username or parsed.password:
        raise ValueError("URLs with credentials are not allowed")

    return parsed.geturl()


def validate_public_url(url: str) -> str:
    parsed = urlparse(url)
    if parsed.scheme not in ALLOWED_SCHEMES or not parsed.hostname:
        raise UnsafeTargetError("Only public http/https URLs are allowed")
    if parsed.username or parsed.password:
        raise UnsafeTargetError("URLs with credentials are not allowed")

    try:
        addresses = {
            info[4][0]
            for info in socket.getaddrinfo(parsed.hostname, parsed.port or 80, type=socket.SOCK_STREAM)
        }
    except socket.gaierror as exc:
        raise UnsafeTargetError("Could not resolve target host") from exc

    if not addresses:
        raise UnsafeTargetError("Could not resolve target host")

    for address in addresses:
        try:
            ip = ipaddress.ip_address(address)
        except ValueError as exc:
            raise UnsafeTargetError("Invalid target address") from exc

        if not ip.is_global:
            raise UnsafeTargetError("Private or reserved network targets are blocked")

    return parsed.geturl()


def safe_get(url: str, *, stream: bool = False) -> requests.Response:
    current_url = validate_public_url(url)

    for _ in range(MAX_REDIRECTS + 1):
        response = session.get(
            current_url,
            headers=DEFAULT_HEADERS,
            timeout=REQUEST_TIMEOUT,
            allow_redirects=False,
            stream=stream,
        )

        if response.is_redirect or response.is_permanent_redirect:
            location = response.headers.get("Location")
            if not location:
                break

            response.close()
            current_url = validate_public_url(urljoin(current_url, location))
            continue

        return response

    raise UnsafeTargetError("Too many redirects")


def fetch_icon_url(website_url: str) -> str | None:
    try:
        response = safe_get(website_url)
        response.raise_for_status()
        soup = BeautifulSoup(response.text, "html.parser")

        for link in soup.find_all("link", rel=True):
            rel = " ".join(link.get("rel")).lower()
            if any(icon_rel in rel for icon_rel in COMMON_ICON_RELS):
                href = link.get("href")
                if href:
                    return urljoin(website_url, href)

        parsed = urlparse(website_url)
        return f"{parsed.scheme}://{parsed.netloc}/favicon.ico"
    except (requests.RequestException, UnsafeTargetError, ValueError):
        return None


def acquire_lock(lock_path: str, timeout: float = 5.0) -> int:
    deadline = time.time() + timeout
    while True:
        try:
            return os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_RDWR)
        except FileExistsError:
            if time.time() >= deadline:
                raise TimeoutError("Could not acquire lock in time")
            time.sleep(0.05)


def release_lock(lock_fd: int, lock_path: str) -> None:
    os.close(lock_fd)
    try:
        os.unlink(lock_path)
    except FileNotFoundError:
        pass


def increase_request_count() -> int:
    os.makedirs(REQUEST_COUNT_DIR, exist_ok=True)
    lock_fd = acquire_lock(REQUEST_COUNT_LOCK_FILE)

    try:
        try:
            with open(REQUEST_COUNT_FILE, "r", encoding="utf-8") as handle:
                raw_value = handle.read().strip()
                count = int(raw_value) if raw_value.isdigit() else 0
        except FileNotFoundError:
            count = 0

        count += 1

        with open(REQUEST_COUNT_FILE, "w", encoding="utf-8") as handle:
            handle.write(str(count))

        return count
    finally:
        release_lock(lock_fd, REQUEST_COUNT_LOCK_FILE)


def read_limited_content(response: requests.Response, limit: int) -> bytes:
    chunks = []
    size = 0

    for chunk in response.iter_content(chunk_size=8192):
        if not chunk:
            continue

        size += len(chunk)
        if size > limit:
            raise ValueError("Icon file is too large")

        chunks.append(chunk)

    return b"".join(chunks)


@app.route("/")
def get_favicon():
    try:
        target_url = normalize_target_url(request.args.get("url", ""))
        validate_public_url(target_url)
    except (ValueError, UnsafeTargetError) as exc:
        return str(exc), 400

    try:
        increase_request_count()
    except TimeoutError:
        pass

    icon_url = fetch_icon_url(target_url)
    if not icon_url:
        domain = urlparse(target_url).hostname
        icon_url = f"https://www.google.com/s2/favicons?domain={domain}&sz=64"

    try:
        response = safe_get(icon_url, stream=True)
        try:
            response.raise_for_status()
            content = read_limited_content(response, MAX_ICON_BYTES)
            content_type = response.headers.get("Content-Type", "image/x-icon")
        finally:
            response.close()

        if len(content) < 50:
            raise ValueError("Icon data is too small")

        return Response(content, content_type=content_type)
    except (requests.RequestException, UnsafeTargetError, ValueError):
        if os.path.exists(DEFAULT_ICON_PATH):
            return send_file(DEFAULT_ICON_PATH, mimetype="image/x-icon")
        return "Default icon file is missing", 500


if __name__ == "__main__":
    app.run(debug=False)
