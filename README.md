# Firma krok po kroku — backend KPiR (PHP + MySQL)

Serwer synchronizacji **podatkowej ksiegi przychodow i rozchodow (KPiR)** dla aplikacji
*Firma krok po kroku*. Faza 2 projektu: jedna ksiega dostepna z wielu urzadzen, ze
wspoldzieleniem (np. z ksiegowa). API jest neutralne (JSON) i pokrywa sie z kontraktem klienta
(`docs/kpir-f2-plan.md` oraz `docs/kpir-f2-api-php.md` w repo aplikacji).

Stack dobrany pod **hosting wspoldzielony cal.pl AC**: czysty PHP (bez frameworka, bez
dlugozyjacego procesu) + MySQL. Zaleznosci zewnetrzne minimalne (weryfikacja JWT z gotowej,
sprawdzonej biblioteki), wgrywane w `vendor/`, bo composer bywa na hostingu niedostepny.

> **ZASADA:** sekrety (haslo DB, sekret JWT, `GOOGLE_CLIENT_ID`) NIGDY nie trafiaja do repo.
> Sa w `config.local.php` (w `.gitignore`). W repo jest tylko `config.local.php.example`.

## Stan (slice'y)

- [x] **1. Szkielet** — front controller, router, PDO, `Http`, `/api/health`, `schema.sql`.
- [x] **2. Auth** — `POST /api/auth/google` (weryfikacja ID tokenu Google: JWKS/aud/iss) →
  sesyjny JWT (HS256) + middleware `Auth::requireUserId`. `GET /api/me` do testu.
- [ ] 3. Ksiegi — `GET/POST /api/ksiegi`, czlonkostwo, role, zaproszenia.
- [ ] 4. Sync KPiR — rezerwacja wpisu (idempotentna), `PUT` z `If-Match` (409), delta pull.

## Struktura

```
public/index.php     front controller (routing, wymuszenie HTTPS, obsluga bledow)
public/.htaccess     rewrite -> index.php (gdy docroot = /public)
.htaccess            zabezpieczenie awaryjne (gdy docroot = katalog glowny repo)
src/bootstrap.php    autoloader Firma\ + opcjonalnie vendor/
src/Config.php       ladowanie config.local.php
src/Db.php           PDO (prepared statements) + transakcje
src/Http.php         odczyt zadania / wysylka JSON
src/HttpError.php    wyjatek z kodem HTTP
src/Router.php       dopasowanie tras z parametrami {id}
src/Auth.php         sesyjny JWT (HS256): wydanie, weryfikacja, middleware requireUserId
src/GoogleVerifier.php  weryfikacja ID tokenu Google (JWKS cache, aud, iss)
src/Repo/UserRepo.php   upsert uzytkownika po google_sub
schema.sql           schemat MySQL (import w phpMyAdmin)
config.local.php.example  wzorzec konfiguracji (skopiuj do config.local.php)
composer.json/.lock  manifest zaleznosci (vendor/ jest wersjonowany — patrz nizej)
vendor/              zaleznosci PHP (firebase/php-jwt) — WGRANE do repo, bo cal.pl bez composera
```

## Uruchomienie lokalne (dev)

Wymagany PHP 8.1+ (dopasowany do wersji na hostingu; `declare(strict_types=1)`, enumy, `readonly`).

```bash
cp config.local.php.example config.local.php   # uzupelnij dane lokalnej bazy; force_https => false
php -S localhost:8080 -t public                # wbudowany serwer PHP
curl http://localhost:8080/api/health
```

Oczekiwana odpowiedz `/api/health`:

```json
{"status":"ok","time":"2026-09-09T12:00:00+00:00","db":"ok"}
```

`db: "error"` oznacza, ze aplikacja wstaje, ale nie ma polaczenia z baza (sprawdz `config.local.php`
i czy zaimportowano `schema.sql`). `db` nigdy nie ujawnia szczegolow bledu klientowi — patrz log.

## Wdrozenie na cal.pl AC

1. **Baza:** w panelu utworz baze MySQL i uzytkownika o **minimalnych** uprawnieniach (tylko ta
   baza). Zaimportuj `schema.sql` przez phpMyAdmin.
2. **Pliki:** wgraj repo (FTP/git).
   - Preferowane: ustaw docroot domeny/subdomeny na katalog `public/`. Wtedy `config.local.php`,
     `src/` i `vendor/` sa poza katalogiem WWW — najbezpieczniej.
   - Jesli nie da sie zmienic docroot (caly katalog repo jest w WWW): dziala awaryjny `.htaccess`
     w katalogu glownym (blokuje sekrety/kod, kieruje `/api/*` do `public/index.php`).
3. **Konfiguracja:** utworz `config.local.php` (skopiuj z `.example`), wpisz dane bazy, wygeneruj
   sekret JWT (`php -r "echo bin2hex(random_bytes(32));"`) i `GOOGLE_CLIENT_ID`.
4. **HTTPS:** wlacz SSL (Let's Encrypt z panelu). `force_https => true` w konfiguracji oraz
   przekierowanie w `.htaccess` wymuszaja szyfrowanie (wymog RODO).
5. **Sprawdz:** `curl https://twoja-domena/api/health` → `{"status":"ok",...,"db":"ok"}`.

## RODO / bezpieczenstwo

Dane KPiR to dane osobowe (kontrahenci, kwoty). Zasady, ktorych pilnujemy w kodzie i wdrozeniu:

- **HTTPS wymuszony** (aplikacyjnie + `.htaccess`).
- **PDO prepared statements** wszedzie — brak sklejania SQL, brak SQL injection.
- **Sekrety poza repo** (`config.local.php` w `.gitignore`).
- **Uzytkownik DB o najmniejszych uprawnieniach** (tylko wlasna baza).
- **Role egzekwowane serwerowo** (OWNER/EDITOR/VIEWER) — slice 3.
- Hosting w PL/UE (cal.pl spelnia). Wspoldzielenie z ksiegowa = powierzenie danych —
  rozwaz umowe powierzenia (DPA).
