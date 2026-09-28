import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// Сборка кладётся в ../public рядом с public/api, чтобы PHP отдавал и дашборд, и API с одного адреса.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  base: "./",
  build: {
    outDir: "../public",
    emptyOutDir: false,
    chunkSizeWarningLimit: 900,
  },
  server: {
    proxy: {
      // npm run dev: запросы к API уходят на локальный PHP (php -S 127.0.0.1:8098 -t public)
      "/api": process.env.API_PROXY ?? "http://127.0.0.1:8098",
    },
  },
});
