"""Клиент DaData: поиск организации или ИП по ИНН (findById/party)."""
import time

import requests

FIND_PARTY_URL = "https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party"

STATUS_RU = {
    "ACTIVE": "действует",
    "LIQUIDATING": "ликвидируется",
    "LIQUIDATED": "ликвидирована",
    "BANKRUPT": "банкротство",
    "REORGANIZING": "реорганизация",
}


class DaDataError(Exception):
    def __init__(self, message, fatal=False):
        super().__init__(message)
        self.fatal = fatal  # ключ не принят или кончился лимит: дальше идти бессмысленно


class DaData:
    def __init__(self, api_key, secret=None, timeout=15, retries=3, session=None):
        if not api_key:
            raise DaDataError("не задан DADATA_API_KEY")
        self.session = session or requests.Session()
        self.session.headers.update({
            "Authorization": f"Token {api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        })
        if secret:
            self.session.headers["X-Secret"] = secret
        self.timeout = timeout
        self.retries = retries

    def find_party(self, inn, kpp=None):
        """Сырой ответ DaData по ИНН (и КПП для конкретного филиала) или None."""
        body = {"query": inn, "count": 1}
        if kpp:
            body["kpp"] = kpp
        else:
            body["branch_type"] = "MAIN"
        suggestions = self._post(body)
        if not suggestions and kpp:
            # КПП из CRM мог устареть: берём головную организацию
            suggestions = self._post({"query": inn, "count": 1, "branch_type": "MAIN"})
        return suggestions[0] if suggestions else None

    def _post(self, body):
        delay = 1.0
        for attempt in range(self.retries + 1):
            try:
                r = self.session.post(FIND_PARTY_URL, json=body, timeout=self.timeout)
            except requests.RequestException as e:
                if attempt == self.retries:
                    raise DaDataError(f"сеть: {e}") from e
            else:
                if r.status_code == 200:
                    return r.json().get("suggestions") or []
                if r.status_code in (401, 403):
                    raise DaDataError(f"ключ не принят ({r.status_code}), проверьте DADATA_API_KEY", fatal=True)
                if r.status_code == 402:
                    raise DaDataError("закончился баланс или дневной лимит DaData", fatal=True)
                if r.status_code not in (429, 500, 502, 503, 504) or attempt == self.retries:
                    raise DaDataError(f"HTTP {r.status_code}: {r.text[:200]}")
            time.sleep(delay)
            delay *= 2
        return []


def parse_party(suggestion):
    """Выжимка из ответа DaData: только то, что нужно для обогащения."""
    if not suggestion:
        return None
    d = suggestion.get("data") or {}
    state = d.get("state") or {}
    management = d.get("management") or {}

    people = []  # ФИО руководителей и учредителей-физлиц, для поиска именных ящиков
    director = management.get("name") or ""
    if d.get("type") == "INDIVIDUAL" and d.get("fio"):
        fio = d["fio"]
        director = " ".join(filter(None, [fio.get("surname"), fio.get("name"), fio.get("patronymic")]))
    if director:
        people.append(director)
    for group in ("managers", "founders"):
        for p in d.get(group) or []:
            fio = p.get("fio") or {}
            full = " ".join(filter(None, [fio.get("surname"), fio.get("name"), fio.get("patronymic")]))
            if full and full not in people:
                people.append(full)

    return {
        "name": suggestion.get("value") or "",
        "type": d.get("type") or "",
        "status": STATUS_RU.get(state.get("status"), state.get("status") or ""),
        "director": director,
        "director_post": management.get("post") or ("ИП" if d.get("type") == "INDIVIDUAL" else ""),
        "people": people,
        "phones": [(p.get("value") or "") for p in d.get("phones") or [] if p],
        "emails": [(e.get("value") or "") for e in d.get("emails") or [] if e],
        "address": ((d.get("address") or {}).get("value")) or "",
    }
