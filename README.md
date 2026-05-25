# map-directory

A vanilla PHP script that fetches Ontario lake polygons from OpenStreetMap's Overpass API and outputs them as GeoJSON.

## Setup

1. Copy `.env.sample` to `.env` if you want to change any settings.
2. Run the fetch script:

```sh
php osm_lakes.php
```

The output includes all Ontario lakes that match the Overpass query, with standalone ways emitted as polygons and lake relations merged into full polygons or multipolygons.

## Environment Variables

```sh
OVERPASS_URL=https://overpass-api.de/api/interpreter
OVERPASS_TIMEOUT=600
OVERPASS_AGENT=map-directory-lake-fetcher/1.0
```

## Cron

Run the job every day at 2:00 AM:

```cron
0 2 * * * cd /Users/thomasa/Desktop/CodeAdam/map-directory && /usr/bin/env php osm_lakes.php >> /Users/thomasa/Desktop/CodeAdam/map-directory/osm_lakes.log 2>&1
```
