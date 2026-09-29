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


def build_enricher(use_site=True, cache_path=None, cache_days=30):
    from .cache import Cache, NoCache
    from .dadata import DaData
    from .pipeline import Enricher
    from .site import SiteScraper

    load_env()
    dadata = DaData(os.environ.get("DADATA_API_KEY"), os.environ.get("DADATA_SECRET_KEY"))
    if cache_path:
        Path(cache_path).parent.mkdir(parents=True, exist_ok=True)
        cache = Cache(cache_path, cache_days)
    else:
        cache = NoCache()
    return Enricher(dadata, SiteScraper() if use_site else None, cache)
