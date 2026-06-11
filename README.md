# UnD – Unemployment Data Visualizer

Instrument Web de vizualizare și comparare multi-criterială a datelor publice privind șomajul înregistrat din România (statistici ANOFM via [data.gov.ro](https://data.gov.ro)).

**Disciplină:** Tehnologii Web 2026 · UAIC Iași

## Documentație

Documentația tehnică completă (cerințe, arhitectură, securitate, caching) se află în **[docs/README.html](docs/README.html)** — accesibilă și din meniul aplicației.

## Rulare locală

Cerințe: PHP ≥ 8.0 cu extensia `pdo_sqlite`.

```bash
php -S localhost:8080
```

| Pagină | URL |
|--------|-----|
| Dashboard | http://localhost:8080/ |
| Administrare | http://localhost:8080/admin/ |
| Documentație | http://localhost:8080/docs/README.html |

Baza de date SQLite se creează automat la prima cerere. Pentru date reale: **Admin → Cache → Actualizare date din API**.

## Structură

```
index.html          Dashboard (filtre, grafice D3, hartă, export)
admin/index.html    Panou administrare
api/                Backend PHP (date, export, admin, auth, cache, fetcher)
js/                 Frontend ES modules (app, charts, map)
css/main.css        Stiluri
data/               GeoJSON județe România
docs/README.html    Documentație tehnică
```

## Repository

[github.com/beatricefilote654-blip/Web_Project_2026](https://github.com/beatricefilote654-blip/Web_Project_2026)

## Licență

Cod sursă: [MIT License](LICENSE).

Atribuire date și resurse:
- **Date șomaj** — ANOFM via [data.gov.ro](https://data.gov.ro), licențiate sub Creative Commons Attribution 4.0 (CC BY 4.0).
- **GeoJSON județe România** — derivat din OpenStreetMap, © OpenStreetMap contributors, Open Database License (ODbL).
