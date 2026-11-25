# GovernIT Data Eraser — Online Web Portal

Online-webapp (PHP + MySQL) til synkronisering af GovernIT Data Eraser. Den lokale
Management-app (Rust/SQLite) synkroniserer med denne centrale MySQL-database via en
JSON REST API, hvor MySQL er master for masterdata (kunder/projekter/teknikere/
roller/profiler), og den lokale app skubber session/certifikatresultater op.

> **Milestone i denne omgang:** fuldt REST API med autentificering, RBAC,
> komplet CRUD (kunder, projekter, teknikere, roller, profiler, brugere,
> organisation) samt sync-endepunkter (push/pull). Skemaet er klart til
> integration med den lokale Rust-app. Rapport-/PDF-generering og web-grænseflade
> (hvis ønsket) er næste skridt.

## Struktur

```
DiskEraserWeb/
├─ .gitignore                     # beskytter config/db.php (rigtige credentials)
├─ config/
│  ├─ db.sample.php               # template med pladsholdere (kan committes)
│  └─ db.php                      # RIGTIGE credentials – git-ignoreret, IKKE commit
├─ migrations/
│  └─ 001_schema.sql              # MySQL-skema + seed (roller + admin)
└─ web/                           # webroot (upload indholdet hertil til FTP)
   ├─ .htaccess                   # front-controller rewrite (Apache/Gigahost)
   ├─ index.php                   # JSON-API-router (front controller)
   ├─ api/                        # ressource-handlere (auth, users, roles, customers,
   │                              #  projects, technicians, profiles, organisation, sync)
   ├─ includes/
   │  ├─ db.php                   # PDO-forbindelse + config-load + JSON-hjælpere
   │  ├─ auth.php                 # login/bearer-token + RBAC-hjælpere
   │  └─ validate.php             # input-valideringshjælpere
```

## Opsætning

### 1. Credentials (én gang)
```bash
cd DiskEraserWeb/config
cp db.sample.php db.php
```
Udfyld `db.php` med de rigtige værdier fra `Config_diskeraser.odt`
(DB: `ckozkl4kj.mysql.service.one.com`, db `ckozkl4kj_ssh`, bruger `ckozkl4kj_ssh`).
Sæt også `app.sync_token` til et stærkt hemmeligt tilfældigt stykke tekst.
**`db.php` er git-ignoreret og må aldrig committes.**

### 2. Migrér databasen
Kør skemaet én gang mod MySQL (fra en maskine med `mysql`-CLI, eller via
phpMyAdmin → Import):
```bash
mysql -h ckozkl4kj.mysql.service.one.com -u ckozkl4kj_ssh -p ckozkl4kj_ssh < migrations/001_schema.sql
```
Seeding opretter standardrollerne (Administrator, Koordinator, Tekniker, Revisor)
og en initial admin-bruger med det midlertidige password **`changeme`** — skift det
med det samme ved første login.

### 3. Deploy til web (FTP) — Gigahost-tjekliste

> **Vigtigt om webroot på Gigahost:** Domænets webroot er **IKKE** FTP-roden. For
> subdomæner ligger webroot i en undermappe (fx `www/eraser.governit.dk/`).
> Uploader du til FTP-roden, ligger der ingen `index.php` i selve webroot → serveren
> svarer **403 Forbidden** (og seje URL'er som `/health` giver 404).

1. **Find webroot-mappen.** Log på FTP (`sftp.governit.dk`, bruger: `ckozkl4kj_ssh`) og
   navigér til `www/eraser.governit.dk/`.

2. **Upload indholdet af `web/`** ind i den mappe. Efter upload skal du i
   subdomæne-webroot-mappen kunne se:
   ```
   www/eraser.governit.dk/
   ├─ .htaccess
   ├─ index.php
   ├─ api/          (auth.php, customers.php, roles.php, ...)
   ├─ includes/     (db.php, auth.php, validate.php)
   └─ config/       (db.php + .htaccess med deny — beskytter credentials)
   ```

3. **`config/db.php`** ligger inde i webroot (under `config/`) på Gigahost, fordi
   FTP-brugeren ikke kan skrive til den delte `www/`-mappe. Filen er beskyttet af
   `config/.htaccess` (deny all) så den ikke er offentligt tilgængelig.
   Indstillingerne er sat under opstart — se `config/db.php` i repoet.

4. **Filsystemrettigheder:** mapper `755`, filer `644`. Aldrig `777`.

5. **Bekræft `.htaccess`:** Mod_rewrite og `Authorization`-header-forwarding skal
   fungere automatisk på Gigahost. Tjek evt. Gigahost-kontrolpanelet.

### 4. Bekræft
Åbn `https://eraser.governit.dk/health` — skal returnere JSON med `ok:true`,
`schema:"migrated"` og tabelantal.
Åbn `https://eraser.governit.dk/api/v1/meta` — skal liste endepunkterne.

## API (implementeret)

REST JSON-API. Auth via `Authorization: Bearer <token>` (hentet fra login) —
eller `X-Sync-Token:`-header for sync/query-endepunkterne. RBAC styres af
roller (Administrator, Koordinator, Tekniker, Revisor).

| Endpoint | Beskrivelse | Rettigheder |
|----------|-------------|-------------|
| `GET /health` | Driftstatus + skemastatus | offentlig |
| `GET /api/v1/meta` | Version + endepunkter | offentlig |
| `POST /api/v1/auth/login` | Login → Bearer-token | offentlig |
| `POST /api/v1/auth/logout` | Ugyldiggør token | login |
| `GET /api/v1/auth/me` | Aktuel bruger + roller | login |
| CRUD `/api/v1/users` | Web-brugeradministration | Admin/Koordinator |
| CRUD `/api/v1/roles` | Rolleadministration | Admin |
| CRUD `/api/v1/customers` | Kunder | Admin/Koordinator(+Revisor ok) |
| CRUD `/api/v1/projects` | Projekter | Admin/Koordinator(+Revisor ok) |
| CRUD `/api/v1/technicians` | Teknikere (inkl. roller) | Admin/Koordinator(+Revisor ok) |
| CRUD `/api/v1/profiles` | Sletningsprofiler | Admin/Koordinator(+Revisor ok) |
| `GET/PUT /api/v1/organisation` | Organisation (singleton) | GET: Admin/Koord/Revisor; PUT: Admin |
| `GET /api/v1/query/master-data` | Komplet masterkonfiguration (sync ned) | sync |
| `GET /api/v1/query/sessions` | Sessioner + certifikater (rapporter) | sync |
| `GET /api/v1/sync/pull-config?since=` | Delta-master siden cursor | sync |
| `POST /api/v1/sync/push-results` | Skub sessioner+certifikater op (idempotent) | sync |

CRUD-konventioner: `POST`=opret, `PUT`=opdater, `DELETE`=deaktiver (soft-delete),
lister støtter `?active=`, `?customer_id=`, `?status=` og lignende filtre.
Fejl returneres som `{"error":"<kode>"}` med passende HTTP-status.

## Synkroniseringsmodel

Browserportalen under `/ui/` bruger en HttpOnly-sessioncookie. Management-klienten
kan fortsat bruge bearer-login eller den konfigurerede synkroniseringsnøgle.
Læsning af sessions- og certifikatlister følger brugerens kundeadgang.
Fuld konfigurationssynkronisering kræver en rolle med global læseadgang.
Resultatimport kræver en global administrator eller koordinator, og import af
masterdata kræver en global administrator. Synkroniseringsnøglen kan fortsat
bruges til begge typer import.

Alle klient-synkede tabeller har `updated_at` (UTC, ISO-8601) samt `deleted`
tombstone-flag, så offline-klienter kan delta-synke uden tab. `sync_log`
journalfører hver stik-synkronisering for revisions-/auditformål. Den lokale app
henter master med `pull-config` (eller `query/master-data`) og skubber resultater
med `push-results`, som er idempotent nøglet på `certificate_hash` / `sync_key`.

## Lokal test med Docker (MySQL 8)
```bash
docker run -d --name eraser-test-db -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=ckozkl4kj_ssh -p 13306:3306 mysql:8
# vent på readiness, udfør migration
docker exec -i eraser-test-db mysql -uroot -proot ckozkl4kj_ssh < migrations/001_schema.sql
cp config/db.sample.php config/db.php   # peg på 127.0.0.1:13306 + root/root
php -S 127.0.0.1:8099 web/index.php     # server fra DiskEraserWeb/
curl -X POST localhost:8099/api/v1/auth/login -d '{"username":"admin","password":"changeme"}'
```
