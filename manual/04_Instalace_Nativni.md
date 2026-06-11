# 4. Instalace — Nativní (PHP + MariaDB + web server)

Tradiční hosting bez Dockeru (cca 5 minut). Předpoklady:

- **PHP 8.5+** s extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`,
  `iconv`, `gd`
- **MariaDB 10.6+** (doporučeno 11.x)
- **Composer 2.x**, **Node.js 22+** (24 doporučeno), **pnpm 10+**
- **Redis** (volitelné — fallback na MariaDB MEMORY)
- Web server: **IIS** nebo **Apache** (oba podporované, repo má `web.config`
  i `.htaccess`)

## 4.1 Klon a konfigurace

```bash
git clone https://github.com/radekhulan/myinvoice.git myinvoice
cd myinvoice
cp cfg.sample.php cfg.php
```

Otevři `cfg.php` a vyplň:

- `db.user` / `db.pass` — připojení k MariaDB
- `app.pepper` — vygeneruj `openssl rand -base64 32`
- `smtp.host` / `user` / `pass` — odchozí pošta
- `captcha.site_key` / `secret_key` — z dash.cloudflare.com → Turnstile
- `ip_allowlist.allow` — volitelné, doporučeno v produkci

## 4.2 Vytvoř databázi

```bash
mysql -u root -p -e "CREATE DATABASE myinvoice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## 4.3 Backend + migrace

```bash
cd api && composer install && cd ..
php api/bin/migrate.php
php tools/generateManualHtml.php   # vyrenderuje manual/generated/ → /manual route
php tools/exportManualToPdf.php    # vygeneruje manual/manual.pdf (Stáhnout PDF v sidebaru)
```

`generateManualHtml.php` je self-contained (nepotřebuje composer/vendor),
generuje HTML kapitoly + search index. `exportManualToPdf.php` vyžaduje
`api/vendor/` (mPDF). Spouštět obojí znovu po každém pull repa, aby `/manual`
ukazoval aktuální obsah. (V Docker variantě se volá build-time uvnitř
`Dockerfile` — viz [Instalace — Docker](03_Instalace_Docker.md).)

## 4.4 Frontend build

```bash
cd web
pnpm install
pnpm build       # produkční build do web/dist/
```

## 4.5 Web server

- **IIS** — `web.config` v rootu repa nakonfiguruje rewrite + statiku.
- **Apache** — `.htaccess` v rootu repa, vyžaduje `mod_rewrite`, `mod_headers`.

Po nasazení web serveru pokračuj kapitolou [Po instalaci](05_Po_instalaci.md).
