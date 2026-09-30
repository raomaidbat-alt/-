import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def load_env(path=ROOT / ".env"):
    """Подхватывает .env без сторонних библиотек. Переменные окружения важнее файла."""
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


def build_enricher(use_site=True, cache_path=None, cache_days=30, use_search=True):
    from .cache import Cache, NoCache
    from .dadata import DaData
    from .pipeline import Enricher
    from .search import WebSearch
    from .site import SiteScraper

    load_env()
    key = os.environ.get("DADATA_API_KEY")
    dadata = DaData(key, os.environ.get("DADATA_SECRET_KEY")) if key else None
    if not dadata and not use_site:
        raise ValueError("нет ни ключа DaData, ни поиска по сайтам: искать контакты негде")
    if cache_path:
        Path(cache_path).parent.mkdir(parents=True, exist_ok=True)
        cache = Cache(cache_path, cache_days)
    else:
        cache = NoCache()
    search = WebSearch() if use_site and use_search else None
    return Enricher(dadata, SiteScraper() if use_site else None, cache, search)
