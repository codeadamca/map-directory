<?php

require_once __DIR__ . '/includes/config.php';

function writeMessage(string $message, bool $isError = false): void
{
	static $streams = [];

	$key = $isError ? 'error' : 'output';

	if (!isset($streams[$key])) {
		if (PHP_SAPI === 'cli') {
			$streams[$key] = fopen($isError ? 'php://stderr' : 'php://stdout', 'wb');
		} else {
			$streams[$key] = fopen('php://output', 'wb');
		}
	}

	if (is_resource($streams[$key])) {
		fwrite($streams[$key], $message);
		return;
	}

	echo $message;
}

function writeProgress(string $message): void
{
	if (PHP_SAPI !== 'cli') {
		return;
	}

	writeMessage($message, true);
}

function fail(string $message): void
{
	throw new RuntimeException($message);
}

function requireDatabaseConnection(mixed $connection): mysqli
{
	if ($connection instanceof mysqli) {
		return $connection;
	}

	$message = function_exists('mysqli_connect_error') ? mysqli_connect_error() : 'Unknown database connection error.';
	fail("Unable to connect to the database: {$message}\n");
}

function normalizeRegionAlias(?string $value): ?string
{
	if ($value === null) {
		return null;
	}

	$value = trim(mb_strtolower($value));

	return $value === '' ? null : $value;
}

function appendLeafRegions(array $regions, array &$leafRegions, array $ancestors = []): void
{
	foreach ($regions as $key => $region) {
		$children = $region['children'] ?? [];
		$currentAncestors = [...$ancestors, ['key' => $key, 'region' => $region]];

		if (is_array($children) && $children !== []) {
			appendLeafRegions($children, $leafRegions, $currentAncestors);
			continue;
		}

		if (($region['type'] ?? null) === 'country' || !isset($region['bbox']) || !is_array($region['bbox'])) {
			continue;
		}

		$aliases = [];

		foreach ($currentAncestors as $ancestor) {
			foreach ([
				$ancestor['key'] ?? null,
				$ancestor['region']['name'] ?? null,
				$ancestor['region']['code'] ?? null,
			] as $candidate) {
				$alias = normalizeRegionAlias(is_string($candidate) ? $candidate : null);

				if ($alias !== null) {
					$aliases[$alias] = true;
				}
			}
		}

		$provinceAncestor = null;
		$countryAncestor = null;

		foreach (array_reverse($currentAncestors) as $ancestor) {
			$type = $ancestor['region']['type'] ?? null;

			if ($provinceAncestor === null && in_array($type, ['province', 'territory'], true)) {
				$provinceAncestor = $ancestor;
			}

			if ($countryAncestor === null && $type === 'country') {
				$countryAncestor = $ancestor;
			}
		}

		$region['key'] = $key;
		$region['aliases'] = array_keys($aliases);
		$region['province_key'] = $provinceAncestor['key'] ?? $key;
		$region['province_name'] = $provinceAncestor['region']['name'] ?? ($region['name'] ?? $key);
		$region['province_code'] = $provinceAncestor['region']['code'] ?? ($region['code'] ?? null);
		$region['country_key'] = $countryAncestor['key'] ?? 'canada';
		$region['country_name'] = $countryAncestor['region']['name'] ?? 'Canada';
		$region['country_code'] = $countryAncestor['region']['code'] ?? 'CA';
		$leafRegions[] = $region;
	}
}

function getLeafRegions(array $regions): array
{
	$leafRegions = [];
	appendLeafRegions($regions, $leafRegions);
	return $leafRegions;
}

function getLakeTimestampColumn(mysqli $database): string
{
	$result = $database->query("SHOW COLUMNS FROM lakes");
	$columns = [];

	while ($row = $result->fetch_assoc()) {
		$columns[$row['Field']] = true;
	}

	if (isset($columns['updated_at'])) {
		return 'updated_at';
	}

	if (isset($columns['created_at'])) {
		return 'created_at';
	}

	fail("The lakes table must have either an updated_at or created_at column.\n");
}

function getLastLakeRegion(mysqli $database): ?string
{
	$timestampColumn = getLakeTimestampColumn($database);
	$result = $database->query(
		"SELECT region FROM lakes WHERE region IS NOT NULL AND region <> '' ORDER BY {$timestampColumn} DESC, id DESC LIMIT 1"
	);
	$row = $result->fetch_assoc();

	return $row['region'] ?? null;
}

function getNextRegion(array $regions, ?string $lastRegionIdentifier): array
{
	if ($regions === []) {
		fail('No queryable regions are configured.');
	}

	$lastRegionAlias = normalizeRegionAlias($lastRegionIdentifier);

	if ($lastRegionAlias === null) {
		return $regions[0];
	}

	$lastMatchingIndex = null;

	foreach ($regions as $index => $region) {
		if (!in_array($lastRegionAlias, $region['aliases'] ?? [], true)) {
			continue;
		}

		$lastMatchingIndex = $index;
	}

	if ($lastMatchingIndex !== null) {
		$nextIndex = ($lastMatchingIndex + 1) % count($regions);
		return $regions[$nextIndex];
	}

	return $regions[0];
}

function getBboxValues(array $bbox): array
{
	$requiredKeys = ['south', 'west', 'north', 'east'];

	foreach ($requiredKeys as $key) {
		if (!array_key_exists($key, $bbox)) {
			fail("Region bounding box is missing {$key}.\n");
		}
	}

	return [
		(float) $bbox['south'],
		(float) $bbox['west'],
		(float) $bbox['north'],
		(float) $bbox['east'],
	];
}

function buildOverpassQuery(array $region, int $overpassTimeout): string
{
	[$south, $west, $north, $east] = getBboxValues($region['bbox']);
	$bbox = sprintf('(%.6F,%.6F,%.6F,%.6F)', $south, $west, $north, $east);

	return <<<OVERPASS
[out:json][timeout:{$overpassTimeout}];
(
	relation["natural"="water"]["water"="lake"]["name"]{$bbox};
	relation["water"="lake"]["name"]{$bbox};
)->.lakeRelations;
(
	way["natural"="water"]["water"="lake"]["name"]{$bbox};
	way["water"="lake"]["name"]{$bbox};
)->.candidateLakeWays;
way(r.lakeRelations)->.relationLakeWays;
(
	.candidateLakeWays;
	- .relationLakeWays;
)->.standaloneLakeWays;
(
	.lakeRelations;
	.standaloneLakeWays;
);
out tags center;
OVERPASS;
}

function buildSlug(string $value, string $fallback): string
{
	$slug = mb_strtolower($value);
	$slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
	$slug = trim($slug, '-');

	return $slug !== '' ? $slug : $fallback;
}

function getFeatureDisplayName(array $feature): string
{
	$name = trim((string) ($feature['name'] ?? ''));

	if ($name !== '') {
		return $name;
	}

	return sprintf('OSM %s %s', $feature['osm_type'] ?? 'feature', $feature['osm_id'] ?? 'unknown');
}

function getElementCenter(array $element): ?array
{
	$center = $element['center'] ?? [];

	if (!isset($center['lat'], $center['lon'])) {
		return null;
	}

	return [
		'latitude' => (float) $center['lat'],
		'longitude' => (float) $center['lon'],
	];
}

function ensureCountry(mysqli $database, string $name, string $code): int
{
	$select = $database->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
	$select->bind_param('s', $code);
	$select->execute();
	$result = $select->get_result();
	$row = $result->fetch_assoc();

	if ($row !== null) {
		$countryId = (int) $row['id'];
		$update = $database->prepare('UPDATE countries SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
		$update->bind_param('si', $name, $countryId);
		$update->execute();
		return $countryId;
	}

	$insert = $database->prepare('INSERT INTO countries (name, code) VALUES (?, ?)');
	$insert->bind_param('ss', $name, $code);
	$insert->execute();

	return (int) $database->insert_id;
}

function ensureProvince(mysqli $database, int $countryId, string $name, string $code): int
{
	$select = $database->prepare('SELECT id FROM provinces WHERE code = ? LIMIT 1');
	$select->bind_param('s', $code);
	$select->execute();
	$result = $select->get_result();
	$row = $result->fetch_assoc();

	if ($row !== null) {
		$provinceId = (int) $row['id'];
		$update = $database->prepare('UPDATE provinces SET country_id = ?, name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
		$update->bind_param('isi', $countryId, $name, $provinceId);
		$update->execute();
		return $provinceId;
	}

	$insert = $database->prepare('INSERT INTO provinces (country_id, name, code) VALUES (?, ?, ?)');
	$insert->bind_param('iss', $countryId, $name, $code);
	$insert->execute();

	return (int) $database->insert_id;
}

function upsertLake(mysqli $database, array $feature, array $region, int $provinceId, int $countryId): int
{
	$osmType = (string) ($feature['osm_type'] ?? '');
	$osmId = (int) ($feature['osm_id'] ?? 0);
	$regionKey = (string) ($region['key'] ?? '');
	$name = getFeatureDisplayName($feature);
	$slug = buildSlug($name, sprintf('%s-%d', $osmType !== '' ? $osmType : 'lake', $osmId));
	$center = $feature['center'] ?? null;
	$tier = 3;

	$select = $database->prepare('SELECT id FROM lakes WHERE osm_type = ? AND osm_id = ? LIMIT 1');
	$select->bind_param('si', $osmType, $osmId);
	$select->execute();
	$result = $select->get_result();
	$row = $result->fetch_assoc();

	if ($row !== null) {
		$lakeId = (int) $row['id'];
		$update = $database->prepare(
			'UPDATE lakes SET name = ?, slug = ?, region = ?, tier = ?, province_id = ?, country_id = ?, latitude = ?, longitude = ?, area_km2 = NULL, notes = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
		);
		$update->bind_param(
			'sssiiiddi',
			$name,
			$slug,
			$regionKey,
			$tier,
			$provinceId,
			$countryId,
			$center['latitude'],
			$center['longitude'],
			$lakeId
		);
		$update->execute();

		return $lakeId;
	}

	$insert = $database->prepare(
		'INSERT INTO lakes (name, slug, region, tier, province_id, country_id, latitude, longitude, area_km2, notes, osm_type, osm_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)'
	);
	$insert->bind_param(
		'sssiiiddsi',
		$name,
		$slug,
		$regionKey,
		$tier,
		$provinceId,
		$countryId,
		$center['latitude'],
		$center['longitude'],
		$osmType,
		$osmId
	);
	$insert->execute();

	return (int) $database->insert_id;
}

$database = null;
$duration = 0.0;
$transactionStarted = false;

try {
	$required = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'OVERPASS_URL', 'OVERPASS_TIMEOUT', 'OVERPASS_AGENT'];

	foreach ($required as $constant) {
		if (!defined($constant) || constant($constant) === '') {
			fail("Missing required env value: {$constant}\n");
		}
	}

	$requestTimeout = (int) OVERPASS_TIMEOUT;

	if ($requestTimeout < 1) {
		fail("OVERPASS_TIMEOUT must be greater than 0.\n");
	}

	$regions = require __DIR__ . '/includes/regions.php';
	$leafRegions = getLeafRegions($regions);
	$database = requireDatabaseConnection($connect ?? null);

	$lastRegion = getLastLakeRegion($database);
	$region = getNextRegion($leafRegions, $lastRegion);
	writeProgress(
		sprintf(
			"Last imported region: %s\n",
			$lastRegion ?? 'none'
		)
	);
	writeProgress(
		sprintf(
			"Next region to import: %s (%s)\n",
			(string) ($region['name'] ?? $region['key'] ?? 'unknown'),
			(string) ($region['province_name'] ?? $region['province_code'] ?? 'unknown')
		)
	);
	$overpassTimeout = max(30, min($requestTimeout, 180));
	$query = buildOverpassQuery($region, $overpassTimeout);

	$context = stream_context_create([
		'http' => [
			'method' => 'POST',
			'header' => implode("\r\n", [
				'Content-Type: text/plain; charset=utf-8',
				'User-Agent: ' . OVERPASS_AGENT,
			]),
			'content' => $query,
			'timeout' => $requestTimeout,
			'ignore_errors' => true,
		],
	]);

	$startedAt = microtime(true);
	$response = @file_get_contents(OVERPASS_URL, false, $context);
	$duration = microtime(true) - $startedAt;

	if ($response === false) {
		$error = error_get_last();
		$message = $error['message'] ?? 'Unknown request failure.';
		fail(sprintf("Request failed after %.2f seconds: %s\n", $duration, $message));
	}

	$statusLine = $http_response_header[0] ?? '';

	if (!str_contains($statusLine, '200')) {
		fail(sprintf("Unexpected HTTP response after %.2f seconds: %s\n%s\n", $duration, $statusLine, $response));
	}

	$payload = json_decode($response, true);

	if (!is_array($payload) || !isset($payload['elements']) || !is_array($payload['elements'])) {
		fail("Unexpected API payload.\n");
	}

	$lakes = [];

	foreach ($payload['elements'] as $element) {
		$tags = $element['tags'] ?? [];
		$elementType = $element['type'] ?? null;
		$elementId = (int) ($element['id'] ?? 0);
		$center = getElementCenter($element);

		if (!in_array($elementType, ['way', 'relation'], true) || $center === null) {
			continue;
		}

		$lakes[] = [
			'osm_id' => $elementId,
			'osm_type' => $elementType,
			'name' => $tags['name'] ?? null,
			'tags' => $tags,
			'center' => $center,
		];
	}

	usort(
		$lakes,
		static function (array $left, array $right): int {
			$nameComparison = strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));

			if ($nameComparison !== 0) {
				return $nameComparison;
			}

			return strcmp(
				sprintf('%s/%d', (string) ($left['osm_type'] ?? ''), (int) ($left['osm_id'] ?? 0)),
				sprintf('%s/%d', (string) ($right['osm_type'] ?? ''), (int) ($right['osm_id'] ?? 0))
			);
		}
	);

	$database->begin_transaction();
	$transactionStarted = true;
	$countryId = ensureCountry(
		$database,
		(string) ($region['country_name'] ?? 'Canada'),
		(string) ($region['country_code'] ?? 'CA')
	);
	$provinceId = ensureProvince(
		$database,
		$countryId,
		(string) ($region['province_name'] ?? $region['name'] ?? 'Unknown'),
		(string) ($region['province_code'] ?? $region['code'] ?? 'UNK')
	);
	writeProgress(
		sprintf(
			"Processing province %s, region %s (%d lakes)\n",
			(string) ($region['province_name'] ?? $region['name'] ?? 'Unknown'),
			(string) ($region['name'] ?? $region['key'] ?? 'unknown'),
			count($lakes)
		)
	);

	$insertedCount = 0;
	$updatedCount = 0;
	$skippedCount = 0;

	foreach ($lakes as $lake) {
		$osmType = (string) ($lake['osm_type'] ?? '');
		$osmId = (int) ($lake['osm_id'] ?? 0);

		if (($lake['center'] ?? null) === null) {
			$skippedCount++;
			writeProgress(
				sprintf(
					"[%s] skipped %s (%s/%d) because no center was returned\n",
					(string) ($region['province_code'] ?? $region['code'] ?? 'UNK'),
					getFeatureDisplayName($lake),
					$osmType,
					$osmId
				)
			);
			continue;
		}

		$existing = $database->prepare('SELECT id FROM lakes WHERE osm_type = ? AND osm_id = ? LIMIT 1');
		$existing->bind_param('si', $osmType, $osmId);
		$existing->execute();
		$existingRow = $existing->get_result()->fetch_assoc();

		$lakeId = upsertLake($database, $lake, $region, $provinceId, $countryId);
		$lakeName = getFeatureDisplayName($lake);

		if ($existingRow === null) {
			$insertedCount++;
			writeProgress(
				sprintf(
					"[%s] inserted %s (%s/%d)\n",
					(string) ($region['province_code'] ?? $region['code'] ?? 'UNK'),
					$lakeName,
					$osmType,
					$osmId
				)
			);
		} else {
			$updatedCount++;
			writeProgress(
				sprintf(
					"[%s] updated %s (%s/%d)\n",
					(string) ($region['province_code'] ?? $region['code'] ?? 'UNK'),
					$lakeName,
					$osmType,
					$osmId
				)
			);
		}
	}

	$database->commit();
	$transactionStarted = false;
	writeProgress(
		sprintf(
			"Completed province %s, region %s: %d inserted, %d updated, %d skipped\n",
			(string) ($region['province_name'] ?? $region['name'] ?? 'Unknown'),
			(string) ($region['name'] ?? $region['key'] ?? 'unknown'),
			$insertedCount,
			$updatedCount,
			$skippedCount
		)
	);

	$output = [
		'type' => 'LakeImportSummary',
		'metadata' => [
			'region' => $region['name'],
			'region_key' => $region['key'],
			'region_code' => $region['code'] ?? null,
			'province' => $region['province_name'] ?? null,
			'fetched_at' => gmdate('c'),
			'source' => OVERPASS_URL,
			'total' => count($lakes),
			'inserted' => $insertedCount,
			'updated' => $updatedCount,
			'skipped' => $skippedCount,
		],
		'lakes' => $lakes,
	];

	$encoded = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	if ($encoded === false) {
		fail("Unable to encode output JSON.\n");
	}

	if (PHP_SAPI !== 'cli' && !headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
	}

	writeMessage($encoded . PHP_EOL);
} catch (Throwable $exception) {
	if ($database instanceof mysqli && $transactionStarted) {
		try {
			$database->rollback();
		} catch (Throwable) {
		}
	}

	writeMessage($exception->getMessage(), true);
	exit(1);
}
