# Photobook v2 — Vollständiger Implementierungsplan

> **Strategie:** In-place Neuaufbau in diesem Repo. Bestehender Code bleibt zunächst lauffähig.  
> **Stack:** Laravel 13 · React 19.2 · TypeScript · Tailwind CSS · Playwright (PDF) · Python (Sidecar)  
> **Ziel:** Vollständige Feature-Parität + saubere Architektur  

---

## Architekturübersicht

```
Nextcloud (WebDAV)
  ↓
NextcloudConnector          PHP — liest Verzeichnisse, Streams
  ↓
PhotoAssetImporter          PHP — schreibt photo_assets-Tabelle
  ↓
ImageCacheService           PHP — cached Originale lokal
  ↓
ExtractPhotoFeaturesJob     PHP — dispatcht Python CLI per Foto
  ↓
python/photobook_ai/cli.py  Python — Analyse → JSON
  ↓
photo_features (DB)         MySQL/SQLite
  ↓
LayoutPlanner               PHP — wählt Template
  ↓
CostMatrixBuilder           PHP — Kostenmatrix mit ML-Features
  ↓
HungarianSolver             PHP — optimale Slot-Zuordnung
  ↓
RenderSpecBuilder           PHP — finale Placement-Daten (RenderSpec)
  ↓
pages.json (Cache)          Persistenter Editor-State
  ↓
React Editor                TypeScript — Drag, Zoom, Crop, Template-Wechsel
  ↓
PDF Export Job              PHP — startet Playwright
  ↓
playwright/render.js        Node.js — öffnet Editor-URL, druckt PDF
  ↓
PDF Datei
```

---

## Feature-Inventar (aktuelle Version → v2-Anforderung)

| Feature | Aktuell | v2 |
|---|---|---|
| Nextcloud WebDAV Scan | ✅ `NextcloudPhotoRepository` | ✅ bleibt, wird `NextcloudConnector` |
| Foto-Metadaten (EXIF, Dim) | ✅ `ImageProbe` | ✅ bleibt, wird `ImageProbe` |
| Foto-Caching lokal | ✅ in `PhotoBookBuilder` | ✅ eigener `ImageCacheService` |
| ML Features (Faces, Saliency) | ✅ `ml_extract.py` (Batch) | ✅ `python/photobook_ai/` (pro Foto) |
| Qualität (Sharpness, Aesthetic) | ✅ partiell | ✅ vollständig |
| Suggested Crop | ❌ fehlt | ✅ `crops.py` |
| pHash (Duplikate) | ⚠️ in DB-Schema, nicht befüllt | ✅ `phash.py` |
| Analysis Versioning | ❌ fehlt | ✅ `analysis_version` Spalte |
| Page Grouping | ✅ `PageGrouper` (adaptiv, hero-aware) | ✅ bleibt |
| Template-Katalog | ✅ `LayoutTemplates` (1–6 Fotos, 20+ Templates) | ✅ bleibt, wird zu JSON exportiert |
| Template-Scoring | ✅ Histogram, Repeat-Penalty, Bias | ✅ bleibt |
| Hungarian Assignment | ✅ in `LayoutPlannerV2::scoreTemplate()` | ✅ eigener `HungarianSolver` |
| Face Crop Validator | ✅ in `LayoutPlannerV2` | ✅ in `CostMatrixBuilder` |
| RenderSpec | ❌ implizit in `objectPosition` | ✅ explizites DTO |
| User Overrides | ✅ `overrides.json` | ✅ in `pages.json` / DB |
| React Editor | ✅ (Zustand, react-rnd) | ✅ bleibt, erweitert |
| PDF Export | ✅ Dompdf | 🔄 **Playwright** |
| Progress Tracking | ✅ `task.status.json` | ✅ bleibt (+ Broadcasting optional) |
| Cover-Seite | ✅ | ✅ bleibt |
| Collage Detection | ✅ `isCollage` Flag | ✅ bleibt |

---

## Phase 1 — Python Sidecar strukturieren

### Aufgabe
`ml_extract.py` in ein sauberes CLI-Package umbauen. Nichts wegwerfen — nur aufteilen.

### Neue Struktur
```
python/
  photobook_ai/
    __init__.py
    cli.py
    faces.py
    saliency.py
    quality.py
    crops.py
    phash.py
  requirements.txt
```

### Output-Schema (pro Foto)
```json
{
  "width": 4032,
  "height": 3024,
  "faces": [{ "cx": 0.5, "cy": 0.4, "w": 0.2, "h": 0.3, "score": 0.97 }],
  "saliency": { "cx": 0.5, "cy": 0.45 },
  "suggested_crop": { "align": { "x": -0.12, "y": -0.08 }, "zoom": 1.08 },
  "quality": { "sharpness": 0.74, "aesthetic": 6.8 },
  "phash": "a3f1c2d4e5b6a7f8",
  "horizon_deg": 0.3,
  "analysis_version": "v2.0"
}
```

### CLI-Aufruf
```bash
python -m photobook_ai.cli analyze --input /path/to/photo.jpg --output /tmp/features.json
```

### Schritte

**Schritt 1.1** — `python/photobook_ai/__init__.py` erstellen (leer)

**Schritt 1.2** — `python/photobook_ai/faces.py` erstellen  
Inhalt: `detect_faces_haarcascade()` aus `ml_extract.py` extrahieren + normalisieren

**Schritt 1.3** — `python/photobook_ai/saliency.py`  
Inhalt: `saliency_spectral()` aus `ml_extract.py`

**Schritt 1.4** — `python/photobook_ai/quality.py`  
Inhalt: `sharpness()` (Laplacian-Varianz), `aesthetic_dummy()` (Placeholder)

**Schritt 1.5** — `python/photobook_ai/crops.py`  
Neu: Berechnet `suggested_crop` aus Faces + Saliency

**Schritt 1.6** — `python/photobook_ai/phash.py`  
Neu: pHash via `imagehash`-Library

**Schritt 1.7** — `python/photobook_ai/cli.py`  
Entry point: `analyze` Command, alle Module aufrufen, JSON schreiben

**Schritt 1.8** — `python/requirements.txt`

---

## Phase 2 — DB Schema erweitern (additiv)

### Aufgabe
`photo_features`-Tabelle um neue Spalten erweitern. **Keine bestehenden Spalten löschen.**

### Neue Migration

Datei: `database/migrations/2026_05_10_000000_add_v2_columns_to_photo_features_table.php`

Neue Spalten:
- `suggested_crop` — JSON (`{align:{x,y}, zoom}`)
- `dominant_colors` — JSON
- `analysis_version` — String, default `'v1'`
- `analyzed_at` — Timestamp

### Model aktualisieren

`app/Models/PhotoFeature.php`:
- Neue Spalten in `$fillable` aufnehmen
- Neue `$casts` hinzufügen

### Repository erweitern

`app/Services/FeatureRepository.php`:
- `upsert()` bekommt optionalen `analysis_version`-Parameter
- Neue Methode `getStale(string $sinceVersion): Collection`

---

## Phase 3 — ImageCacheService extrahieren

### Aufgabe
Download- und Cache-Logik aus `PhotoBookBuilder` in eigene Klasse auslagern.

### Neue Klasse: `app/Services/ImageCacheService.php`

Methoden:
- `ensureCached(string $nextcloudPath): string` — gibt lokalen Pfad zurück
- `ensureCachedBatch(array $photos): array<string, string>` — Map path→localPath
- `buildManifest(array $photos, string $cacheDir): array` — Signatur-Check wie bisher
- `invalidate(string $folder): void`

`PhotoBookBuilder` ruft dann nur noch `$this->cache->ensureCachedBatch()` auf.

---

## Phase 4 — ExtractPhotoFeaturesJob

### Aufgabe
Neuer Laravel Job der Python CLI pro Foto aufruft.

### Neue Datei: `app/Jobs/ExtractPhotoFeaturesJob.php`

```php
public function __construct(
    private string $nextcloudPath,
    private string $localImagePath,
) {}
```

Ablauf:
1. `$outputPath = tempnam(sys_get_temp_dir(), 'pbf') . '.json'`
2. `Process::timeout(60)->run(['python', '-m', 'photobook_ai.cli', 'analyze', ...])`
3. JSON lesen, `FeatureRepository::upsert()` aufrufen
4. Temp-Datei löschen

`BuildPhotoBook`-Job dispatcht diesen Job für alle Fotos ohne Features oder mit veralteter `analysis_version`.

---

## Phase 5 — RenderSpec DTO

### Aufgabe
Explizites DTO für Placement-Daten einführen. Wird von Editor, SlotRenderer und PDF-Export gleichermaßen genutzt.

### Neue Datei: `app/DTO/RenderSpec.php`

```php
final class RenderSpec {
    public function __construct(
        public readonly string $photoPath,
        public readonly string $slotId,
        public readonly string $fit,        // 'cover' | 'contain'
        public readonly float  $alignX,     // -1.0 .. 1.0
        public readonly float  $alignY,
        public readonly float  $zoom,       // 1.0 = no zoom
        public readonly float  $rotation,   // degrees
        public readonly bool   $auto,       // false = user override
    ) {}

    public function toArray(): array { ... }
    public static function fromArray(array $d): self { ... }
    public static function fromSuggestedCrop(array $crop): self { ... }
    public static function neutral(string $path, string $slotId): self { ... }
}
```

### RenderSpecBuilder: `app/Services/RenderSpecBuilder.php`

- `build(array $assignment, array $featMap): array<RenderSpec>`
- Liest `suggested_crop` aus Features → setzt `auto = true`
- Wenn kein Feature vorhanden → `RenderSpec::neutral()`

---

## Phase 6 — CostMatrixBuilder + HungarianSolver

### Aufgabe
`scoreTemplate()` in `LayoutPlannerV2` aufteilen in eigenständige Services.

### Neue Klasse: `app/Services/CostMatrixBuilder.php`

```
cost(photo, slot) =
  aspectMismatch     * $w['crop']          // bereits vorhanden
+ orientationMismat  * $w['orientation']   // bereits vorhanden
+ faceCropPenalty    * 2.0                 // NEU: aus suggested_crop
+ saliencyCropPenalt * 0.8                 // NEU
- heroBonus          * $b['hero_bonus']    // bereits vorhanden
- qualityBonus       * 0.3                 // NEU: sharpness + aesthetic
+ chronologyPenalty  * $w['chronology']    // bereits vorhanden
```

### Neue Klasse: `app/Services/HungarianSolver.php`

Extrahiert aus `LayoutPlannerV2::hungarian()` — pure Funktion:

```php
/** @param float[][] $cost n×n matrix
 *  @return int[] photoIndex → slotIndex */
public function solve(array $cost): array { ... }
```

### LayoutPlannerV2 vereinfachen

`scoreTemplate()` wird zu:
```php
$cost   = $this->costBuilder->build($photos, $slots, $featMap);
$assign = $this->solver->solve($cost);
```

---

## Phase 7 — Playwright PDF-Export

### Aufgabe
`PdfRenderer` (Dompdf) durch Playwright ersetzen.

### Ansatz

Laravel startet Playwright über einen Node.js-Prozess. Playwright öffnet die React-Editor-URL mit `?print=1`, druckt zu PDF.

### Neue Dateien

**`playwright/render.js`** — nimmt `--url`, `--output`, `--width`, `--height`  
**`app/Services/PlaywrightPdfRenderer.php`** — startet Node-Prozess

### React-Seite

- Route `GET /photobook/preview/{hash}?print=1` — alle Seiten als druckbereites HTML
- CSS `@media print` regelt Seitenumbrüche

### package.json

```json
"@playwright/test": "^1.x"
```

---

## Phase 8 — React Editor (Erweiterungen)

### Was bleibt
- Zustand Store, react-rnd, TanStack Query, bestehende Komponenten

### Was neu kommt

**RenderSpec als TypeScript-Typ**

```typescript
interface RenderSpec {
  photoPath: string;
  slotId: string;
  fit: 'cover' | 'contain';
  alignX: number;   // -1..1
  alignY: number;
  zoom: number;     // 1.0 = original
  rotation: number;
  auto: boolean;
}
```

**SlotRenderer** erhält `RenderSpec` statt `objectPosition`-String  
**CropEditor** — User-Interaktion setzt `auto = false`

---

## Phase 9 — pages.json → DB (optional)

Nach Feature-Parität:
- Tabelle `photobook_projects` (id, folder_hash, pages_json, cover_json, timestamps)
- Tabelle `photobook_overrides` (project_id, page_id, data_json)

---

## Reihenfolge und Abhängigkeiten

```
Phase 1 (Python)     → Keine Abhängigkeiten, sofort startbar
Phase 2 (DB)         → Keine Abhängigkeiten, sofort startbar
Phase 3 (Cache)      → Keine Abhängigkeiten
Phase 5 (RenderSpec) → Keine Abhängigkeiten
Phase 6 (Planner)    → braucht Phase 5
Phase 4 (Job)        → braucht Phase 1 + 2
Phase 8 (React)      → braucht Phase 5
Phase 7 (Playwright) → braucht Phase 8
Phase 9 (DB)         → alles andere fertig
```

**Empfohlene Startreihenfolge:** `1 → 2 → 3 → 5 → 6 → 4 → 8 → 7 → 9`

---

## Was nicht geändert wird

| Komponente | Begründung |
|---|---|
| `LayoutTemplates.php` | Vollständig, 20+ Templates |
| `PhotoDto` | Sauber, immutable |
| `PageGrouper` | Adaptiv, hero-aware |
| `NextcloudPhotoRepository` | Funktioniert, bleibt als Basis |
| `ImageProbe` | EXIF-Logik bleibt |
| `FeatureRepository::hamming()` | Bleibt für pHash-Vergleich |
| API-Routen in `routes/api.php` | Struktur bleibt |
| Config `config/photobook.php` | Wird nur erweitert |
| Zustand-Store, TanStack Query | Bleiben im React-Editor |

---

## Versions-Hinweis

Das Repo läuft noch auf **Laravel 12.22.1** — v2 zielt auf **Laravel 13**.  
React 19.2.6 (latest stable), TypeScript 5.9, Vite 7 — alles aktuell.  
Playwright wird als `devDependency` + `dependency` (für den Node-Runner) hinzugefügt.

---

## Git-Workflow

```
main      → stabiler Stand, wird nicht direkt angefasst
refactor  → v2-Entwicklung, eine Phase = ein Commit
```

Commit-Format:
```
Phase 1: Python Sidecar als photobook_ai Package
Phase 2: DB Migration photo_features v2
Phase 3: ImageCacheService extrahieren
Phase 5: RenderSpec DTO
Phase 6: CostMatrixBuilder + HungarianSolver
Phase 4: ExtractPhotoFeaturesJob
Phase 8: React Editor RenderSpec-Integration
Phase 7: Playwright PDF-Export
```
