/**
 * MarketingFunnelDashboard: дашборд воронки продаж и каналов привлечения.
 *
 * Один файл, три слоя:
 *   1. Data contract  — интерфейс DashboardData (сырые события + справочник каналов + охват/расходы по дням).
 *   2. Data layer     — моки (детерминированный генератор), загрузка из API, чистые функции агрегации.
 *   3. Presentation   — карточки, воронка, таблица каналов, график динамики.
 *
 * Без бэкенда рендерится на моках. Для живых данных передайте apiUrl (PHP: public/api/events.php),
 * ответ API совпадает с DashboardData один в один.
 *
 * Зависимости: react, recharts, lucide-react, tailwindcss (dark-режим через класс .dark на <html>).
 */
import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import {
  Area,
  AreaChart,
  CartesianGrid,
  Cell,
  Funnel,
  FunnelChart,
  LabelList,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Banknote,
  Clapperboard,
  Filter,
  Handshake,
  Layers,
  Mail,
  Minus,
  Moon,
  RefreshCw,
  Send,
  Sun,
  Target,
  TrendingDown,
  TrendingUp,
  UserCheck,
  Users,
  type LucideIcon,
} from "lucide-react";

/* ═══════════════════════════════════════════════════════════════════════════
 * 1. DATA CONTRACT
 * ═════════════════════════════════════════════════════════════════════════ */

export type ChannelId = string;
export type FunnelStage = "lead" | "sql" | "consult" | "paid";
export type PeriodKey = "7d" | "30d" | "quarter" | "all";
export type CurrencyCode = "RUB" | "USD" | "EUR" | string;

/** Один лид = одно событие. Моменты шагов берутся из истории стадий (снапшотов CRM). */
export interface LeadEvent {
  id: string;
  /** ISO 8601 с поясом, момент входа в воронку */
  createdAt: string;
  channel: ChannelId;
  /** Самый дальний достигнутый шаг */
  stage: FunnelStage;
  /** Лид закрыт как некачественный (брак) */
  lost: boolean;
  /** Сумма оплаты в основной валюте (0, если не оплачен) */
  revenue: number;
  sqlAt: string | null;
  consultAt: string | null;
  paidAt: string | null;
}

export interface ChannelDef {
  id: ChannelId;
  label: string;
}

/** Охват и расходы канала за день: приходят из рекламных кабинетов или конфига. */
export interface ChannelDailyStat {
  date: string; // YYYY-MM-DD
  channel: ChannelId;
  reach: number;
  spend: number;
}

export interface DashboardData {
  meta: {
    source: "mock" | "api";
    /** Время формирования данных на сервере: от него считаются периоды */
    generatedAt: string;
    /** Последняя успешная синхронизация с CRM */
    lastSyncAt: string | null;
    lastSyncStatus?: string | null;
    currency: CurrencyCode;
    timezone?: string;
  };
  channels: ChannelDef[];
  rawEvents: LeadEvent[];
  channelDaily: ChannelDailyStat[];
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 2. DATA LAYER
 * ═════════════════════════════════════════════════════════════════════════ */

// ── 2.1 Моки: детерминированный генератор, чтобы демо выглядело одинаково ──────────

function mulberry32(seed: number) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Дата в ISO с локальным смещением: '2026-09-28T14:05:00+03:00'. */
function toLocalIso(d: Date): string {
  const pad = (n: number) => String(Math.abs(n)).padStart(2, "0");
  const off = -d.getTimezoneOffset();
  const sign = off >= 0 ? "+" : "-";
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
    `T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}` +
    `${sign}${pad(Math.trunc(off / 60))}:${pad(off % 60)}`
  );
}

const DAY_MS = 86_400_000;

export function generateMockDashboardData(now: Date = new Date(), seed = 20260928): DashboardData {
  const rnd = mulberry32(seed);
  const channels: (ChannelDef & {
    perDay: number; reach: number; spend: number;
    pSql: number; pConsult: number; pPaid: number; check: [number, number];
  })[] = [
    { id: "reels", label: "Reels / Органика", perDay: 7.5, reach: 4200, spend: 11_000, pSql: 0.52, pConsult: 0.48, pPaid: 0.34, check: [18_000, 42_000] },
    { id: "telegram", label: "Telegram-канал", perDay: 4.6, reach: 1600, spend: 9_500, pSql: 0.68, pConsult: 0.58, pPaid: 0.44, check: [24_000, 58_000] },
    { id: "base", label: "База / Рассылки", perDay: 3.1, reach: 650, spend: 5_200, pSql: 0.61, pConsult: 0.55, pPaid: 0.49, check: [19_000, 38_000] },
    { id: "partners", label: "Партнеры / Инвайтинг", perDay: 1.4, reach: 320, spend: 8_500, pSql: 0.79, pConsult: 0.7, pPaid: 0.55, check: [40_000, 90_000] },
  ];
  const days = 400;
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate() - days + 1);
  const events: LeadEvent[] = [];
  const channelDaily: ChannelDailyStat[] = [];
  let id = 10_000;

  for (let i = 0; i < days; i++) {
    const day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
    const dateKey = toLocalIso(day).slice(0, 10);
    const weekday = day.getDay();
    const season = (weekday === 0 || weekday === 6 ? 0.72 : 1.06) * (0.75 + (0.45 * i) / days);

    for (const ch of channels) {
      const noise = 0.75 + rnd() * 0.5;
      channelDaily.push({
        date: dateKey,
        channel: ch.id,
        reach: Math.round(ch.reach * season * noise),
        spend: Math.round(ch.spend * (0.9 + rnd() * 0.2)),
      });
      const count = Math.round(ch.perDay * season * noise + (rnd() - 0.5));
      for (let k = 0; k < count; k++) {
        const created = new Date(day.getTime() + rnd() * DAY_MS);
        if (created > now) continue;
        const after = (from: Date, meanDays: number) => new Date(from.getTime() - Math.log(1 - rnd()) * meanDays * DAY_MS);

        let stage: FunnelStage = "lead";
        let sqlAt: Date | null = null;
        let consultAt: Date | null = null;
        let paidAt: Date | null = null;
        if (rnd() < ch.pSql) {
          sqlAt = after(created, 0.8);
          if (sqlAt <= now) {
            stage = "sql";
            if (rnd() < ch.pConsult) {
              consultAt = after(sqlAt, 2.2);
              if (consultAt <= now) {
                stage = "consult";
                if (rnd() < ch.pPaid) {
                  paidAt = after(consultAt, 3.5);
                  if (paidAt <= now) stage = "paid";
                  else paidAt = null;
                }
              } else consultAt = null;
            }
          } else sqlAt = null;
        }
        const ageDays = (now.getTime() - created.getTime()) / DAY_MS;
        const lost = stage !== "paid" && ageDays > 10 && rnd() < 0.78;
        events.push({
          id: String(id++),
          createdAt: toLocalIso(created),
          channel: ch.id,
          stage,
          lost,
          revenue: stage === "paid" ? Math.round((ch.check[0] + rnd() * (ch.check[1] - ch.check[0])) / 500) * 500 : 0,
          sqlAt: sqlAt && toLocalIso(sqlAt),
          consultAt: consultAt && toLocalIso(consultAt),
          paidAt: paidAt && toLocalIso(paidAt),
        });
      }
    }
  }
  events.sort((a, b) => a.createdAt.localeCompare(b.createdAt));

  return {
    meta: {
      source: "mock",
      generatedAt: toLocalIso(now),
      lastSyncAt: toLocalIso(new Date(now.getTime() - 17 * 60_000)),
      lastSyncStatus: "success",
      currency: "RUB",
    },
    channels: channels.map(({ id: cid, label }) => ({ id: cid, label })),
    rawEvents: events,
    channelDaily,
  };
}

// ── 2.2 Загрузка из API ────────────────────────────────────────────────────────────

export class ApiAuthError extends Error {}

/** GET {apiUrl}?period=… → DashboardData. Ответ PHP-эндпоинта уже в нужной форме, здесь только проверка типов. */
export async function fetchDashboardData(
  apiUrl: string,
  period: PeriodKey,
  token?: string,
  signal?: AbortSignal,
): Promise<DashboardData> {
  const url = new URL(apiUrl, window.location.href);
  url.searchParams.set("period", period);
  const res = await fetch(url, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
    signal,
  });
  if (res.status === 401) throw new ApiAuthError("Неверный или просроченный токен API");
  if (!res.ok) throw new Error(`API ответил ${res.status}`);
  const raw = (await res.json()) as Partial<DashboardData>;
  if (!raw.meta || !Array.isArray(raw.rawEvents) || !Array.isArray(raw.channels)) {
    throw new Error("Ответ API не похож на DashboardData");
  }
  return {
    meta: { ...raw.meta, source: "api" },
    channels: raw.channels,
    rawEvents: raw.rawEvents.map((e) => ({ ...e, revenue: Number(e.revenue) || 0 })),
    channelDaily: (raw.channelDaily ?? []).map((d) => ({ ...d, reach: Number(d.reach) || 0, spend: Number(d.spend) || 0 })),
  };
}

// ── 2.3 Нормализация и агрегаты: чистые функции ───────────────────────────────────

const STAGE_RANK: Record<FunnelStage, number> = { lead: 0, sql: 1, consult: 2, paid: 3 };
const reached = (e: LeadEvent, s: FunnelStage) => STAGE_RANK[e.stage] >= STAGE_RANK[s];
const ts = (iso: string) => Date.parse(iso);

export interface PeriodRange {
  from: number;
  to: number;
  prevFrom: number | null;
  prevTo: number | null;
  days: number;
  bucket: "day" | "week";
}

export function resolvePeriod(period: PeriodKey, nowIso: string, events: LeadEvent[]): PeriodRange {
  const now = new Date(ts(nowIso));
  const to = now.getTime();
  if (period === "all") {
    const first = events.length ? Math.min(...events.map((e) => ts(e.createdAt))) : to - 30 * DAY_MS;
    const days = Math.max(1, Math.ceil((to - first) / DAY_MS));
    return { from: first, to, prevFrom: null, prevTo: null, days, bucket: days > 45 ? "week" : "day" };
  }
  const days = period === "7d" ? 7 : period === "30d" ? 30 : 90;
  const from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - days + 1).getTime();
  const len = to - from;
  return { from, to, prevFrom: from - len - 1, prevTo: from - 1, days, bucket: days > 45 ? "week" : "day" };
}

const inRange = (iso: string | null, from: number, to: number) => iso !== null && ts(iso) >= from && ts(iso) <= to;

export interface Kpis {
  leads: number;
  sql: number;
  consult: number;
  paidCohort: number;
  crOverall: number;
  c2: number;
  payments: number;
  revenue: number;
  avgCheck: number;
}

/**
 * KPI за окно. Конверсии считаются по когорте (лиды, созданные в окне),
 * выручка и число оплат по дате оплаты (деньги, пришедшие в окне).
 */
export function computeKpis(events: LeadEvent[], from: number, to: number): Kpis {
  const cohort = events.filter((e) => inRange(e.createdAt, from, to));
  const leads = cohort.length;
  const sql = cohort.filter((e) => reached(e, "sql")).length;
  const consult = cohort.filter((e) => reached(e, "consult")).length;
  const paidCohort = cohort.filter((e) => e.stage === "paid").length;
  const paidInWindow = events.filter((e) => e.stage === "paid" && inRange(e.paidAt ?? e.createdAt, from, to));
  const revenue = paidInWindow.reduce((s, e) => s + e.revenue, 0);
  return {
    leads,
    sql,
    consult,
    paidCohort,
    crOverall: leads ? paidCohort / leads : 0,
    c2: leads ? sql / leads : 0,
    payments: paidInWindow.length,
    revenue,
    avgCheck: paidInWindow.length ? revenue / paidInWindow.length : 0,
  };
}

export interface Delta {
  /** Относительное изменение (0.142 = +14.2%), null если сравнивать не с чем */
  rel: number | null;
  /** Абсолютное изменение (для процентных метрик это п.п.) */
  abs: number;
}

export function computeDelta(current: number, previous: number | null | undefined): Delta | null {
  if (previous === null || previous === undefined) return null;
  return { rel: previous === 0 ? (current === 0 ? 0 : null) : (current - previous) / previous, abs: current - previous };
}

export interface FunnelStep {
  id: "reach" | FunnelStage;
  name: string;
  value: number | null;
  stepCr: number | null;
  totalCr: number | null;
  dropped: number | null;
  /** Медиана дней от входа в воронку до шага */
  medianDays: number | null;
}

function median(values: number[]): number | null {
  if (!values.length) return null;
  const s = [...values].sort((a, b) => a - b);
  const m = Math.floor(s.length / 2);
  return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
}

export function computeFunnel(events: LeadEvent[], daily: ChannelDailyStat[], from: number, to: number): FunnelStep[] {
  const cohort = events.filter((e) => inRange(e.createdAt, from, to));
  const fromKey = toLocalIso(new Date(from)).slice(0, 10);
  const toKey = toLocalIso(new Date(to)).slice(0, 10);
  const reach = daily.filter((d) => d.date >= fromKey && d.date <= toKey).reduce((s, d) => s + d.reach, 0);
  const daysTo = (at: (e: LeadEvent) => string | null) =>
    median(cohort.map((e) => (at(e) ? (ts(at(e) as string) - ts(e.createdAt)) / DAY_MS : NaN)).filter((v) => Number.isFinite(v) && v >= 0));

  const raw: Omit<FunnelStep, "stepCr" | "totalCr" | "dropped">[] = [
    { id: "reach", name: "Просмотры / Охват", value: reach > 0 ? reach : null, medianDays: null },
    { id: "lead", name: "Вход в воронку (Лид)", value: cohort.length, medianDays: null },
    { id: "sql", name: "Квалификация (SQL)", value: cohort.filter((e) => reached(e, "sql")).length, medianDays: daysTo((e) => e.sqlAt) },
    { id: "consult", name: "Консультация / Демо / КП", value: cohort.filter((e) => reached(e, "consult")).length, medianDays: daysTo((e) => e.consultAt) },
    { id: "paid", name: "Продажа (Оплата)", value: cohort.filter((e) => e.stage === "paid").length, medianDays: daysTo((e) => e.paidAt) },
  ];
  const entry = cohort.length;
  let prev: number | null = null;
  return raw.map((st) => {
    const v = st.value;
    const out: FunnelStep = {
      ...st,
      stepCr: v !== null && prev ? v / prev : null,
      totalCr: v !== null && st.id !== "reach" && entry ? v / entry : null,
      dropped: v !== null && prev !== null ? Math.max(0, prev - v) : null,
    };
    if (v !== null) prev = v;
    return out;
  });
}

export interface ChannelRow {
  id: ChannelId;
  label: string;
  leads: number;
  reach: number;
  c1: number | null;
  sql: number;
  paid: number;
  crPaid: number;
  revenue: number;
  spend: number;
  romi: number | null;
}

export function computeChannels(
  events: LeadEvent[],
  daily: ChannelDailyStat[],
  channels: ChannelDef[],
  from: number,
  to: number,
): ChannelRow[] {
  const fromKey = toLocalIso(new Date(from)).slice(0, 10);
  const toKey = toLocalIso(new Date(to)).slice(0, 10);
  const rows = new Map<ChannelId, ChannelRow>(
    channels.map((c) => [c.id, { id: c.id, label: c.label, leads: 0, reach: 0, c1: null, sql: 0, paid: 0, crPaid: 0, revenue: 0, spend: 0, romi: null }]),
  );
  for (const e of events) {
    const row = rows.get(e.channel);
    if (!row) continue;
    if (inRange(e.createdAt, from, to)) {
      row.leads++;
      if (reached(e, "sql")) row.sql++;
      if (e.stage === "paid") row.paid++;
    }
    if (e.stage === "paid" && inRange(e.paidAt ?? e.createdAt, from, to)) row.revenue += e.revenue;
  }
  for (const d of daily) {
    const row = rows.get(d.channel);
    if (row && d.date >= fromKey && d.date <= toKey) {
      row.reach += d.reach;
      row.spend += d.spend;
    }
  }
  return [...rows.values()].map((r) => ({
    ...r,
    c1: r.reach > 0 ? r.leads / r.reach : null,
    crPaid: r.leads ? r.paid / r.leads : 0,
    romi: r.spend > 0 ? (r.revenue - r.spend) / r.spend : null,
  }));
}

export interface SeriesPoint {
  key: string;
  label: string;
  leads: number;
  paid: number;
  revenue: number;
}

function weekStart(d: Date): Date {
  const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const wd = (x.getDay() + 6) % 7; // понедельник = 0
  x.setDate(x.getDate() - wd);
  return x;
}

/** Новые лиды (по дате создания) против оплат (по дате оплаты) по дням или неделям. */
export function computeSeries(events: LeadEvent[], from: number, to: number, bucket: "day" | "week"): SeriesPoint[] {
  const points = new Map<string, SeriesPoint>();
  const keyOf = (t: number) => {
    const d = new Date(t);
    return toLocalIso(bucket === "week" ? weekStart(d) : d).slice(0, 10);
  };
  const fmt = new Intl.DateTimeFormat("ru-RU", { day: "numeric", month: "short" });
  for (let t = bucket === "week" ? weekStart(new Date(from)).getTime() : from; t <= to; t += bucket === "week" ? 7 * DAY_MS : DAY_MS) {
    const k = keyOf(t);
    if (!points.has(k)) points.set(k, { key: k, label: fmt.format(new Date(k + "T12:00:00")).replace(".", ""), leads: 0, paid: 0, revenue: 0 });
  }
  for (const e of events) {
    if (inRange(e.createdAt, from, to)) {
      const p = points.get(keyOf(ts(e.createdAt)));
      if (p) p.leads++;
    }
    const paidAt = e.stage === "paid" ? e.paidAt ?? e.createdAt : null;
    if (paidAt && inRange(paidAt, from, to)) {
      const p = points.get(keyOf(ts(paidAt)));
      if (p) {
        p.paid++;
        p.revenue += e.revenue;
      }
    }
  }
  return [...points.values()];
}

// ── 2.4 Хук данных: моки или API, автообновление ────────────────────────────────────

interface DataState {
  data: DashboardData | null;
  loading: boolean;
  error: string | null;
  refresh: () => void;
}

function useDashboardData(opts: {
  apiUrl?: string;
  apiToken?: string;
  period: PeriodKey;
  initialData?: DashboardData;
  refreshIntervalMs: number;
  onAuthError?: () => void;
}): DataState {
  const { apiUrl, apiToken, period, initialData, refreshIntervalMs, onAuthError } = opts;
  const mock = useMemo(() => (apiUrl ? null : initialData ?? generateMockDashboardData()), [apiUrl, initialData]);
  const [data, setData] = useState<DashboardData | null>(mock);
  const [loading, setLoading] = useState<boolean>(Boolean(apiUrl));
  const [error, setError] = useState<string | null>(null);
  const [nonce, setNonce] = useState(0);

  useEffect(() => {
    if (!apiUrl) {
      setData(mock);
      return;
    }
    const ctrl = new AbortController();
    setLoading(true);
    fetchDashboardData(apiUrl, period, apiToken, ctrl.signal)
      .then((d) => {
        setData(d);
        setError(null);
      })
      .catch((e: unknown) => {
        if (ctrl.signal.aborted) return;
        if (e instanceof ApiAuthError) onAuthError?.();
        setError(e instanceof Error ? e.message : "Не удалось загрузить данные");
      })
      .finally(() => {
        if (!ctrl.signal.aborted) setLoading(false);
      });
    return () => ctrl.abort();
  }, [apiUrl, apiToken, period, nonce, mock, onAuthError]);

  useEffect(() => {
    if (!apiUrl || refreshIntervalMs <= 0) return;
    const t = window.setInterval(() => setNonce((n) => n + 1), refreshIntervalMs);
    return () => window.clearInterval(t);
  }, [apiUrl, refreshIntervalMs]);

  const refresh = useCallback(() => setNonce((n) => n + 1), []);
  return { data, loading, error, refresh };
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 3. PRESENTATION
 * ═════════════════════════════════════════════════════════════════════════ */

const COLORS = {
  indigo: "#6366f1",
  blue: "#3b82f6",
  emerald: "#10b981",
  amber: "#f59e0b",
  red: "#ef4444",
};

const CHANNEL_STYLE: Record<string, { icon: LucideIcon; color: string }> = {
  reels: { icon: Clapperboard, color: "#6366f1" },
  telegram: { icon: Send, color: "#3b82f6" },
  base: { icon: Mail, color: "#8b5cf6" },
  partners: { icon: Handshake, color: "#14b8a6" },
  other: { icon: Layers, color: "#a1a1aa" },
};
const channelStyle = (id: string) => CHANNEL_STYLE[id] ?? CHANNEL_STYLE.other;

const PERIODS: { key: PeriodKey; label: string }[] = [
  { key: "7d", label: "7 дней" },
  { key: "30d", label: "30 дней" },
  { key: "quarter", label: "Квартал" },
  { key: "all", label: "Все время" },
];

// ── Форматирование ─────────────────────────────────────────────────────────────────

const nf = new Intl.NumberFormat("ru-RU");
const fmtInt = (n: number) => nf.format(Math.round(n));
const fmtPct = (v: number | null, digits = 1) =>
  v === null || !Number.isFinite(v) ? "—" : `${(v * 100).toLocaleString("ru-RU", { minimumFractionDigits: digits, maximumFractionDigits: digits })}%`;
const fmtMoney = (v: number, currency: CurrencyCode, compact = false) =>
  new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency,
    maximumFractionDigits: compact ? 1 : 0,
    notation: compact ? "compact" : "standard",
  }).format(v);
/** ROMI: до 1000% в процентах, дальше множителем "×12,4", чтобы не раздувать колонку. */
const fmtRomi = (v: number) =>
  Math.abs(v) >= 10 ? `×${(v + 1).toLocaleString("ru-RU", { maximumFractionDigits: 1 })}` : fmtPct(v, 0);
const fmtDays = (d: number | null) =>
  d === null ? null : d < 1 ? `${Math.max(1, Math.round(d * 24))} ч` : `${d.toLocaleString("ru-RU", { maximumFractionDigits: 1 })} дн`;

function plural(n: number, one: string, few: string, many: string) {
  const m10 = n % 10;
  const m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
  return many;
}

function relativeTime(iso: string | null, nowMs: number): string {
  if (!iso) return "нет данных";
  const min = Math.max(0, Math.round((nowMs - ts(iso)) / 60_000));
  if (min < 1) return "только что";
  if (min < 60) return `${min} ${plural(min, "минуту", "минуты", "минут")} назад`;
  const h = Math.round(min / 60);
  if (h < 48) return `${h} ${plural(h, "час", "часа", "часов")} назад`;
  const d = Math.round(h / 24);
  return `${d} ${plural(d, "день", "дня", "дней")} назад`;
}

const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(" ");

// ── Примитивы в духе shadcn/ui ─────────────────────────────────────────────────────

function Card({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <section
      className={cx(
        "rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60",
        className,
      )}
    >
      {children}
    </section>
  );
}

function CardHeader({ title, description, action, icon: Icon }: { title: string; description?: string; action?: ReactNode; icon?: LucideIcon }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-5">
      <div className="min-w-0">
        <h2 className="flex items-center gap-2 text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">
          {Icon && <Icon className="size-4 text-zinc-400" aria-hidden />}
          {title}
        </h2>
        {description && <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{description}</p>}
      </div>
      {action}
    </div>
  );
}

function CardContent({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cx("px-5 pb-5 pt-4", className)}>{children}</div>;
}

type Tone = "positive" | "negative" | "neutral" | "warning" | "info";
const TONE: Record<Tone, string> = {
  positive: "bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/20",
  negative: "bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/20",
  warning: "bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/20",
  info: "bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/20",
  neutral: "bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-400/20",
};

function Badge({ tone = "neutral", children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return (
    <span className={cx("inline-flex items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums ring-1 ring-inset", TONE[tone], className)}>
      {children}
    </span>
  );
}

/** Бейдж дельты: относительная (%) или абсолютная в п.п. для процентных метрик. */
function DeltaBadge({ delta, mode = "rel", invert = false }: { delta: Delta | null; mode?: "rel" | "pp"; invert?: boolean }) {
  if (!delta) return null;
  const value = mode === "pp" ? delta.abs * 100 : delta.rel;
  if (value === null) return <Badge tone="info">новое</Badge>;
  const flat = Math.abs(value) < (mode === "pp" ? 0.05 : 0.0005);
  const good = invert ? value < 0 : value > 0;
  const Icon = flat ? Minus : value > 0 ? TrendingUp : TrendingDown;
  const text =
    mode === "pp"
      ? `${value > 0 ? "+" : ""}${value.toLocaleString("ru-RU", { maximumFractionDigits: 1 })} п.п.`
      : `${value > 0 ? "+" : ""}${(value * 100).toLocaleString("ru-RU", { maximumFractionDigits: 1 })}%`;
  return (
    <Badge tone={flat ? "neutral" : good ? "positive" : "negative"}>
      <Icon className="size-3" aria-hidden />
      {text}
    </Badge>
  );
}

function Segmented<T extends string>({ value, options, onChange, label }: { value: T; options: { key: T; label: string }[]; onChange: (v: T) => void; label: string }) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-lg border border-zinc-200 bg-zinc-100/70 p-0.5 dark:border-zinc-800 dark:bg-zinc-900">
      {options.map((o) => (
        <button
          key={o.key}
          type="button"
          role="radio"
          aria-checked={value === o.key}
          onClick={() => onChange(o.key)}
          className={cx(
            "rounded-md px-3 py-1.5 text-xs font-medium transition-all duration-150",
            value === o.key
              ? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-100"
              : "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200",
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

function IconButton({ label, onClick, children, spinning }: { label: string; onClick: () => void; children: ReactNode; spinning?: boolean }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      title={label}
      className="inline-flex size-8 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-500 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
    >
      <span className={cx("inline-flex", spinning && "animate-spin")}>{children}</span>
    </button>
  );
}

function Skeleton({ className }: { className?: string }) {
  return <div className={cx("animate-pulse rounded-md bg-zinc-200/70 dark:bg-zinc-800", className)} />;
}

// ── Header ─────────────────────────────────────────────────────────────────────────

function DataStatus({ meta, nowMs }: { meta: DashboardData["meta"]; nowMs: number }) {
  if (meta.source === "mock") {
    return (
      <Badge tone="info">
        <span className="size-1.5 rounded-full bg-indigo-500" aria-hidden />
        Демо-данные
      </Badge>
    );
  }
  const ageMin = meta.lastSyncAt ? (nowMs - ts(meta.lastSyncAt)) / 60_000 : Infinity;
  const failed = meta.lastSyncStatus === "failed";
  const live = ageMin <= 150 && !failed; // синхронизация раз в 2 часа + запас
  return (
    <span className="inline-flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
      <span className="relative flex size-2">
        {live && <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60" />}
        <span className={cx("relative inline-flex size-2 rounded-full", live ? "bg-emerald-500" : failed ? "bg-red-500" : "bg-amber-500")} />
      </span>
      <span className={cx("font-medium", live ? "text-emerald-600 dark:text-emerald-400" : failed ? "text-red-600 dark:text-red-400" : "text-amber-600 dark:text-amber-400")}>
        {live ? "Live" : failed ? "Ошибка синхронизации" : "Данные устарели"}
      </span>
      <span aria-hidden>·</span>
      <span>обновлено {relativeTime(meta.lastSyncAt, nowMs)}</span>
    </span>
  );
}

// ── KPI ────────────────────────────────────────────────────────────────────────────

function KpiCard({ icon: Icon, label, value, delta, hint, accent }: { icon: LucideIcon; label: string; value: string; delta: ReactNode; hint: ReactNode; accent: string }) {
  return (
    <Card className="group relative overflow-hidden transition-shadow hover:shadow-md">
      <div className="absolute inset-x-0 top-0 h-0.5 opacity-80" style={{ background: accent }} aria-hidden />
      <div className="p-5">
        <div className="flex items-center justify-between gap-2">
          <p className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{label}</p>
          <span className="inline-flex size-7 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 transition-colors group-hover:text-zinc-900 dark:bg-zinc-800 dark:text-zinc-400 dark:group-hover:text-zinc-100">
            <Icon className="size-3.5" aria-hidden />
          </span>
        </div>
        <p className="mt-3 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-50">{value}</p>
        <div className="mt-2 flex min-h-5 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
          {delta}
          {hint}
        </div>
      </div>
    </Card>
  );
}

// ── Воронка ────────────────────────────────────────────────────────────────────────

const FUNNEL_COLORS = ["#a5b4fc", COLORS.indigo, "#4f46e5", "#3b82f6", COLORS.emerald];

interface FunnelTooltipProps {
  active?: boolean;
  payload?: ReadonlyArray<{ payload?: FunnelStep & { fill: string } }>;
}

function FunnelTooltip({ active, payload }: FunnelTooltipProps) {
  const p = active ? payload?.[0]?.payload : undefined;
  if (!p || p.value === null) return null;
  return (
    <TooltipShell title={p.name}>
      <TooltipRow color={p.fill} label="Контактов" value={fmtInt(p.value)} />
      {p.stepCr !== null && <TooltipRow label="Step CR" value={fmtPct(p.stepCr)} />}
      {p.totalCr !== null && <TooltipRow label="Total CR" value={fmtPct(p.totalCr)} />}
    </TooltipShell>
  );
}

function FunnelSection({ steps, hasPrev }: { steps: FunnelStep[]; hasPrev: boolean }) {
  const visible = steps.filter((s) => s.id !== "reach" && s.value !== null);
  const top = visible[0]?.value ?? 0;
  // Ширина сегмента не меньше 14% верхнего, иначе маленькие шаги превращаются в линию.
  // Подписи и тултип берут реальное value.
  const chartSteps = visible.map((s, i) => ({ ...s, shape: Math.max(s.value ?? 0, top * 0.14), fill: FUNNEL_COLORS[i + 1] }));
  const maxDrop = Math.max(0, ...steps.filter((s) => s.id !== "lead").map((s) => s.dropped ?? 0));
  const reach = steps.find((s) => s.id === "reach");

  return (
    <Card>
      <CardHeader
        icon={Filter}
        title="Воронка по этапам"
        description="Когорта лидов, пришедших в выбранный период. Шаг засчитан, если лид до него дошёл, даже если позже ушёл в брак."
        action={!hasPrev ? undefined : <Badge tone="neutral">Step CR · Total CR · потери</Badge>}
      />
      <CardContent className="grid gap-6 lg:grid-cols-5">
        <div className="h-64 min-w-0 lg:col-span-2 lg:h-auto lg:min-h-64">
          {chartSteps.length && (chartSteps[0].value ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <FunnelChart margin={{ top: 4, right: 8, bottom: 4, left: 8 }}>
                <Tooltip content={(p) => <FunnelTooltip {...(p as unknown as FunnelTooltipProps)} />} cursor={false} />
                <Funnel dataKey="shape" nameKey="name" data={chartSteps} isAnimationActive lastShapeType="rectangle" stroke="none">
                  {chartSteps.map((s) => (
                    <Cell key={s.id} fill={s.fill} />
                  ))}
                  <LabelList
                    dataKey="value"
                    position="center"
                    fill="#ffffff"
                    stroke="none"
                    className="text-sm font-semibold tabular-nums"
                    formatter={(v: unknown) => fmtInt(Number(v))}
                  />
                </Funnel>
              </FunnelChart>
            </ResponsiveContainer>
          ) : (
            <EmptyState text="В этом периоде лидов нет" />
          )}
        </div>

        <ol className="min-w-0 space-y-1.5 lg:col-span-3">
          {steps.map((s, i) => {
            const isReach = s.id === "reach";
            const dropShare = s.dropped !== null && s.value !== null && s.dropped + s.value > 0 ? s.dropped / (s.dropped + s.value) : null;
            const width = isReach ? 100 : s.totalCr !== null ? Math.max(2, s.totalCr * 100) : 0;
            return (
              <li key={s.id} className="rounded-lg border border-transparent px-3 py-2.5 transition-colors hover:border-zinc-200 hover:bg-zinc-50 dark:hover:border-zinc-800 dark:hover:bg-zinc-800/40">
                <div className="flex items-center gap-3">
                  <span
                    className="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white"
                    style={{ background: FUNNEL_COLORS[i] }}
                  >
                    {i + 1}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-3">
                      <p className="truncate text-sm font-medium text-zinc-800 dark:text-zinc-200">{s.name}</p>
                      <p className="text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{s.value === null ? "нет данных" : fmtInt(s.value)}</p>
                    </div>
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                      <div className="h-full rounded-full transition-all duration-500" style={{ width: `${width}%`, background: FUNNEL_COLORS[i] }} />
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                      {s.stepCr !== null && (
                        <Badge tone="info">
                          Step CR <span className="font-semibold">{fmtPct(s.stepCr, s.stepCr < 0.01 ? 2 : 1)}</span>
                        </Badge>
                      )}
                      {s.totalCr !== null && s.id !== "lead" && (
                        <Badge tone={s.id === "paid" ? "positive" : "neutral"}>
                          Total CR <span className="font-semibold">{fmtPct(s.totalCr)}</span>
                        </Badge>
                      )}
                      {s.dropped !== null && s.dropped > 0 && !isReach && (
                        <Badge tone={s.id !== "lead" && s.dropped === maxDrop ? "negative" : "warning"}>
                          <ArrowDown className="size-3" aria-hidden />−{fmtInt(s.dropped)}
                          {dropShare !== null && s.id !== "lead" && <span className="opacity-75">({fmtPct(dropShare, 0)})</span>}
                        </Badge>
                      )}
                      {s.medianDays !== null && <span className="ml-auto whitespace-nowrap">медиана {fmtDays(s.medianDays)} от входа</span>}
                      {isReach && (
                        <span>{reach?.value === null ? "охват не подключён: задайте reach в конфиге каналов" : "охват из рекламных кабинетов / конфига"}</span>
                      )}
                    </div>
                  </div>
                </div>
              </li>
            );
          })}
        </ol>
      </CardContent>
    </Card>
  );
}

// ── Каналы ─────────────────────────────────────────────────────────────────────────

type SortKey = "label" | "leads" | "c1" | "revenue" | "romi";

function ChannelsTable({ rows, currency, selected }: { rows: ChannelRow[]; currency: CurrencyCode; selected: ChannelId | "all" }) {
  const [sort, setSort] = useState<{ key: SortKey; dir: 1 | -1 }>({ key: "leads", dir: -1 });
  const sorted = useMemo(() => {
    const val = (r: ChannelRow): number | string => (sort.key === "label" ? r.label : r[sort.key] ?? -Infinity);
    return [...rows].sort((a, b) => {
      const va = val(a);
      const vb = val(b);
      return (typeof va === "string" ? va.localeCompare(String(vb), "ru") : va - (vb as number)) * sort.dir;
    });
  }, [rows, sort]);
  const maxLeads = Math.max(1, ...rows.map((r) => r.leads));
  const total = rows.reduce(
    (a, r) => ({ leads: a.leads + r.leads, reach: a.reach + r.reach, revenue: a.revenue + r.revenue, spend: a.spend + r.spend }),
    { leads: 0, reach: 0, revenue: 0, spend: 0 },
  );

  const Th = ({ k, children, align = "right" }: { k: SortKey; children: ReactNode; align?: "left" | "right" }) => {
    const active = sort.key === k;
    const Icon = !active ? ArrowUpDown : sort.dir === 1 ? ArrowUp : ArrowDown;
    return (
      <th scope="col" className={cx("whitespace-nowrap px-2.5 py-2 font-medium", align === "right" ? "text-right" : "text-left")} aria-sort={active ? (sort.dir === 1 ? "ascending" : "descending") : "none"}>
        <button
          type="button"
          onClick={() => setSort((s) => ({ key: k, dir: s.key === k ? ((s.dir * -1) as 1 | -1) : -1 }))}
          className={cx("inline-flex items-center gap-1 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100", active && "text-zinc-900 dark:text-zinc-100")}
        >
          {children}
          <Icon className={cx("size-3", !active && "opacity-40")} aria-hidden />
        </button>
      </th>
    );
  };

  return (
    <Card className="flex min-w-0 flex-col lg:col-span-3">
      <CardHeader icon={Layers} title="Каналы и конверсии" description="C1: из охвата в лид. ROMI: (выручка − расходы) / расходы за период." />
      <CardContent className="overflow-x-auto px-2 pb-3">
        <table className="w-full min-w-[540px] text-sm">
          <thead className="text-xs text-zinc-500 dark:text-zinc-400">
            <tr className="border-b border-zinc-200 dark:border-zinc-800">
              <Th k="label" align="left">Источник</Th>
              <Th k="leads">Лиды</Th>
              <Th k="c1">C1 %</Th>
              <Th k="revenue">Выручка</Th>
              <Th k="romi">ROMI</Th>
            </tr>
          </thead>
          <tbody>
            {sorted.map((r) => {
              const st = channelStyle(r.id);
              const Icon = st.icon;
              return (
                <tr
                  key={r.id}
                  aria-selected={selected === r.id}
                  className={cx(
                    "border-b border-zinc-100 transition-colors last:border-0 hover:bg-zinc-50 dark:border-zinc-800/70 dark:hover:bg-zinc-800/40",
                    selected === r.id && "bg-indigo-50/60 dark:bg-indigo-500/10",
                    selected !== "all" && selected !== r.id && "opacity-50",
                  )}
                >
                  <td className="px-2.5 py-3">
                    <div className="flex items-center gap-2.5">
                      <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-lg" style={{ background: `${st.color}1a`, color: st.color }}>
                        <Icon className="size-3.5" aria-hidden />
                      </span>
                      <div className="min-w-0">
                        <p className="truncate font-medium text-zinc-800 dark:text-zinc-200">{r.label}</p>
                        <p className="text-xs text-zinc-500 dark:text-zinc-400">
                          {fmtInt(r.paid)} {plural(r.paid, "оплата", "оплаты", "оплат")} · CR {fmtPct(r.crPaid)}
                        </p>
                      </div>
                    </div>
                  </td>
                  <td className="px-2.5 py-3 text-right">
                    <p className="font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{fmtInt(r.leads)}</p>
                    <div className="ml-auto mt-1 h-1 w-16 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                      <div className="h-full rounded-full" style={{ width: `${(r.leads / maxLeads) * 100}%`, background: st.color }} />
                    </div>
                  </td>
                  <td className="px-2.5 py-3 text-right tabular-nums text-zinc-700 dark:text-zinc-300">{fmtPct(r.c1, 2)}</td>
                  <td className="px-2.5 py-3 text-right font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{fmtMoney(r.revenue, currency)}</td>
                  <td className="px-2.5 py-3 text-right">
                    {r.romi === null ? (
                      <span className="text-zinc-400">—</span>
                    ) : (
                      <Badge tone={r.romi >= 1 ? "positive" : r.romi >= 0 ? "warning" : "negative"}>{fmtRomi(r.romi)}</Badge>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
          <tfoot>
            <tr className="border-t border-zinc-200 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:text-zinc-300">
              <td className="px-2.5 py-2.5">Итого</td>
              <td className="px-2.5 py-2.5 text-right tabular-nums">{fmtInt(total.leads)}</td>
              <td className="px-2.5 py-2.5 text-right tabular-nums">{fmtPct(total.reach ? total.leads / total.reach : null, 2)}</td>
              <td className="px-2.5 py-2.5 text-right tabular-nums">{fmtMoney(total.revenue, currency)}</td>
              <td className="px-2.5 py-2.5 text-right tabular-nums">{total.spend ? fmtRomi((total.revenue - total.spend) / total.spend) : "—"}</td>
            </tr>
          </tfoot>
        </table>
      </CardContent>
    </Card>
  );
}

// ── Динамика ───────────────────────────────────────────────────────────────────────

function TooltipShell({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="min-w-44 rounded-lg border border-zinc-200 bg-white/95 px-3 py-2.5 text-xs shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
      <p className="mb-1.5 font-medium text-zinc-900 dark:text-zinc-100">{title}</p>
      <div className="space-y-1">{children}</div>
    </div>
  );
}

function TooltipRow({ color, label, value }: { color?: string; label: string; value: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="inline-flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400">
        {color && <span className="size-2 rounded-full" style={{ background: color }} aria-hidden />}
        {label}
      </span>
      <span className="font-medium tabular-nums text-zinc-900 dark:text-zinc-100">{value}</span>
    </div>
  );
}

interface AreaTooltipProps {
  active?: boolean;
  payload?: ReadonlyArray<{ payload?: SeriesPoint }>;
  currency: CurrencyCode;
  bucket: "day" | "week";
}

function AreaTooltip({ active, payload, currency, bucket }: AreaTooltipProps) {
  const p = active ? payload?.[0]?.payload : undefined;
  if (!p) return null;
  const cr = p.leads ? p.paid / p.leads : null;
  return (
    <TooltipShell title={bucket === "week" ? `Неделя с ${p.label}` : p.label}>
      <TooltipRow color={COLORS.indigo} label="Новые лиды" value={fmtInt(p.leads)} />
      <TooltipRow color={COLORS.emerald} label="Оплаты" value={fmtInt(p.paid)} />
      <TooltipRow label="Выручка" value={fmtMoney(p.revenue, currency)} />
      {cr !== null && (
        <div className="pt-1">
          <Badge tone="info">оплат на лид {fmtPct(cr)}</Badge>
        </div>
      )}
    </TooltipShell>
  );
}

function TrendChart({ series, currency, bucket, dark }: { series: SeriesPoint[]; currency: CurrencyCode; bucket: "day" | "week"; dark: boolean }) {
  const grid = dark ? "#27272a" : "#f4f4f5";
  const axis = dark ? "#71717a" : "#a1a1aa";
  return (
    <Card className="flex min-w-0 flex-col lg:col-span-2">
      <CardHeader
        icon={TrendingUp}
        title="Динамика: лиды и оплаты"
        description={bucket === "week" ? "По неделям. Лиды по дате входа, оплаты по дате оплаты." : "По дням. Лиды по дате входа, оплаты по дате оплаты."}
        action={
          <div className="flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
            <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full" style={{ background: COLORS.indigo }} />Лиды</span>
            <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full" style={{ background: COLORS.emerald }} />Оплаты</span>
          </div>
        }
      />
      <CardContent className="h-72 min-h-72 pl-1 lg:h-auto lg:flex-1">
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={series} margin={{ top: 8, right: 12, left: -12, bottom: 0 }}>
            <defs>
              <linearGradient id="gLeads" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={COLORS.indigo} stopOpacity={0.32} />
                <stop offset="95%" stopColor={COLORS.indigo} stopOpacity={0} />
              </linearGradient>
              <linearGradient id="gPaid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={COLORS.emerald} stopOpacity={0.35} />
                <stop offset="95%" stopColor={COLORS.emerald} stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid stroke={grid} vertical={false} />
            <XAxis dataKey="label" tick={{ fill: axis, fontSize: 11 }} tickLine={false} axisLine={false} minTickGap={24} />
            <YAxis tick={{ fill: axis, fontSize: 11 }} tickLine={false} axisLine={false} allowDecimals={false} width={44} />
            <Tooltip
              content={(p) => <AreaTooltip {...(p as unknown as AreaTooltipProps)} currency={currency} bucket={bucket} />}
              cursor={{ stroke: axis, strokeDasharray: "3 3" }}
            />
            <Area type="monotone" dataKey="leads" name="Лиды" stroke={COLORS.indigo} strokeWidth={2} fill="url(#gLeads)" activeDot={{ r: 4, strokeWidth: 0 }} />
            <Area type="monotone" dataKey="paid" name="Оплаты" stroke={COLORS.emerald} strokeWidth={2} fill="url(#gPaid)" activeDot={{ r: 4, strokeWidth: 0 }} />
          </AreaChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

function EmptyState({ text }: { text: string }) {
  return <div className="flex h-full items-center justify-center text-sm text-zinc-400">{text}</div>;
}

// ── Тема ───────────────────────────────────────────────────────────────────────────

function useTheme(): [boolean, () => void] {
  const [dark, setDark] = useState<boolean>(() => {
    if (typeof window === "undefined") return false;
    try {
      const saved = window.localStorage.getItem("mfd-theme");
      if (saved) return saved === "dark";
    } catch {
      /* localStorage недоступен */
    }
    return window.matchMedia?.("(prefers-color-scheme: dark)").matches ?? false;
  });
  useEffect(() => {
    document.documentElement.classList.toggle("dark", dark);
    document.documentElement.style.colorScheme = dark ? "dark" : "light";
    try {
      window.localStorage.setItem("mfd-theme", dark ? "dark" : "light");
    } catch {
      /* ignore */
    }
  }, [dark]);
  return [dark, () => setDark((d) => !d)];
}

// ── Компонент ──────────────────────────────────────────────────────────────────────

export interface MarketingFunnelDashboardProps {
  /** URL эндпоинта с DashboardData (например, "api/events.php"). Без него работают моки. */
  apiUrl?: string;
  /** Токен API, уходит в заголовке Authorization: Bearer */
  apiToken?: string;
  /** Свои данные вместо генератора моков (только без apiUrl) */
  initialData?: DashboardData;
  /** Автообновление, мс (0 = выкл). По умолчанию 5 минут. */
  refreshIntervalMs?: number;
  /** Вызывается при 401 от API */
  onAuthError?: () => void;
  title?: string;
}

export default function MarketingFunnelDashboard({
  apiUrl,
  apiToken,
  initialData,
  refreshIntervalMs = 5 * 60_000,
  onAuthError,
  title = "Воронка продаж и каналы",
}: MarketingFunnelDashboardProps) {
  const [period, setPeriod] = useState<PeriodKey>("30d");
  const [channel, setChannel] = useState<ChannelId | "all">("all");
  const [dark, toggleTheme] = useTheme();
  const [nowMs, setNowMs] = useState(() => Date.now());
  const { data, loading, error, refresh } = useDashboardData({ apiUrl, apiToken, period, initialData, refreshIntervalMs, onAuthError });

  useEffect(() => {
    const t = window.setInterval(() => setNowMs(Date.now()), 30_000);
    return () => window.clearInterval(t);
  }, []);

  const view = useMemo(() => {
    if (!data) return null;
    const events = channel === "all" ? data.rawEvents : data.rawEvents.filter((e) => e.channel === channel);
    const daily = channel === "all" ? data.channelDaily : data.channelDaily.filter((d) => d.channel === channel);
    const range = resolvePeriod(period, data.meta.generatedAt, data.rawEvents);
    const kpi = computeKpis(events, range.from, range.to);
    const prev = range.prevFrom !== null && range.prevTo !== null ? computeKpis(events, range.prevFrom, range.prevTo) : null;
    return {
      range,
      kpi,
      prev,
      deltas: {
        leads: computeDelta(kpi.leads, prev?.leads),
        cr: computeDelta(kpi.crOverall, prev?.crOverall),
        sql: computeDelta(kpi.sql, prev?.sql),
        revenue: computeDelta(kpi.revenue, prev?.revenue),
      },
      funnel: computeFunnel(events, daily, range.from, range.to),
      channels: computeChannels(data.rawEvents, data.channelDaily, data.channels, range.from, range.to),
      series: computeSeries(events, range.from, range.to, range.bucket),
    };
  }, [data, period, channel]);

  const channelOptions = data?.channels ?? [];
  const currency = data?.meta.currency ?? "RUB";
  const vsLabel = period === "all" ? null : "vs прошлый период";

  return (
    <div className="min-h-screen bg-zinc-50 text-zinc-900 antialiased transition-colors dark:bg-zinc-950 dark:text-zinc-100">
      <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        {/* Header & Controls */}
        <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div className="min-w-0">
            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
            <div className="mt-1.5">{data ? <DataStatus meta={data.meta} nowMs={nowMs} /> : <Skeleton className="h-4 w-48" />}</div>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Segmented label="Период" value={period} options={PERIODS} onChange={setPeriod} />
            <label className="relative">
              <span className="sr-only">Канал</span>
              <select
                value={channel}
                onChange={(e) => setChannel(e.target.value)}
                className="h-8 appearance-none rounded-lg border border-zinc-200 bg-white pl-3 pr-8 text-xs font-medium text-zinc-700 shadow-sm outline-none transition-colors hover:bg-zinc-50 focus:ring-2 focus:ring-indigo-500/30 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
              >
                <option value="all">Все каналы</option>
                {channelOptions.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.label}
                  </option>
                ))}
              </select>
              <ArrowUpDown className="pointer-events-none absolute right-2.5 top-1/2 size-3 -translate-y-1/2 text-zinc-400" aria-hidden />
            </label>
            {apiUrl && (
              <IconButton label="Обновить" onClick={refresh} spinning={loading}>
                <RefreshCw className="size-3.5" />
              </IconButton>
            )}
            <IconButton label={dark ? "Светлая тема" : "Тёмная тема"} onClick={toggleTheme}>
              {dark ? <Sun className="size-3.5" /> : <Moon className="size-3.5" />}
            </IconButton>
          </div>
        </header>

        {error && (
          <div role="alert" className="mt-5 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300">
            <AlertTriangle className="size-4 shrink-0" aria-hidden />
            <span className="flex-1">{error}{data ? ". Показаны последние загруженные данные." : ""}</span>
            <button type="button" onClick={refresh} className="rounded-md px-2 py-1 text-xs font-medium hover:bg-red-100 dark:hover:bg-red-900/40">
              Повторить
            </button>
          </div>
        )}

        {!view ? (
          <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: 4 }, (_, i) => (
              <Card key={i} className="p-5">
                <Skeleton className="h-3 w-24" />
                <Skeleton className="mt-4 h-7 w-32" />
                <Skeleton className="mt-3 h-4 w-40" />
              </Card>
            ))}
            <Card className="h-80 sm:col-span-2 lg:col-span-4 p-5"><Skeleton className="h-full w-full" /></Card>
          </div>
        ) : (
          <main className={cx("mt-6 space-y-4 transition-opacity", loading && "opacity-60")}>
            {/* KPI */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <KpiCard
                icon={Users}
                accent={COLORS.indigo}
                label="Всего лидов · C1"
                value={fmtInt(view.kpi.leads)}
                delta={<DeltaBadge delta={view.deltas.leads} />}
                hint={vsLabel ?? "за всё время"}
              />
              <KpiCard
                icon={Target}
                accent={COLORS.emerald}
                label="Сквозная конверсия"
                value={fmtPct(view.kpi.crOverall)}
                delta={<DeltaBadge delta={view.deltas.cr} mode="pp" />}
                hint={<span>{fmtInt(view.kpi.paidCohort)} из {fmtInt(view.kpi.leads)} дошли до оплаты</span>}
              />
              <KpiCard
                icon={UserCheck}
                accent={COLORS.blue}
                label="Квалифицированные (SQL)"
                value={fmtInt(view.kpi.sql)}
                delta={<DeltaBadge delta={view.deltas.sql} />}
                hint={<span>C2 {fmtPct(view.kpi.c2)} из лида</span>}
              />
              <KpiCard
                icon={Banknote}
                accent={COLORS.emerald}
                label="Выручка / Оплаты"
                value={fmtMoney(view.kpi.revenue, currency)}
                delta={<DeltaBadge delta={view.deltas.revenue} />}
                hint={
                  <span>
                    {fmtInt(view.kpi.payments)} {plural(view.kpi.payments, "оплата", "оплаты", "оплат")} · ср. чек {fmtMoney(view.kpi.avgCheck, currency)}
                  </span>
                }
              />
            </div>

            <FunnelSection steps={view.funnel} hasPrev={view.prev !== null} />

            <div className="grid gap-4 lg:grid-cols-5">
              <ChannelsTable rows={view.channels} currency={currency} selected={channel} />
              <TrendChart series={view.series} currency={currency} bucket={view.range.bucket} dark={dark} />
            </div>

            <footer className="flex flex-wrap items-center justify-between gap-2 pt-2 text-xs text-zinc-400 dark:text-zinc-500">
              <span>
                Источник: {data?.meta.source === "api" ? "Bitrix24 CRM (снапшоты каждые 2 часа)" : "демо-данные, подключите apiUrl"} · {fmtInt(data?.rawEvents.length ?? 0)} событий
              </span>
              <span>Таблица каналов показывает все каналы, выбранный в фильтре подсвечен</span>
            </footer>
          </main>
        )}
      </div>
    </div>
  );
}
