# API-dokumentaatio

Traficom radioamatöörikutsujen seurantatyökalu tarjoaa JSON REST API:n kutsumerkkidatan hakemiseen.

Toteutus: **PHP 8.1+ / Slim 4**, PDO + MariaDB.

Base URL: `https://oh-kutsumerkit.oh2lak.radio`

---

## Kenttien selitykset

### Kategoriat (`category`)

| Arvo | Selitys |
|------|---------|
| `new` | Aidosti uusi kutsumerkki — ei esiinny aiemmissa snapshotissa |
| `renewal` | Lupauusinta — kutsumerkki katosi tilapäisesti ja palasi 7 päivän sisällä |
| `genuine_remove` | Vahvistettu poisto — kutsumerkki poistunut yli 7 päivää sitten eikä palannut |
| `pending` | Odottaa luokittelua — poistunut alle 7 päivää sitten, lopullinen luokittelu kesken |

### Näkymät (`view`)

| Arvo | Selitys |
|------|---------|
| `clean` | Siivottu data — suodattaa pois `renewal`- ja `pending`-tapaukset, näyttää vain aidot uudet ja poistetut |
| `raw` | Raakadata — kaikki muutokset mukaan lukien lupauusinnat |

---

## Endpointit

### `GET /api/summary`

Palauttaa KPI-yhteenvedon: viimeisimmän päivän kokonaismäärä sekä 7 päivän summat.

**Parametrit:** ei ole

**Esimerkki:**
```
GET /api/summary
```

**Vastaus:**
```json
{
  "latest": {
    "stat_date": "2026-05-29",
    "total": 7636,
    "added": 5,
    "removed": 2,
    "new_callsigns": 5,
    "renewals": 0,
    "genuine_removes": 2,
    "pending_removes": 0
  },
  "last_7_days": {
    "added_7d": 12,
    "removed_7d": 8,
    "new_7d": 12,
    "renewals_7d": 2,
    "genuine_removes_7d": 6,
    "pending_removes_7d": 0
  }
}
```

---

### `GET /api/stats`

Palauttaa päivittäiset tilastot aikasarjana. Käytetään kaavioihin.

**Parametrit:**

| Parametri | Tyyppi | Oletus | Min | Max | Kuvaus |
|-----------|--------|--------|-----|-----|--------|
| `days` | int | `90` | `7` | `730` | Kuinka monen päivän historia palautetaan |
| `view` | string | `clean` | – | – | `clean` tai `raw` |

**Esimerkit:**
```
GET /api/stats
GET /api/stats?days=30
GET /api/stats?days=365&view=raw
```

**Vastaus** (taulukko päivittäisistä riveistä):
```json
[
  {
    "stat_date": "2026-05-29",
    "total": 7636,
    "added": 5,
    "removed": 2,
    "new_callsigns": 5,
    "renewals": 0,
    "genuine_removes": 2,
    "pending_removes": 0,
    "display_added": 5,
    "display_removed": 2
  }
]
```

> `display_added` ja `display_removed` ovat `view`-parametrin mukaan lasketut arvot — käytä näitä kaavioihin.
> `clean`-näkymässä: `display_added = new_callsigns`, `display_removed = genuine_removes`
> `raw`-näkymässä: `display_added = added`, `display_removed = removed`

---

### `GET /api/changes`

Palauttaa yksittäiset kutsumerkkimuutokset aikajärjestyksessä uusimmasta vanhimpaan.

**Parametrit:**

| Parametri | Tyyppi | Oletus | Min | Max | Kuvaus |
|-----------|--------|--------|-----|-----|--------|
| `days` | int | `30` | `1` | `365` | Kuinka monen päivän historia palautetaan |
| `kind` | string | `all` | – | – | `all`, `added` tai `removed` |
| `view` | string | `clean` | – | – | `clean` tai `raw` |

**Esimerkit:**
```
GET /api/changes
GET /api/changes?days=7
GET /api/changes?days=30&kind=added
GET /api/changes?days=30&kind=removed&view=raw
GET /api/changes?days=90&view=raw
```

**Vastaus** (taulukko muutosriveistä):
```json
[
  {
    "change_date": "2026-05-29",
    "callsign": "OH2FOO",
    "change_type": "added",
    "category": "new"
  },
  {
    "change_date": "2026-05-29",
    "callsign": "OH5NEG",
    "change_type": "removed",
    "category": "genuine_remove"
  }
]
```

---

### `GET /api/search`

Hakee yksittäisen kutsumerkin tilan ja muutoshistorian.

**Parametrit:**

| Parametri | Tyyppi | Pakollinen | Kuvaus |
|-----------|--------|-----------|--------|
| `q` | string | kyllä | Kutsumerkki (1–20 merkkiä, muunnetaan automaattisesti isiksi kirjaimiksi) |

**Esimerkit:**
```
GET /api/search?q=OH2LAK
GET /api/search?q=oh2lak
GET /api/search?q=OG1SDR
```

**Vastaus — kutsumerkki löytyy ja on voimassa:**
```json
{
  "found": true,
  "callsign": "OH2LAK",
  "active": true,
  "status": "VOIMASSA",
  "snapshot_date": "2026-05-29",
  "removed_date": null,
  "changes": [
    {
      "change_date": "2021-06-15",
      "change_type": "added",
      "category": "new"
    }
  ]
}
```

**Vastaus — kutsumerkki löytyy mutta on poistettu:**
```json
{
  "found": true,
  "callsign": "OH5NEG",
  "active": false,
  "status": "POISTETTU",
  "snapshot_date": null,
  "removed_date": "2026-05-29",
  "changes": [
    {
      "change_date": "2026-05-29",
      "change_type": "removed",
      "category": "genuine_remove"
    }
  ]
}
```

**Vastaus — kutsumerkkiä ei löydy:**
```json
{
  "found": false,
  "callsign": "OH9XYZ"
}
```

---

## Käyttöesimerkkejä

### curl

```bash
# Viimeisin yhteenveto
curl https://oh-kutsumerkit.oh2lak.radio/api/summary

# Tänään lisätyt kutsumerkit
curl "https://oh-kutsumerkit.oh2lak.radio/api/changes?days=1&kind=added"

# Hae kutsumerkki
curl "https://oh-kutsumerkit.oh2lak.radio/api/search?q=OH2LAK"

# Kaikki muutokset viimeiseltä vuodelta raakadatana
curl "https://oh-kutsumerkit.oh2lak.radio/api/stats?days=365&view=raw"
```

### Python

```python
import requests

base = "https://oh-kutsumerkit.oh2lak.radio"

# Tarkista onko kutsumerkki voimassa
r = requests.get(f"{base}/api/search", params={"q": "OH2LAK"})
data = r.json()
if data["found"] and data["active"]:
    print(f"{data['callsign']} on voimassa (tieto {data['snapshot_date']})")
else:
    print(f"{data['callsign']} ei löydy tai on poistettu")

# Hae tämän viikon uudet kutsumerkit
r = requests.get(f"{base}/api/changes", params={"days": 7, "kind": "added", "view": "clean"})
uudet = r.json()
print(f"Uusia kutsumerkkejä viikolla: {len(uudet)}")
for cs in uudet:
    print(f"  {cs['change_date']}  {cs['callsign']}")
```

### JavaScript / fetch

```javascript
// Hae päivän muutokset
const r = await fetch('https://oh-kutsumerkit.oh2lak.radio/api/changes?days=1&view=raw');
const muutokset = await r.json();
const uudet    = muutokset.filter(m => m.change_type === 'added');
const poistetut = muutokset.filter(m => m.change_type === 'removed');
console.log(`Tänään: +${uudet.length} uutta, -${poistetut.length} poistettu`);
```

---

## Huomioita

- Kaikki päivämäärät ovat muodossa `YYYY-MM-DD`
- Data päivitetään kerran vuorokaudessa klo 04:00 Suomen aikaa
- Historia alkaa toukokuusta 2021 (Telegram-bottidata) ja jatkuu tästä eteenpäin live-fetcherillä
- Rajapinta on julkinen, ei vaadi autentikointia
