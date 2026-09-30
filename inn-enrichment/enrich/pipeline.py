"""Обогащение одной строки и всей таблицы."""
import re
from concurrent.futures import ThreadPoolExecutor

from .contacts import (classify_email, email_domain, format_phone, is_mobile, is_public_domain,
                       normalize_email, normalize_phone)
from .dadata import DaDataError, parse_party
from .search import SearchError, candidate_domains

NEW_COLUMNS = [
    "Статус (DaData)",
    "Руководитель (DaData)",
    "Основной телефон",
    "Телефоны мобильные",
    "Телефоны городские",
    "Основной email",
    "Email именные",
    "Email общие",
    "Сайт",
    "ИНН на сайте",
    "Мессенджеры и соцсети",
    "Источники",
    "Комментарий",
]


def normalize_inn(raw):
    """ИНН из ячейки: убирает '.0' из Excel и восстанавливает потерянный ведущий ноль."""
    if raw is None:
        return None
    s = str(raw).strip()
    if re.fullmatch(r"\d+\.0+", s):
        s = s.split(".")[0]
    s = re.sub(r"\D", "", s)
    if len(s) in (9, 11):
        s = "0" + s
    return s if len(s) in (10, 12) else None


def inn_is_valid(inn):
    d = [int(c) for c in inn]

    def check(weights, n):
        return sum(w * x for w, x in zip(weights, d)) % 11 % 10 == d[n]

    if len(d) == 10:
        return check([2, 4, 10, 3, 5, 9, 4, 6, 8], 9)
    if len(d) == 12:
        return (check([7, 2, 4, 10, 3, 5, 9, 4, 6, 8], 10)
                and check([3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8], 11))
    return False


def normalize_kpp(raw):
    if raw is None:
        return None
    s = str(raw).strip()
    if re.fullmatch(r"\d+\.0+", s):
        s = s.split(".")[0]
    s = re.sub(r"\D", "", s)
    if len(s) == 8:
        s = "0" + s
    return s if len(s) == 9 else None


def find_column(headers, *names):
    """Индекс колонки по названию без учёта регистра и пробелов."""
    norm = [re.sub(r"\s+", " ", str(h or "")).strip().lower() for h in headers]
    for name in names:
        n = name.lower()
        if n in norm:
            return norm.index(n)
    for name in names:
        n = name.lower()
        for i, h in enumerate(norm):
            if h.startswith(n):
                return i
    return None


class Enricher:
    def __init__(self, dadata=None, scraper=None, cache=None, search=None):
        self.dadata = dadata
        self.scraper = scraper
        self.cache = cache
        self.search = search
        # без DaData её колонки всегда пустые, в результат их не добавляем
        self.columns = NEW_COLUMNS if dadata else [c for c in NEW_COLUMNS if "DaData" not in c]

    def party(self, inn, kpp):
        key = f"dadata:{inn}:{kpp or ''}"
        cached = self.cache.get(key) if self.cache else None
        if cached is not None:
            return cached.get("party")
        party = parse_party(self.dadata.find_party(inn, kpp))
        if self.cache:
            self.cache.set(key, {"party": party})
        return party

    def site(self, domain, inn):
        # ИНН в ключе: признак «ИНН на сайте» относится к паре сайт + компания
        key = f"site:{domain}:{inn}"
        cached = self.cache.get(key) if self.cache else None
        if cached is None:
            cached = self.scraper.scrape(domain, inn=inn)
            if self.cache:
                self.cache.set(key, cached)
        return cached

    def find_site(self, inn, name=None, skip=()):
        """Ищет сайт в поисковике и берёт первый, на котором указан этот ИНН."""
        key = f"search:{inn}"
        cached = self.cache.get(key) if self.cache else None
        if cached is not None:
            return cached or None
        queries = [f'"{inn}"']
        if name:
            queries.append(f"{_clean_name(name)} ИНН {inn}")
        tried, found = set(skip), ""
        for q in queries:
            for domain in candidate_domains(self.search.search(q)):
                if domain in tried:
                    continue
                tried.add(domain)
                data = self.site(domain, inn)
                if data.get("site") and data.get("inn_found"):
                    found = domain
                    break
                if len(tried) >= 6:
                    break
            if found or len(tried) >= 6:
                break
        if self.cache:
            self.cache.set(key, found)
        return found or None

    def enrich(self, inn_raw, kpp_raw=None, site_hint=None, known_people=(), name=None):
        out = dict.fromkeys(NEW_COLUMNS, "")
        inn = normalize_inn(inn_raw)
        if not inn:
            out["Комментарий"] = "нет ИНН или неверная длина"
            return out
        if not inn_is_valid(inn):
            out["Комментарий"] = "ИНН не проходит проверку контрольной суммы"
            return out
        kpp = normalize_kpp(kpp_raw) if len(inn) == 10 else None

        notes, sources = [], []
        phones, emails, people = [], [], [p for p in known_people if p]

        party = None
        if self.dadata:
            try:
                party = self.party(inn, kpp)
            except DaDataError as e:
                if e.fatal:
                    raise
                notes.append(f"DaData: {e}")
            if not party and not notes:
                notes.append("DaData не нашла ИНН")
        if party:
            out["Статус (DaData)"] = party["status"]
            if party["director"]:
                post = party["director_post"]
                out["Руководитель (DaData)"] = f"{party['director']} ({post.lower()})" if post else party["director"]
            people += party["people"]
            dd_phones = [p for p in map(normalize_phone, party["phones"]) if p]
            dd_emails = [e for e in map(normalize_email, party["emails"]) if e]
            phones += [(p, "DaData") for p in dd_phones]
            emails += [(e, "DaData") for e in dd_emails]
            if dd_phones or dd_emails:
                sources.append("DaData")
            if party["status"] and party["status"] != "действует":
                notes.append(f"компания: {party['status']}")
            name = name or party["name"]

        if self.scraper:
            domains = _site_candidates(site_hint, [e for e, _ in emails])
            found, search_error = None, None
            for domain in domains:
                data = self.site(domain, inn)
                if data.get("site"):
                    found = (domain, data, False)
                    break
            if (not found or not found[1]["inn_found"]) and self.search:
                try:
                    domain = self.find_site(inn, name, skip=domains)
                except SearchError as e:
                    domain = None
                    search_error = str(e)
                if domain:
                    found = (domain, self.site(domain, inn), True)
            if found:
                domain, data, by_search = found
                out["Сайт"] = data["site"]
                out["ИНН на сайте"] = "да" if data["inn_found"] else "нет"
                phones += [(p, "сайт") for p in data["phones"]]
                emails += [(e, "сайт") for e in data["emails"]]
                out["Мессенджеры и соцсети"] = ", ".join(data["messengers"][:10])
                sources.append("сайт (найден поиском)" if by_search else "сайт")
                if not data["inn_found"]:
                    notes.append("ИНН на сайте не найден, проверьте, что сайт этой компании")
            elif domains:
                notes.append(f"сайт не открылся: {', '.join(domains)}")
            elif search_error:
                notes.append(f"поиск: {search_error}, повторите прогон позже")
            elif self.search:
                notes.append("сайт с этим ИНН в поиске не найден")

        surnames = [p.split()[0] for p in people if p.split()]
        mobiles = _uniq(p for p, _ in phones if is_mobile(p))
        landlines = _uniq(p for p, _ in phones if not is_mobile(p))
        named = _uniq(e for e, _ in emails if classify_email(e, surnames) == "именной")
        common = _uniq(e for e, _ in emails if e not in named)

        out["Телефоны мобильные"] = ", ".join(map(format_phone, mobiles))
        out["Телефоны городские"] = ", ".join(map(format_phone, landlines))
        best_phone = (mobiles or landlines or [None])[0]
        out["Основной телефон"] = format_phone(best_phone) if best_phone else ""
        out["Email именные"] = ", ".join(named)
        out["Email общие"] = ", ".join(common)
        out["Основной email"] = (named or common or [""])[0]
        out["Источники"] = ", ".join(_uniq(sources))
        if not phones and not emails and not notes:
            notes.append("контакты не найдены")
        out["Комментарий"] = "; ".join(notes)
        return out


def _clean_name(name):
    """ООО "РОМАШКА" -> РОМАШКА: форма собственности в поиске только мешает."""
    s = re.sub(r"[\"«»“”']", " ", str(name))
    s = re.sub(r"^\s*(ООО|ОАО|ЗАО|ПАО|АО|ИП|НКО|АНО|ФГУП|МУП|ГУП)\s+", "", s.strip(), flags=re.I)
    return re.sub(r"\s+", " ", s).strip()


def _site_candidates(site_hint, emails):
    """Домены для проверки: сайт из файла, затем домены корпоративных почт."""
    out = []
    if site_hint:
        for part in re.split(r"[,;\s]+", str(site_hint)):
            host = re.sub(r"^https?://", "", part.strip().lower()).split("/")[0]
            host = host[4:] if host.startswith("www.") else host
            if "." in host and host not in out:
                out.append(host)
    for e in emails:
        d = email_domain(e)
        if not is_public_domain(d) and d not in out:
            out.append(d)
    return out[:3]


def _uniq(items):
    seen, out = set(), []
    for x in items:
        if x not in seen:
            seen.add(x)
            out.append(x)
    return out


def enrich_table(headers, rows, enricher, workers=8, progress=None):
    """Возвращает (новые заголовки, новые строки). rows: списки значений."""
    inn_i = find_column(headers, "ИНН")
    if inn_i is None:
        raise ValueError("в файле нет колонки «ИНН»")
    kpp_i = find_column(headers, "КПП")
    site_i = find_column(headers, "Сайт", "Веб-сайт", "Web", "Сайт компании")
    fio_cols = [find_column(headers, n) for n in
                ("Фамилия руководителя", "Имя руководителя", "Отчество руководителя")]
    name_i = find_column(headers, "Название (ФИО)", "Название")

    def cell(row, i):
        return row[i] if i is not None and i < len(row) else None

    def one(row):
        director = " ".join(str(cell(row, i)).strip() for i in fio_cols if cell(row, i))
        people = [director]
        inn = normalize_inn(cell(row, inn_i))
        # у ИП название и есть ФИО
        if inn and len(inn) == 12 and cell(row, name_i):
            people.append(re.sub(r"^ИП\s+", "", str(cell(row, name_i)).strip(), flags=re.I))
        try:
            return enricher.enrich(cell(row, inn_i), cell(row, kpp_i), cell(row, site_i), people,
                                   name=cell(row, name_i))
        except DaDataError as e:
            if e.fatal:
                raise
            res = dict.fromkeys(NEW_COLUMNS, "")
            res["Комментарий"] = f"ошибка: {e}"
            return res
        except Exception as e:  # одна плохая строка не должна останавливать файл
            res = dict.fromkeys(NEW_COLUMNS, "")
            res["Комментарий"] = f"ошибка: {e}"
            return res

    results = []
    with ThreadPoolExecutor(max_workers=max(1, workers)) as pool:
        for n, res in enumerate(pool.map(one, rows), 1):
            results.append(res)
            if progress:
                progress(n, len(rows))

    columns = getattr(enricher, "columns", NEW_COLUMNS)
    extra = [c for c in columns if c not in headers]
    new_headers = list(headers) + extra
    new_rows = []
    for row, res in zip(rows, results):
        row = list(row) + [None] * (len(headers) - len(row))
        for c in columns:
            if c in headers:  # повторный прогон уже обогащённого файла: перезаписываем
                row[headers.index(c)] = res[c]
        new_rows.append(row + [res[c] for c in extra])
    return new_headers, new_rows
