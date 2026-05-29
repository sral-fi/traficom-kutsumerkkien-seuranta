# Traficom radioamatöörikutsumerkkien seurantatyökalu

Seuraa suomalaisten radioamatöörikutsujen (OF/OG/OH/OI/OJ) muutoksia Traficomin rekisterissä.

## Testaus-stack

- Python 3.11, FastAPI, MariaDB
- Proxmox LXC (Debian 12)
- nginx reverse proxy

## Tiedostorakenne

```
traficom-tracker/
├── app.py              # FastAPI-webserveri
├── fetcher.py          # Hakee Traficomilta kutsumerkkilistan ja laskee päivittäiset erot
├── db.py               # MariaDB-yhteys ja tietokantarakenne
├── requirements.txt
├── .env                # Ei repossa – katso .env.example
├── .env.example
└── templates/
    └── index.html      # Dashboard
    └── style.css       # CSS settings
    └── font.ttf        # Alternative font
    └── font.woff       # Alternative font
```

## Asennus

```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
# Muokkaa .env oikeilla arvoilla
```

## Tietokanta

```sql
CREATE DATABASE traficom_tracker CHARACTER SET utf8mb4;
CREATE USER 'traficom'@'localhost' IDENTIFIED BY 'salasana';
GRANT ALL PRIVILEGES ON traficom_tracker.* TO 'traficom'@'localhost';
```

Taulut luodaan automaattisesti ensimmäisellä ajolla.

## Käyttö

```bash
# Hae data kerran (tai pakota uushaku)
python3 fetcher.py
python3 fetcher.py --force

# Käynnistä webserveri
uvicorn app:app --host 0.0.0.0 --port 8099
```

## Cron
 
Webserveri (systemd) pitää dashboardin käynnissä jatkuvasti, mutta se ei itse hae dataa Traficomilta. Tietojen päivitys tapahtuu erillisellä `fetcher.py`-skriptillä, joka ajetaan cronilla kerran vuorokaudessa klo 04:00. Fetcher hakee kutsumerkkilistan Traficomin palvelulta, tallentaa snapshotin tietokantaan ja laskee muutokset edelliseen päivään verrattuna.
 
```
0 4 * * * cd /opt/traficom-tracker && /opt/traficom-tracker/venv/bin/python3 fetcher.py >> /var/log/traficom-fetcher.log 2>&1
```

## Systemd

Palvelu pyörii turvallisuussyistä erillisellä järjestelmäkäyttäjällä `traficom-web`, jolla ei ole login-shelliä eikä kotihakemistoa:

```bash
useradd --system --no-create-home --shell /usr/sbin/nologin traficom-web
chown -R traficom-web:traficom-web /opt/traficom-tracker
```

```ini
[Unit]
Description=Traficom Callsign Tracker
After=network.target mariadb.service

[Service]
User=traficom-web
Group=traficom-web
WorkingDirectory=/opt/traficom-tracker
ExecStart=/opt/traficom-tracker/venv/bin/uvicorn app:app --host 0.0.0.0 --port 8099
Restart=always

[Install]
WantedBy=multi-user.target
```

## API

| Endpoint | Kuvaus |
|----------|--------|
| `GET /` | Dashboard |
| `GET /api/summary` | KPI-yhteenveto |
| `GET /api/stats?days=90&view=clean` | Päivittäiset tilastot |
| `GET /api/changes?days=30&view=clean` | Muutosloki |
| `GET /api/search?q=OH2LAK` | Kutsumerkkihaku |

Täydellinen API-dokumentaatio parametreineen ja esimerkkeineen: [API.md](API.md)

## Grace period -logiikka

Lupauusinnat aiheuttavat tilapäisen katoamisen listalta (Traficom poistaa vanhan luvan ennen uuden myöntämistä). `GRACE_DAYS=7` — jos kutsumerkki palaa 7 päivän sisällä, se luokitellaan `renewal`-kategoriaan eikä näy aitona poistona.
