"""Поиск, нормализация и классификация телефонов и email."""
import re

# Бесплатные почтовые сервисы: ящик на них почти всегда личный
PUBLIC_EMAIL_DOMAINS = {
    "mail.ru", "inbox.ru", "list.ru", "bk.ru", "internet.ru", "mail.ua",
    "yandex.ru", "ya.ru", "yandex.com", "yandex.by", "yandex.kz", "yandex.ua", "narod.ru",
    "gmail.com", "googlemail.com", "rambler.ru", "lenta.ru", "ro.ru", "autorambler.ru",
    "myrambler.ru", "hotmail.com", "outlook.com", "live.com", "icloud.com", "me.com",
    "yahoo.com", "aol.com", "proton.me", "protonmail.com", "vk.com", "qip.ru", "pochta.ru",
}

# Типовые ролевые ящики: общий адрес компании, а не конкретного человека
ROLE_LOCAL_PARTS = {
    "info", "office", "mail", "post", "sales", "sale", "zakaz", "order", "orders", "support",
    "help", "hello", "contact", "contacts", "admin", "reception", "secretary", "priemnaya",
    "buh", "buhgalter", "accounting", "finance", "hr", "job", "jobs", "kadry", "marketing",
    "pr", "press", "media", "manager", "shop", "market", "opt", "client", "clients",
    "service", "tender", "tenders", "snab", "zakupki", "purchase", "logistics", "sklad",
    "noreply", "no-reply", "webmaster", "director", "dir", "gendir", "company", "team",
    "feedback", "partner", "partners", "commerce", "sbyt", "otdel", "otk", "legal", "ur",
}

_EMAIL_RE = re.compile(r"[a-z0-9][a-z0-9._%+\-]{0,63}@(?:[a-z0-9\-]+\.)+[a-z]{2,24}", re.I)
_BAD_EMAIL_TAIL = (".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".css", ".js")
_BAD_EMAIL_DOMAINS = ("example.com", "example.ru", "domain.ru", "domain.com", "sentry.io",
                      "wixpress.com", "sentry-next.wixpress.com")

# Российский номер в тексте: +7/8 и 10 цифр, либо код в скобках.
# Без префикса и скобок не берём: так в выборку попадают ИНН, ОГРН и счета.
_PHONE_RE = re.compile(
    r"(?<![\d\w])(?:"
    r"(?:\+7|8|7)[\s\- ]*\(?\d{3,5}\)?[\s\- ]*\d{1,3}[\s\- ]*\d{2}[\s\- ]*\d{2}"
    r"|\(\d{3,5}\)[\s\- ]*\d{1,3}[\s\- ]*\d{2}[\s\- ]*\d{2}"
    r")(?!\d)"
)

_TRANSLIT = {
    "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e", "ж": "zh", "з": "z",
    "и": "i", "й": "y", "к": "k", "л": "l", "м": "m", "н": "n", "о": "o", "п": "p", "р": "r",
    "с": "s", "т": "t", "у": "u", "ф": "f", "х": "kh", "ц": "ts", "ч": "ch", "ш": "sh",
    "щ": "shch", "ъ": "", "ы": "y", "ь": "", "э": "e", "ю": "yu", "я": "ya",
}


def normalize_phone(raw):
    """Возвращает номер в виде +7XXXXXXXXXX или None."""
    if not raw:
        return None
    digits = re.sub(r"\D", "", str(raw))
    if len(digits) == 11 and digits[0] in "78":
        digits = digits[1:]
    if len(digits) != 10 or digits[0] not in "3489":
        return None
    if len(set(digits)) <= 2:  # 0000000000, 9999999999 и прочие заглушки
        return None
    return "+7" + digits


def is_mobile(phone):
    return phone.startswith("+79")


def format_phone(phone):
    d = phone[2:]
    return f"+7 {d[0:3]} {d[3:6]}-{d[6:8]}-{d[8:10]}"


def extract_phones(text):
    found = []
    for m in _PHONE_RE.finditer(text or ""):
        p = normalize_phone(m.group(0))
        if p and p not in found:
            found.append(p)
    return found


def normalize_email(raw):
    if not raw:
        return None
    e = str(raw).strip().strip(".,;:").lower()
    if e.startswith("mailto:"):
        e = e[7:].split("?")[0]
    if not _EMAIL_RE.fullmatch(e):
        return None
    if e.endswith(_BAD_EMAIL_TAIL) or email_domain(e) in _BAD_EMAIL_DOMAINS:
        return None
    return e


def extract_emails(text):
    found = []
    for m in _EMAIL_RE.finditer(text or ""):
        e = normalize_email(m.group(0))
        if e and e not in found:
            found.append(e)
    return found


def email_domain(email):
    return email.rsplit("@", 1)[-1]


def is_public_domain(domain):
    return domain.lower() in PUBLIC_EMAIL_DOMAINS


def translit(text):
    return "".join(_TRANSLIT.get(ch, ch) for ch in (text or "").lower())


def surname_variants(surname):
    """Варианты написания фамилии латиницей: ivanov, ivanova, shcherbakov/scherbakov."""
    base = translit(surname).strip()
    if len(base) < 3:
        return set()
    variants = {base, base.replace("shch", "sch"), base.replace("kh", "h"), base.replace("yu", "iu")}
    # Иванов / Иванова: одна и та же фамилия в разном роде
    for v in list(variants):
        if v.endswith("a") and len(v) > 4:
            variants.add(v[:-1])
    return variants


def classify_email(email, person_surnames=()):
    """Возвращает 'именной' или 'общий'.

    Именной: на публичном домене (кроме ролевых вроде info@mail.ru) или адрес
    содержит фамилию руководителя / учредителя.
    """
    local, domain = email.rsplit("@", 1)
    local_clean = re.sub(r"[\d_]+$", "", local)
    for surname in person_surnames:
        for v in surname_variants(surname):
            if v and v in local:
                return "именной"
    if local_clean in ROLE_LOCAL_PARTS or local.split(".")[0] in ROLE_LOCAL_PARTS:
        return "общий"
    if is_public_domain(domain):
        return "именной"
    # i.ivanov@, ivanov.i@, ivan.petrov@ на корпоративном домене
    if re.fullmatch(r"[a-z]{1,2}\.[a-z]{3,}|[a-z]{3,}\.[a-z]{1,2}|[a-z]{3,}[._][a-z]{3,}", local):
        return "именной"
    return "общий"
