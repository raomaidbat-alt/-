from enrich.search import candidate_domains, parse_bing, parse_duckduckgo

DDG = '''<div class="result"><h2 class="result__title">
<a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.rusprofile.ru%2Fid%2F1&amp;rut=x">Rusprofile</a></h2></div>
<div class="result"><a rel="nofollow" class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.firma.ru%2Fcontacts&amp;rut=y">Фирма</a></div>'''

BING = '''<ol><li class="b_algo"><div><h2><a href="https://list-org.com/company/1" h="x">ООО</a></h2></div></li>
<li class="b_algo"><h2><a href="https://firma.ru/rekvizity/">Реквизиты</a></h2></li></ol>'''


def test_parsers():
    assert parse_duckduckgo(DDG) == ["https://www.rusprofile.ru/id/1", "https://www.firma.ru/contacts"]
    assert parse_bing(BING) == ["https://list-org.com/company/1", "https://firma.ru/rekvizity/"]


def test_candidate_domains_skip_aggregators():
    urls = ["https://www.rusprofile.ru/id/1", "https://spb.zoon.ru/x", "https://www.firma.ru/a",
            "https://firma.ru/b", "https://kad.arbitr.ru/", "https://minfin.gov.ru/", "http://127.0.0.1:8080/"]
    assert candidate_domains(urls) == ["firma.ru", "127.0.0.1:8080"]
