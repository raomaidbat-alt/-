"""Поиск сайта компании через поисковик без ключей: DuckDuckGo, при отказе Bing."""
import re
import threading
import time
from html import unescape
from urllib.parse import parse_qs, quote_plus, urlparse

import requests

from .site import USER_AGENT

# Агрегаторы реквизитов, справочники, соцсети и площадки: сайтом компании они не бывают
EXCLUDED = (
    "rusprofile.ru", "list-org.com", "checko.ru", "zachestnyibiznes.ru", "sbis.ru", "saby.ru",
    "audit-it.ru", "companies.rbc.ru", "rbc.ru", "spark-interfax.ru", "focus.kontur.ru", "kontur.ru",
    "e-ecolog.ru", "vbankcenter.ru", "synapsenet.ru", "nalog.ru", "nalog.gov.ru", "egrul.ru",
    "ogrn.site", "rus-profile.ru", "orgpage.ru", "yell.ru", "zoon.ru", "2gis.ru", "2gis.com",
    "yandex.ru", "ya.ru", "google.com", "google.ru", "bing.com", "duckduckgo.com", "mail.ru",
    "vk.com", "vk.ru", "ok.ru", "t.me", "youtube.com", "rutube.ru", "dzen.ru", "wikipedia.org",
    "hh.ru", "superjob.ru", "rabota.ru", "zarplata.ru", "avito.ru", "ozon.ru", "wildberries.ru",
    "zakupki.gov.ru", "gosuslugi.ru", "pravo.gov.ru", "kad.arbitr.ru", "arbitr.ru", "fedresurs.ru",
    "bankrot.fedresurs.ru", "fssp.gov.ru", "cbr.ru", "tenderguru.ru", "rostender.info",
    "b2b-center.ru", "bicotender.ru", "tenderplan.ru", "clearspending.ru", "companium.ru",
    "egrulinfo.ru", "igk-group.ru", "k-agent.ru", "prima-inform.ru", "ofdata.ru", "kartoteka.ru",
    "gkrf.ru", "moeoffice.ru", "b-kontur.ru", "reestr-dogovorov.ru", "catalog.ru", "spravker.ru",
    "fira.ru", "bo.nalog.ru", "pb.nalog.ru", "vypiska-nalog.com", "ruscompany.ru", "rucompany.ru",
    "inn-info.ru", "innproverka.ru", "reputation.ru",
)


class SearchError(Exception):
    pass


class WebSearch:
    def __init__(self, timeout=15, min_interval=2.5, session=None):
        self.timeout = timeout
        self.min_interval = min_interval  # поисковики блокируют частые запросы
        self.session = session or requests.Session()
        self.session.headers.update({"User-Agent": USER_AGENT, "Accept-Language": "ru,en;q=0.8"})
        self._lock = threading.Lock()
        self._last = 0.0

    def search(self, query):
        """Список URL из выдачи. SearchError, если оба поисковика недоступны или заблокировали запрос."""
        answered = False
        for engine in (self._duckduckgo, self._bing):
            self._wait()
            try:
                urls = engine(query)
            except requests.RequestException:
                continue
            if urls is None:  # капча или отказ
                continue
            answered = True
            if urls:
                return urls
        if not answered:
            raise SearchError("поисковики недоступны или просят капчу")
        return []

    def _wait(self):
        with self._lock:
            pause = self._last + self.min_interval - time.time()
            if pause > 0:
                time.sleep(pause)
            self._last = time.time()

    def _duckduckgo(self, query):
        r = self.session.post("https://html.duckduckgo.com/html/", data={"q": query, "kl": "ru-ru"},
                              timeout=self.timeout)
        if r.status_code != 200:
            return None
        return parse_duckduckgo(r.text)

    def _bing(self, query):
        r = self.session.get(f"https://www.bing.com/search?q={quote_plus(query)}&setlang=ru&cc=RU",
                             timeout=self.timeout)
        if r.status_code != 200:
            return None
        return parse_bing(r.text)


def parse_duckduckgo(page):
    urls = []
    for href in re.findall(r'class="result__a"[^>]*href="([^"]+)"|href="([^"]+)"[^>]*class="result__a"', page):
        href = unescape(href[0] or href[1])
        if "uddg=" in href:
            href = parse_qs(urlparse(href if href.startswith("http") else "https:" + href).query)["uddg"][0]
        if href.startswith("http"):
            urls.append(href)
    return urls


def parse_bing(page):
    urls = []
    for block in re.findall(r'<li class="b_algo".*?</h2>', page, re.S):
        m = re.search(r'<a[^>]+href="(https?://[^"]+)"', block)
        if m:
            urls.append(unescape(m.group(1)))
    return urls


def candidate_domains(urls, limit=4):
    """Домены из выдачи без агрегаторов, соцсетей и госсайтов."""
    out = []
    for url in urls:
        host = urlparse(url).netloc.lower().rsplit("@", 1)[-1]
        host = host[4:] if host.startswith("www.") else host
        if not host or host in out:
            continue
        bare = host.split(":")[0]
        if any(bare == d or bare.endswith("." + d) for d in EXCLUDED) or bare.endswith(".gov.ru"):
            continue
        out.append(host)
        if len(out) >= limit:
            break
    return out
