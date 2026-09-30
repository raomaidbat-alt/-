from enrich.contacts import classify_email, extract_emails, extract_phones, normalize_phone
from enrich.pipeline import inn_is_valid, normalize_inn, normalize_kpp


def test_normalize_phone():
    assert normalize_phone("8 (912) 345-67-89") == "+79123456789"
    assert normalize_phone("+7 495 123 45 67") == "+74951234567"
    assert normalize_phone("7707083893") is None  # ИНН, не телефон
    assert normalize_phone("+7 000 000 00 00") is None


def test_extract_phones_skips_requisites():
    text = "ИНН 7707083893, ОГРН 1027700132195. Тел.: +7 (495) 500-55-50, моб. 8-912-345-67-89"
    assert extract_phones(text) == ["+74955005550", "+79123456789"]


def test_extract_emails():
    text = "Пишите: Info@Firma.ru, logo@2x.png, ivanov@mail.ru."
    assert extract_emails(text) == ["info@firma.ru", "ivanov@mail.ru"]


def test_classify_email():
    assert classify_email("info@firma.ru") == "общий"
    assert classify_email("sales2@firma.ru") == "общий"
    assert classify_email("info@mail.ru") == "общий"
    assert classify_email("petrov1980@mail.ru") == "именной"
    assert classify_email("a.ivanova@firma.ru") == "именной"
    assert classify_email("shcherbakov@firma.ru", ["Щербаков"]) == "именной"
    assert classify_email("sergeeva@firma.ru", ["Сергеева"]) == "именной"
    assert classify_email("sergeev@firma.ru", ["Сергеева"]) == "именной"


def test_inn():
    assert normalize_inn("7707083893.0") == "7707083893"
    assert normalize_inn(274062111) == "0274062111"
    assert normalize_inn("") is None
    assert inn_is_valid("7707083893")
    assert not inn_is_valid("7707083894")
    assert inn_is_valid("500100732259")
    assert normalize_kpp("773601001") == "773601001"
    assert normalize_kpp(40001001) == "040001001"
