"""Сбор контактов с сайта компании: главная и страницы «Контакты», «О компании», «Реквизиты»."""
import html
import re
from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse

import requests

from .contacts import extract_emails, extract_phones, normalize_email, normalize_phone

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/128.0 Safari/537.36"
)
MAX_BYTES = 1_500_000
CONTACT_HINTS = ("contact", "kontakt", "контакт", "about", "o-kompanii", "o_kompanii", "о компании",
                 "company", "rekvizit", "реквизит", "svyaz", "связ", "team", "komanda", "команда",
                 "rukovod", "руковод", "staff", "sotrudn", "сотрудн")
MESSENGER_RE = re.compile(
    r"https?://(?:www\.)?(?:t\.me|telegram\.me|wa\.me|api\.whatsapp\.com|vk\.com|vk\.ru|ok\.ru"
    r"|max\.ru|rutube\.ru|dzen\.ru|youtube\.com)/[^\s\"'<>]+",
    re.I,
)
_OBFUSCATED_AT = re.compile(r"\s*(?:\[at\]|\(at\)|\{at\}|\[собака\]|\(собака\))\s*", re.I)
_OBFUSCATED_DOT = re.compile(r"\s*(?:\[dot\]|\(dot\)|\[точка\]|\(точка\))\s*", re.I)


class _PageParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.links = []  # (href, текст ссылки)
        self.text = []
        self._skip = 0
        self._href = None
        self._link_text = []

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style", "noscript", "svg"):
            self._skip += 1
        elif tag == "a":
            self._href = dict(attrs).get("href") or ""
            self._link_text = []

    def handle_endtag(self, tag):
        if tag in ("script", "style", "noscript", "svg") and self._skip:
            self._skip -= 1
        elif tag == "a" and self._href is not None:
            self.links.append((self._href, " ".join(self._link_text).strip()))
            self._href = None

    def handle_data(self, data):
        if self._skip:
            return
        self.text.append(data)
        if self._href is not None:
            self._link_text.append(data)


class SiteScraper:
    def __init__(self, timeout=10, max_pages=5, session=None):
        self.timeout = timeout
        self.max_pages = max_pages
        self.session = session or requests.Session()
        self.session.headers.update({"User-Agent": USER_AGENT, "Accept-Language": "ru,en;q=0.8"})

    def fetch(self, url):
        """(финальный URL, html) или (None, None)."""
        try:
            with self.session.get(url, timeout=self.timeout, stream=True, allow_redirects=True) as r:
                if r.status_code != 200 or "html" not in r.headers.get("Content-Type", "html"):
                    return None, None
                raw = b""
                for chunk in r.iter_content(65536):
                    raw += chunk
                    if len(raw) > MAX_BYTES:
                        break
                enc = r.encoding
                if not enc or enc.lower() == "iso-8859-1":
                    m = re.search(rb"charset=[\"']?([\w\-]+)", raw[:4000], re.I)
                    enc = m.group(1).decode() if m else "utf-8"
                try:
                    return r.url, raw.decode(enc, errors="replace")
                except LookupError:
                    return r.url, raw.decode("utf-8", errors="replace")
        except requests.RequestException:
            return None, None

    def open_home(self, site):
        """Пробует https, затем http. Возвращает (url, html) рабочей главной."""
        site = site.strip()
        if site.startswith(("http://", "https://")):
            candidates = [site]
        else:
            host = site.strip("/")
            candidates = [f"https://{host}/", f"http://{host}/"]
            if not host.startswith("www."):
                candidates.append(f"https://www.{host}/")
        for url in candidates:
            final, page = self.fetch(url)
            if page:
                return final, page
        return None, None

    def scrape(self, site, inn=None):
        """Контакты с сайта. site: домен или URL."""
        home_url, home = self.open_home(site)
        result = {"site": None, "phones": [], "emails": [], "messengers": [], "inn_found": False}
        if not home:
            return result
        result["site"] = _root_url(home_url)
        home_host = _bare_host(home_url)

        pages = [(home_url, home)]
        seen = {home_url.rstrip("/")}
        for href, _ in _contact_links(home, home_url, home_host):
            if len(pages) >= self.max_pages:
                break
            if href.rstrip("/") in seen:
                continue
            seen.add(href.rstrip("/"))
            final, page = self.fetch(href)
            if page:
                pages.append((final, page))

        for _, page in pages:
            _collect(page, result, inn)
        return result


def _collect(page, result, inn):
    parser = _PageParser()
    try:
        parser.feed(page)
    except Exception:  # битая разметка не должна ронять весь прогон
        pass
    text = html.unescape(" ".join(parser.text))
    text = _OBFUSCATED_DOT.sub(".", _OBFUSCATED_AT.sub("@", text))

    for href, _ in parser.links:
        h = html.unescape(href).strip()
        low = h.lower()
        if low.startswith("tel:"):
            _add(result["phones"], normalize_phone(h[4:]))
        elif low.startswith("mailto:"):
            _add(result["emails"], normalize_email(h))
        elif MESSENGER_RE.match(h):
            _add(result["messengers"], h.split("?")[0].rstrip("/"))

    for p in extract_phones(text):
        _add(result["phones"], p)
    for e in extract_emails(text):
        _add(result["emails"], e)
    for m in MESSENGER_RE.findall(page):
        _add(result["messengers"], html.unescape(m).split("?")[0].rstrip("/"))

    if inn and not result["inn_found"] and re.search(rf"(?<!\d){re.escape(inn)}(?!\d)", text):
        result["inn_found"] = True


def _contact_links(page, base_url, home_host):
    parser = _PageParser()
    try:
        parser.feed(page)
    except Exception:
        return []
    scored = []
    for href, label in parser.links:
        if not href or href.startswith(("#", "mailto:", "tel:", "javascript:")):
            continue
        url = urljoin(base_url, href).split("#")[0]
        if _bare_host(url) != home_host:
            continue
        hay = (url + " " + label).lower()
        score = sum(1 for hint in CONTACT_HINTS if hint in hay)
        if score:
            # «Контакты» важнее «О компании»
            if "contact" in hay or "kontakt" in hay or "контакт" in hay:
                score += 5
            scored.append((score, url))
    scored.sort(key=lambda s: -s[0])
    out, seen = [], set()
    for _, url in scored:
        if url not in seen:
            seen.add(url)
            out.append((url, ""))
    return out


def _bare_host(url):
    host = (urlparse(url).hostname or "").lower()
    return host[4:] if host.startswith("www.") else host


def _root_url(url):
    p = urlparse(url)
    return f"{p.scheme}://{p.netloc}"


def _add(bucket, value):
    if value and value not in bucket:
        bucket.append(value)
