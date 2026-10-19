# BookyWay Calendar for WordPress

Show your [BookyWay](https://www.bookyway.com) class schedule on your WordPress site: times, instructor, available spots and a **Book** button that opens the BookyWay web app.

🇮🇹 [Versione italiana più sotto](#italiano)

> Unofficial plugin, not affiliated with or endorsed by BookyWay.

## Features

- Shortcode `[bookyway_calendar]`, works with the block editor, Elementor and any page builder
- Upcoming days grouped by day, full classes greyed out, classes already started today hidden
- API keys stay on the server: visitors never see them
- Server-side cache (default 2 hours) to keep API usage low, with fallback to the last good copy if BookyWay is unreachable
- Booked member IDs are never stored or shown
- All front-end texts and colors configurable from the settings page, day/month names follow the site language
- Works with persistent object caches (Redis, Memcached)

## Requirements

- WordPress 5.8+, PHP 7.4+
- BookyWay OpenAPI access (paid add-on with a free trial; ask BookyWay support). You need:
  - a **Bookyway-API-Key** (Primary or Secondary, from your profile on the BookyWay API portal)
  - your account **BookyWay-Secret-Key** (sent by BookyWay)

## Installation

1. Download the repository as ZIP (**Code → Download ZIP**) or a release ZIP.
2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the ZIP and activate.
3. Go to **Settings → BookyWay Calendar**, paste the two keys, click **Save**, then **Test connection**: it lists your cloud IDs.
4. Enter your **Default cloud ID** and save.
5. Add the shortcode to a page.

> If you build the ZIP yourself on Windows, avoid PowerShell 5.1 `Compress-Archive`: it writes backslashes in paths and WordPress will not find the plugin file.

### Keeping the keys in `wp-config.php` (recommended)

```php
define( 'BYC_API_KEY', 'your-primary-key' );
define( 'BYC_SECRET_KEY', 'your-secret-key' );
```

## Shortcode

```
[bookyway_calendar]
[bookyway_calendar cloud="12345" days="5" title="Our classes" note="First time? <a href='/contact/'>Get in touch</a>"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `cloud`   | setting | BookyWay cloud ID (one per location) |
| `days`    | 7       | Days to show, 1–14 |
| `title`   | setting | Heading above the calendar |
| `note`    | setting | Short text under the title (allowed HTML: a, strong, em, br) |

## API usage

`GET /clouds/{cloudId}/events/daily` returns **one day per call**, so each refresh costs one call per day shown:

| Days | Refresh | Max calls / month (per cloud) |
|------|---------|-------------------------------|
| 7    | 1 h     | ~5,000 |
| 7    | 2 h     | ~2,500 |
| 7    | 3 h     | ~1,700 |

Calls only happen when someone views the page and the cache has expired, so real usage is usually lower.

### Notes on the BookyWay API (v2)

- Base URL `https://openapi.bookyway.com/public`, headers `Bookyway-API-Key`, `BookyWay-Secret-Key`, `API-Version: v2`
- `startDate` must be **`YYYYMMDD`** (e.g. `20261020`); ISO dates return HTTP 400
- Event times are returned in local time without a timezone

## Caching plugins

Exclude the calendar page from full-page caches (Cache Enabler, WP Super Cache, LiteSpeed…), otherwise available spots won't update. After updating the plugin on servers with OPcache timestamp validation disabled, reload PHP-FPM.

## License

[MIT](LICENSE) © Menconi.IT

---

## Italiano

Mostra il calendario delle lezioni di **BookyWay** sul tuo sito WordPress: orari, istruttore, posti liberi e pulsante **Prenota** che apre la web app BookyWay.

> Plugin non ufficiale, non affiliato a BookyWay.

### Installazione

1. Scarica lo ZIP (**Code → Download ZIP**).
2. In WordPress: **Plugin → Aggiungi nuovo → Carica plugin**, carica lo ZIP e attiva.
3. **Impostazioni → BookyWay Calendar**: incolla la **API Key** (Primary, dal tuo profilo sul portale API BookyWay) e la **Secret Key** (te la manda BookyWay), salva e premi **Test connection** per vedere i tuoi cloud ID.
4. Inserisci il **cloud ID** della sede e salva.
5. Traduci i testi del calendario nella stessa pagina, per esempio:
   - Button label: `Prenota`
   - "Full": `Completo`
   - "1 spot left": `%d posto libero`, "N spots left": `%d posti liberi`
   - Today / Tomorrow: `Oggi` / `Domani`, "with": `con`
   - Nessuna lezione: `Nessuna lezione in programma nei prossimi giorni.`
6. Inserisci lo shortcode in una pagina: `[bookyway_calendar]`, oppure con più sedi `[bookyway_calendar cloud="12345" title="Lezioni – Roma"]`.

Le API di BookyWay sono un servizio a pagamento con prova gratuita: il costo dipende dal numero di chiamate al mese (vedi la tabella "API usage" sopra). Ricorda di escludere la pagina del calendario dalla cache delle pagine.
