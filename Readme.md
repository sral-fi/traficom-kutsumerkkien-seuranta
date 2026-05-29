# Traficom radioamatöörikutsumerkkien seurantatyökalu

Seuraa suomalaisten radioamatöörikutsujen (OF/OG/OH/OI/OJ) muutoksia Traficomin rekisterissä.

## Stack

| Komponentti | Teknologia |
|-------------|-----------|
| REST API | PHP 8.1+, Slim 4, PDO/MariaDB |
| Frontend | React 18, Vite, Chart.js |
| Datahakija | Python 3.11, requests, BeautifulSoup |
| Tietokanta | MariaDB / MySQL |
| Reverse proxy | nginx |

## Tiedostorakenne

```
traficom-tracker/
├── api/                        # PHP Slim4 REST API
│   ├── public/
│   │   ├── index.php           # Entry point (Slim app)
│   │   └── .htaccess           # Apache rewrite (nginx ei tarvitse)
│   ├── src/
│   │   └── Database.php        # PDO-wrapper (singleton)
│   ├── composer.json
│   └── .env.example
├── frontend/                   # React-dashboard
│   ├── src/
│   │   ├── main.jsx
│   │   ├── App.jsx
│   │   ├── api.js              # API-client
│   │   ├── index.css
│   │   └── components/
│   │       ├── KpiRow.jsx
│   │       ├── DailyStatus.jsx
│   │       ├── SearchCard.jsx
│   │       ├── Charts.jsx
│   │       └── ChangeLog.jsx
│   ├── index.html
│   ├── vite.config.js
│   ├── package.json
│   └── .env.example
├── fetcher.py                  # Hakee Traficomilta listan ja laskee diff
├── db.py                       # MariaDB-yhteys (Python, käytetään fetcherissä)
├── requirements.txt            # Python-riippuvuudet (fetcherille)
├── calls_sral_fi.sql           # Tietokannan alustuskomennot
└── API.md                      # REST API -dokumentaatio
```

## Tietokanta

```bash
# Luo tietokanta ja taulut
mysql -u root -p < calls_sral_fi.sql
```

Muokkaa ensin `calls_sral_fi.sql`-tiedoston käyttäjä/salasana-rivit tai luo käyttäjä erikseen:

```sql
CREATE USER 'calls'@'localhost' IDENTIFIED BY 'salasana';
GRANT ALL PRIVILEGES ON traficom_tracker.* TO 'calls'@'localhost';
FLUSH PRIVILEGES;
```

## PHP Slim4 API

```bash
cd api
cp .env.example .env
# Muokkaa .env – tietokantayhteystiedot ja CORS_ORIGIN

composer install

# Kehityspalvelin (PHP built-in)
php -S localhost:8080 -t public
```

## React-frontend

```bash
cd frontend
cp .env.example .env      # valinnainen – proxy hoitaa API-kutsut dev-tilassa
npm install
npm run dev               # http://localhost:5173

# Tuotantobuild
npm run build             # tulostuu frontend/dist/
```

## Python-datahakija

```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env      # sama .env kuin API:lle (DB-yhteystiedot)

python3 fetcher.py        # yksi ajo
python3 fetcher.py --force  # pakota uushaku vaikka tänään jo haettu
```

## Cron

```
0 4 * * * cd /opt/traficom-tracker && /opt/traficom-tracker/venv/bin/python3 fetcher.py >> /var/log/traficom-fetcher.log 2>&1
```

## nginx-konfiguraatio

```nginx
server {
    listen 80;
    server_name calls.sral.fi;

    # React-frontend (staattinen build)
    root /opt/calls-tracker/frontend/dist;
    index index.html;

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }

    # PHP Slim4 API – välitä PHP-FPM:lle
    location /api/ {
        root   /opt/calls-tracker/api/public;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /opt/calls-tracker/api/public/index.php;
        include fastcgi_params;
    }
}
```

## PHP-FPM systemd (vaihtoehto built-in palvelimelle)

Tuotannossa ajetaan PHP-FPM:n kautta. Käynnistä PHP-FPM normaalisti distron paketilla (`apt install php8.2-fpm`). React-frontend tarjoillaan nginxin kautta staattisina tiedostoina `frontend/dist/`-hakemistosta.

Fetcherille oma systemd-timer tai cron (ks. yllä).

## API

| Endpoint | Kuvaus |
|----------|--------|
| `GET /api/summary` | KPI-yhteenveto |
| `GET /api/stats?days=90&view=clean` | Päivittäiset tilastot |
| `GET /api/changes?days=30&view=clean` | Muutosloki |
| `GET /api/search?q=OH2LAK` | Kutsumerkkihaku |

Täydellinen API-dokumentaatio parametreineen ja esimerkkeineen: [API.md](API.md)

## Grace period -logiikka

Lupauusinnat aiheuttavat tilapäisen katoamisen listalta (Traficom poistaa vanhan luvan ennen uuden myöntämistä). `GRACE_DAYS=7` — jos kutsumerkki palaa 7 päivän sisällä, se luokitellaan `renewal`-kategoriaan eikä näy aitona poistona.
