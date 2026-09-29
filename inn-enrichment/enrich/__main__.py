"""python -m enrich companies.xlsx -o result.xlsx"""
import argparse
import sys
import time
from pathlib import Path

from .config import ROOT, build_enricher
from .dadata import DaDataError
from .pipeline import enrich_table
from .table import read_table, write_table


def main(argv=None):
    ap = argparse.ArgumentParser(prog="python -m enrich", description="Обогащение компаний по ИНН")
    ap.add_argument("input", help="CSV или XLSX с колонкой «ИНН»")
    ap.add_argument("-o", "--output", help="куда сохранить (.xlsx или .csv), по умолчанию <имя>_enriched.xlsx")
    ap.add_argument("--no-site", action="store_true", help="не ходить на сайты, только DaData")
    ap.add_argument("--workers", type=int, default=8, help="параллельных потоков (по умолчанию 8)")
    ap.add_argument("--cache", default=str(ROOT / "var" / "cache.sqlite"), help="файл кэша")
    ap.add_argument("--no-cache", action="store_true", help="не использовать кэш")
    ap.add_argument("--cache-days", type=int, default=30, help="сколько дней хранить кэш")
    args = ap.parse_args(argv)

    src = Path(args.input)
    out = Path(args.output) if args.output else src.with_name(src.stem + "_enriched.xlsx")

    try:
        enricher = build_enricher(not args.no_site, None if args.no_cache else args.cache, args.cache_days)
        headers, rows = read_table(src)
    except (DaDataError, ValueError, OSError) as e:
        print(f"Ошибка: {e}", file=sys.stderr)
        return 1

    started = time.time()

    def progress(n, total):
        if n == total or n % 10 == 0:
            print(f"\r{n}/{total}  {time.time() - started:.0f} c", end="", file=sys.stderr, flush=True)

    try:
        headers, rows = enrich_table(headers, rows, enricher, args.workers, progress)
    except (ValueError, DaDataError) as e:
        print(file=sys.stderr)
        print(f"Ошибка: {e}", file=sys.stderr)
        return 1
    print(file=sys.stderr)
    write_table(out, headers, rows)

    phone_i = headers.index("Основной телефон")
    email_i = headers.index("Основной email")
    with_phone = sum(1 for r in rows if r[phone_i])
    with_email = sum(1 for r in rows if r[email_i])
    print(f"Готово: {out}\nСтрок: {len(rows)}, с телефоном: {with_phone}, с email: {with_email}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
