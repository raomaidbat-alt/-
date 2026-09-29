import threading
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

import pytest

from enrich.cache import Cache
from enrich.pipeline import Enricher, enrich_table
from enrich.site import SiteScraper
from enrich.table import read_table, write_table

HOME = """<html><head><meta charset="windows-1251"></head><body>
<a href="/contacts/">Контакты</a> <a href="https://other.ru/contacts">чужой</a>
<script>var x = "+7 999 111-22-33";</script>
<p>Звоните: 8 (843) 200-10-20</p></body></html>"""
CONTACTS = """<html><body><h1>Контакты</h1>
<a href="tel:+79171234567">Директор</a>
<a href="mailto:info@firma.test">info@firma.test</a>
<p>Иванов Пётр: p.ivanov[at]firma.test</p>
<a href="https://t.me/firma_bot?start=1">Telegram</a>
<p>ИНН 7707083893</p></body></html>"""


@pytest.fixture
def site(tmp_path):
    (tmp_path / "index.html").write_bytes(HOME.encode("cp1251"))
    (tmp_path / "contacts").mkdir()
    (tmp_path / "contacts" / "index.html").write_text(CONTACTS, encoding="utf-8")
    handler = partial(SimpleHTTPRequestHandler, directory=str(tmp_path))
    handler.log_message = lambda *a: None
    srv = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{srv.server_address[1]}/"
    srv.shutdown()


class FakeDaData:
    def __init__(self):
        self.calls = []

    def find_party(self, inn, kpp=None):
        self.calls.append((inn, kpp))
        if inn != "7707083893":
            return None
        return {"value": "ООО \"ФИРМА\"", "data": {
            "type": "LEGAL", "state": {"status": "ACTIVE"},
            "management": {"name": "Иванов Пётр Сергеевич", "post": "ГЕНЕРАЛЬНЫЙ ДИРЕКТОР"},
            "phones": [{"value": "+7 843 200-10-20"}, {"value": "+7 917 000-11-22"}],
            "emails": [{"value": "Office@firma.test"}],
        }}


def test_scraper(site):
    data = SiteScraper(timeout=5).scrape(site, inn="7707083893")
    assert data["phones"] == ["+78432001020", "+79171234567"]  # номер из <script> не взят
    assert data["emails"] == ["info@firma.test", "p.ivanov@firma.test"]
    assert data["messengers"] == ["https://t.me/firma_bot"]
    assert data["inn_found"] is True


def test_enrich_row(site, tmp_path):
    dd = FakeDaData()
    e = Enricher(dd, SiteScraper(timeout=5), Cache(str(tmp_path / "c.sqlite")))
    out = e.enrich("7707083893", "773601001", site_hint=site)
    assert out["Статус (DaData)"] == "действует"
    assert out["Руководитель (DaData)"] == "Иванов Пётр Сергеевич (генеральный директор)"
    assert out["Основной телефон"] == "+7 917 000-11-22"
    assert out["Телефоны мобильные"] == "+7 917 000-11-22, +7 917 123-45-67"
    assert out["Телефоны городские"] == "+7 843 200-10-20"
    assert out["Email именные"] == "p.ivanov@firma.test"
    assert out["Email общие"] == "office@firma.test, info@firma.test"
    assert out["ИНН на сайте"] == "да"
    assert out["Источники"] == "DaData, сайт"
    assert out["Комментарий"] == ""
    e.enrich("7707083893", "773601001", site_hint=site)
    assert len(dd.calls) == 1  # второй раз из кэша


def test_bad_inn():
    e = Enricher(FakeDaData())
    assert "контрольной" in e.enrich("7707083894")["Комментарий"]
    assert "нет ИНН" in e.enrich(None)["Комментарий"]
    assert e.enrich("500100732259")["Комментарий"] == "DaData не нашла ИНН"


def test_table_roundtrip(tmp_path):
    src = tmp_path / "in.csv"
    src.write_bytes(("Название (ФИО);ИНН;КПП;Фамилия руководителя\n"
                     "ООО Фирма;7707083893;773601001;Иванов\n"
                     "ИП Петров;7707083894;;\n").encode("cp1251"))
    headers, rows = read_table(src)
    headers, rows = enrich_table(headers, rows, Enricher(FakeDaData()), workers=2)
    assert headers[:4] == ["Название (ФИО)", "ИНН", "КПП", "Фамилия руководителя"]
    assert rows[0][headers.index("Основной телефон")] == "+7 917 000-11-22"
    assert rows[0][headers.index("Email общие")] == "office@firma.test"
    out = tmp_path / "out.xlsx"
    write_table(out, headers, rows)
    h2, r2 = read_table(out)
    assert h2 == headers and r2[0][1] == "7707083893"
    # повторный прогон по уже обогащённому файлу не плодит колонки
    h3, _ = enrich_table(h2, r2, Enricher(FakeDaData()), workers=1)
    assert h3 == headers


def test_fatal_dadata_error_stops_run():
    from enrich.dadata import DaDataError

    class Broken:
        def find_party(self, inn, kpp=None):
            raise DaDataError("ключ не принят", fatal=True)

    with pytest.raises(DaDataError):
        enrich_table(["ИНН"], [["7707083893"]], Enricher(Broken()), workers=1)
