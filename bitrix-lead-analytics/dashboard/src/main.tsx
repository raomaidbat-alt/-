import { StrictMode, useCallback, useState, type FormEvent } from "react";
import { createRoot } from "react-dom/client";
import { KeyRound } from "lucide-react";
import MarketingFunnelDashboard from "./MarketingFunnelDashboard";
import "./index.css";

/**
 * Точка входа сборки для public/:
 *   ?demo=1       моковые данные, API не нужен
 *   иначе         живые данные из api/events.php, токен спрашивается один раз и хранится в localStorage
 */
const API_URL = import.meta.env.VITE_API_URL ?? "api/events.php";
const TOKEN_KEY = "mfd-api-token";

function readToken(): string {
  try {
    return localStorage.getItem(TOKEN_KEY) ?? "";
  } catch {
    return "";
  }
}

function App() {
  const params = new URLSearchParams(window.location.search);
  const [demo, setDemo] = useState(params.get("demo") === "1");
  const [token, setToken] = useState(readToken);
  const [authError, setAuthError] = useState(false);

  const onAuthError = useCallback(() => {
    try {
      localStorage.removeItem(TOKEN_KEY);
    } catch {
      /* ignore */
    }
    setAuthError(true);
    setToken("");
  }, []);

  if (demo) return <MarketingFunnelDashboard />;
  if (!token) {
    return (
      <TokenGate
        error={authError}
        onSubmit={(t) => {
          try {
            localStorage.setItem(TOKEN_KEY, t);
          } catch {
            /* ignore */
          }
          setAuthError(false);
          setToken(t);
        }}
        onDemo={() => setDemo(true)}
      />
    );
  }
  return <MarketingFunnelDashboard apiUrl={API_URL} apiToken={token} onAuthError={onAuthError} />;
}

function TokenGate({ error, onSubmit, onDemo }: { error: boolean; onSubmit: (t: string) => void; onDemo: () => void }) {
  const [value, setValue] = useState("");
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (value.trim()) onSubmit(value.trim());
  };
  return (
    <div className="flex min-h-screen items-center justify-center bg-zinc-50 px-4 dark:bg-zinc-950">
      <form onSubmit={submit} className="w-full max-w-sm rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <span className="inline-flex size-9 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">
          <KeyRound className="size-4" aria-hidden />
        </span>
        <h1 className="mt-4 text-base font-semibold text-zinc-900 dark:text-zinc-100">Доступ к дашборду</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Введите токен API из config.php (api.token).</p>
        <input
          type="password"
          autoFocus
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder="Токен"
          className="mt-4 h-9 w-full rounded-lg border border-zinc-200 bg-white px-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
        />
        {error && <p className="mt-2 text-xs text-red-600 dark:text-red-400">Токен не подошёл, попробуйте ещё раз.</p>}
        <button type="submit" className="mt-4 h-9 w-full rounded-lg bg-zinc-900 text-sm font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white">
          Открыть
        </button>
        <button type="button" onClick={onDemo} className="mt-2 h-9 w-full rounded-lg text-sm font-medium text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100">
          Посмотреть демо
        </button>
      </form>
    </div>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
