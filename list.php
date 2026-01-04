<?php
/* ============================================================
   PIPII QUICKADD — WIZARD (LOCKED SCHEMA SOURCE OF TRUTH)
   MySQL 5.7.39 / utf8mb4 / InnoDB
   ------------------------------------------------------------
   What you asked for (implemented):
   1) Every selectable “place” has an inline “+ Add” for THAT place:
      - Towns (towns)
      - Makes (vehicle_makes)
      - Models (vehicle_models per make)
      - Allowed years (vehicle_model_years per model)
      - Allowed bodies (vehicle_model_bodies per model)
      - Features options (settings[pipii_features])
      - Yards (car_yards per dealer) + Add yard
   2) Anything added is saved in DB and forced to Title Case (first letters capital).
   3) Balanced UI/UX: a guided “flash question” wizard with fade transitions:
      - Tap pills instead of typing wherever possible
      - Each step appears one at a time
      - End: Review card + “List now”
   4) Pills have duotone-style SVG icons + deterministic pastel solid backgrounds.
   5) JSON-safe endpoints (no HTML/warnings leaking into fetch JSON):
      - output buffering + shutdown handler
      - clean JSON for all ?a= endpoints
   ------------------------------------------------------------
   NOTE:
   - Fuel type and transmission are enums in schema, so they are pill options
     (not DB-addable). Same for condition_type.
   - “sales_agent_id NOT NULL” in car_yards:
     this quick-add bootstraps sales_agent_id = dealer_id and created_by = dealer_id.
     Replace in production when you wire real agents.
   ============================================================ */

declare(strict_types=1);

/* =========================
   0) CONFIG (EDIT)
   ========================= */
$dbHost = "localhost";
$dbName = "car";
$dbUser = "root";
$dbPass = "root";
$dbCharset = "utf8mb4";

/** Upload paths */
$uploadBaseDir = __DIR__ . "/uploads";
$uploadListingsDir = $uploadBaseDir . "/listings";
$uploadUrlPrefix = "/uploads/listings";

/* =========================
   1) HARDEN OUTPUT (JSON SAFETY)
   ========================= */
date_default_timezone_set("Africa/Nairobi");
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$action = $_GET['a'] ?? '';
$isApi = ($action !== '');

ob_start();
register_shutdown_function(function () use (&$isApi) {
  $err = error_get_last();
  if (!$err) return;

  if ($isApi) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
      "ok" => false,
      "error" => "Fatal error: " . ($err['message'] ?? 'unknown'),
    ], JSON_UNESCAPED_UNICODE);
    return;
  }
});

/* =========================
   2) FILE HELPERS
   ========================= */
function ensure_dir(string $path): void {
  if (is_dir($path)) return;
  @mkdir($path, 0775, true);
  $idx = rtrim($path, "/") . "/index.html";
  if (!file_exists($idx)) @file_put_contents($idx, "");
}
ensure_dir($uploadBaseDir);
ensure_dir($uploadListingsDir);

/* =========================
   3) DB + HELPERS (SCHEMA-TRUTH)
   ========================= */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  global $dbHost,$dbName,$dbUser,$dbPass,$dbCharset;
  $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
  $pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}

function now_utc(): string {
  $dt = new DateTime("now", new DateTimeZone("UTC"));
  return $dt->format("Y-m-d H:i:s");
}
function add_days_utc(int $days): string {
  $dt = new DateTime("now", new DateTimeZone("UTC"));
  $dt->modify("+{$days} days");
  return $dt->format("Y-m-d H:i:s");
}

function clean_str($v, int $max=255): string {
  $v = trim((string)$v);
  $v = preg_replace('/\s+/', ' ', $v ?? '');
  if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
  return (string)$v;
}

function normalize_ke_phone(string $input): string {
  $raw = trim($input);
  if ($raw === '') return '';

  $compact = preg_replace('/\s+/', '', $raw);
  if (preg_match('/^\+254(7|1)\d{8}$/', $compact)) {
    return $compact;
  }

  $digits = preg_replace('/\D+/', '', $compact);
  if ($digits === '') return '';

  // strip country code if present
  if (strpos($digits, '254') === 0 && strlen($digits) >= 12) {
    $digits = substr($digits, 3);
  }
  // strip leading zero for local 07/01 formats
  if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
    $digits = substr($digits, 1);
  }

  if (preg_match('/^(7|1)\d{8}$/', $digits)) {
    return '+254' . $digits;
  }
  return '';
}

function intv($v, ?int $min=null, ?int $max=null): ?int {
  if ($v === null || $v === '') return null;
  if (!is_numeric($v)) return null;
  $n = (int)$v;
  if ($min !== null && $n < $min) return null;
  if ($max !== null && $n > $max) return null;
  return $n;
}

function bool01($v): int {
  return ((string)$v === "1" || $v === 1 || $v === true) ? 1 : 0;
}

function json_out($data, int $code=200): void {
  while (ob_get_level()) ob_end_clean();
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function is_post(): bool {
  return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Title Case (first letters capital) — with basic separators.
 * Examples:
 *  "toyota" => "Toyota"
 *  "land cruiser prado" => "Land Cruiser Prado"
 *  "nairobi west" => "Nairobi West"
 */
function title_case(string $s): string {
  $s = trim(preg_replace('/\s+/', ' ', $s));
  $s = mb_strtolower($s, 'UTF-8');

  // split by space but keep hyphen/apostrophe handling inside words
  $words = preg_split('/\s+/', $s) ?: [];
  $out = [];

  foreach ($words as $w) {
    if ($w === '') continue;

    // handle hyphenated parts: "x-trail" => "X-Trail"
    $parts = explode('-', $w);
    $parts2 = [];
    foreach ($parts as $p) {
      if ($p === '') continue;

      // handle apostrophe: "d'max" => "D'Max"
      $aposParts = explode("'", $p);
      $aposOut = [];
      foreach ($aposParts as $ap) {
        if ($ap === '') { $aposOut[] = ''; continue; }
        $first = mb_substr($ap, 0, 1, 'UTF-8');
        $rest  = mb_substr($ap, 1, null, 'UTF-8');
        $aposOut[] = mb_strtoupper($first, 'UTF-8') . $rest;
      }
      $parts2[] = implode("'", $aposOut);
    }
    $out[] = implode('-', $parts2);
  }
  return implode(' ', $out);
}

/* =========================
   4) SETTINGS + EVENTS (SCHEMA TRUTH)
   ========================= */
function setting_get(string $key, $default=null) {
  $pdo = db();
  $st = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
  $st->execute([$key]);
  $row = $st->fetch();
  return $row ? $row["value"] : $default;
}
function setting_exists(string $key): bool {
  $pdo = db();
  $st = $pdo->prepare("SELECT 1 FROM settings WHERE `key`=? LIMIT 1");
  $st->execute([$key]);
  return (bool)$st->fetchColumn();
}
function setting_set(string $key, string $value): void {
  $pdo = db();
  $st = $pdo->prepare("
    INSERT INTO settings(`key`,`value`)
    VALUES(?,?)
    ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)
  ");
  $st->execute([$key, $value]);
}
function event_log(string $type, array $ids=[], array $meta=[]): void {
  $pdo = db();
  $st = $pdo->prepare("
    INSERT INTO events(type, listing_id, dealer_id, sales_agent_id, payment_id, meta, created_at)
    VALUES(?,?,?,?,?,?,?)
  ");
  $st->execute([
    $type,
    $ids["listing_id"] ?? null,
    $ids["dealer_id"] ?? null,
    $ids["sales_agent_id"] ?? null,
    $ids["payment_id"] ?? null,
    $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
    now_utc()
  ]);
}

/* =========================
   5) ONE-TIME SEED (FEATURES)
   ========================= */
function seed_features_once(): void {
  if (setting_exists('pipii_features')) return;

  $seed = [
    ["tag"=>"abs","label"=>"ABS Brakes"],
    ["tag"=>"airbags","label"=>"Airbags"],
    ["tag"=>"alarm","label"=>"Alarm"],
    ["tag"=>"alloy_wheels","label"=>"Alloy Wheels"],
    ["tag"=>"android_auto","label"=>"Android Auto"],
    ["tag"=>"apple_carplay","label"=>"Apple CarPlay"],
    ["tag"=>"auto_headlights","label"=>"Auto Headlights"],
    ["tag"=>"backup_camera","label"=>"Backup Camera"],
    ["tag"=>"bluetooth","label"=>"Bluetooth"],
    ["tag"=>"bull_bar","label"=>"Bull Bar"],
    ["tag"=>"central_locking","label"=>"Central Locking"],
    ["tag"=>"cruise_control","label"=>"Cruise Control"],
    ["tag"=>"daytime_running_lights","label"=>"DRL"],
    ["tag"=>"electric_windows","label"=>"Electric Windows"],
    ["tag"=>"fog_lights","label"=>"Fog Lights"],
    ["tag"=>"immobilizer","label"=>"Immobilizer"],
    ["tag"=>"keyless_entry","label"=>"Keyless Entry"],
    ["tag"=>"lane_assist","label"=>"Lane Assist"],
    ["tag"=>"leather_seats","label"=>"Leather Seats"],
    ["tag"=>"led_headlights","label"=>"LED Headlights"],
    ["tag"=>"parking_sensors","label"=>"Parking Sensors"],
    ["tag"=>"power_steering","label"=>"Power Steering"],
    ["tag"=>"push_start","label"=>"Push Start"],
    ["tag"=>"roof_rack","label"=>"Roof Rack"],
    ["tag"=>"sunroof","label"=>"Sunroof"],
    ["tag"=>"touchscreen","label"=>"Touchscreen"],
    ["tag"=>"traction_control","label"=>"Traction Control"],
    ["tag"=>"usb_ports","label"=>"USB Ports"],
  ];
  setting_set('pipii_features', json_encode($seed, JSON_UNESCAPED_UNICODE));
}

try {
  db();
  seed_features_once();
} catch (Throwable $e) {
  if ($isApi) json_out(["ok"=>false,"error"=>"DB connection failed"], 500);
}

/* ============================================================
   6) API ENDPOINTS
   ============================================================ */

/* ---------- Reference loads ---------- */
if ($action === "towns") {
  try{
    $st = db()->query("SELECT id, name FROM towns WHERE is_active=1 ORDER BY name ASC");
    json_out(["ok"=>true, "towns"=>$st->fetchAll()]);
  }catch(Throwable $e){
    json_out(["ok"=>false,"error"=>$e->getMessage()], 500);
  }
}
if ($action === "makes") {
  $st = db()->query("SELECT id, name FROM vehicle_makes WHERE is_active=1 ORDER BY name ASC");
  json_out(["ok"=>true, "makes"=>$st->fetchAll()]);
}
if ($action === "models") {
  $makeId = (int)($_GET["make_id"] ?? 0);
  if ($makeId <= 0) json_out(["ok"=>false,"error"=>"make_id required"], 422);
  $st = db()->prepare("SELECT id, name FROM vehicle_models WHERE make_id=? AND is_active=1 ORDER BY name ASC");
  $st->execute([$makeId]);
  json_out(["ok"=>true, "models"=>$st->fetchAll()]);
}
if ($action === "model_years") {
  $modelId = (int)($_GET["model_id"] ?? 0);
  if ($modelId <= 0) json_out(["ok"=>false,"error"=>"model_id required"], 422);
  $st = db()->prepare("SELECT year FROM vehicle_model_years WHERE model_id=? ORDER BY year ASC");
  $st->execute([$modelId]);
  $years = array_map("intval", array_column($st->fetchAll(), "year"));
  json_out(["ok"=>true, "years"=>$years]);
}
if ($action === "model_bodies") {
  $modelId = (int)($_GET["model_id"] ?? 0);
  if ($modelId <= 0) json_out(["ok"=>false,"error"=>"model_id required"], 422);
  $st = db()->prepare("SELECT body_type FROM vehicle_model_bodies WHERE model_id=? ORDER BY body_type ASC");
  $st->execute([$modelId]);
  $bodies = array_column($st->fetchAll(), "body_type");
  json_out(["ok"=>true, "bodies"=>$bodies]);
}
if ($action === "options_get") {
  $featuresJson = setting_get("pipii_features", "[]");
  $featuresArr = json_decode((string)$featuresJson, true);
  if (!is_array($featuresArr)) $featuresArr = [];

  json_out(["ok"=>true, "features"=>$featuresArr]);
}

/* ---------- DB adds (Title Case enforced) ---------- */
if ($action === "town_add") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);
  $name = title_case(clean_str($_POST["name"] ?? "", 120));
  if ($name === "") json_out(["ok"=>false,"error"=>"Town name required"], 422);

  $pdo = db();
  try {
    $st = $pdo->prepare("INSERT INTO towns(name, county, country_code, is_active, created_at) VALUES(?,?,?,?,?)");
    $st->execute([$name, null, "KE", 1, now_utc()]);
    json_out(["ok"=>true, "id"=>(int)$pdo->lastInsertId(), "name"=>$name]);
  } catch (Throwable $e) {
    // try fetch existing
    $st2 = $pdo->prepare("SELECT id,name FROM towns WHERE name=? AND country_code='KE' LIMIT 1");
    $st2->execute([$name]);
    $row = $st2->fetch();
    if ($row) json_out(["ok"=>true, "id"=>(int)$row["id"], "name"=>$row["name"], "exists"=>true]);
    json_out(["ok"=>false,"error"=>"Unable to add town"], 409);
  }
}

if ($action === "make_add") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);
  $name = title_case(clean_str($_POST["name"] ?? "", 80));
  if ($name === "") json_out(["ok"=>false,"error"=>"Make name required"], 422);

  $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', preg_replace('/\s+/', ' ', trim($name))));
  $slug = trim($slug, '-');
  if ($slug === "") $slug = 'make-' . bin2hex(random_bytes(4));

  $pdo = db();
  try {
    $st = $pdo->prepare("INSERT INTO vehicle_makes(name, slug, is_active, created_at) VALUES(?,?,1,?)");
    $st->execute([$name, $slug, now_utc()]);
    json_out(["ok"=>true, "id"=>(int)$pdo->lastInsertId(), "name"=>$name]);
  } catch (Throwable $e) {
    $st2 = $pdo->prepare("SELECT id,name FROM vehicle_makes WHERE name=? LIMIT 1");
    $st2->execute([$name]);
    $row = $st2->fetch();
    if ($row) json_out(["ok"=>true, "id"=>(int)$row["id"], "name"=>$row["name"], "exists"=>true]);
    json_out(["ok"=>false,"error"=>"Unable to add make"], 409);
  }
}

if ($action === "model_add") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);
  $makeId = (int)($_POST["make_id"] ?? 0);
  if ($makeId <= 0) json_out(["ok"=>false,"error"=>"make_id required"], 422);

  $name = title_case(clean_str($_POST["name"] ?? "", 80));
  if ($name === "") json_out(["ok"=>false,"error"=>"Model name required"], 422);

  // slug: make-slug + model-name
  $pdo = db();
  $mk = $pdo->prepare("SELECT slug FROM vehicle_makes WHERE id=? LIMIT 1");
  $mk->execute([$makeId]);
  $mrow = $mk->fetch();
  if (!$mrow) json_out(["ok"=>false,"error"=>"Invalid make_id"], 404);

  $makeSlug = (string)$mrow["slug"];
  $modelSlugPart = strtolower(preg_replace('/[^a-z0-9]+/', '-', preg_replace('/\s+/', ' ', trim($name))));
  $modelSlugPart = trim($modelSlugPart, '-');
  $slug = trim($makeSlug . "-" . $modelSlugPart, '-');
  if ($slug === "") $slug = 'model-' . bin2hex(random_bytes(4));

  try {
    $st = $pdo->prepare("INSERT INTO vehicle_models(make_id, name, slug, is_active, created_at) VALUES(?,?,?,1,?)");
    $st->execute([$makeId, $name, $slug, now_utc()]);
    json_out(["ok"=>true, "id"=>(int)$pdo->lastInsertId(), "name"=>$name]);
  } catch (Throwable $e) {
    $st2 = $pdo->prepare("SELECT id,name FROM vehicle_models WHERE make_id=? AND name=? LIMIT 1");
    $st2->execute([$makeId, $name]);
    $row = $st2->fetch();
    if ($row) json_out(["ok"=>true, "id"=>(int)$row["id"], "name"=>$row["name"], "exists"=>true]);
    json_out(["ok"=>false,"error"=>"Unable to add model"], 409);
  }
}

if ($action === "model_year_add") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);
  $modelId = (int)($_POST["model_id"] ?? 0);
  $year = intv($_POST["year"] ?? null, 1900, 2100);
  if ($modelId <= 0 || !$year) json_out(["ok"=>false,"error"=>"model_id and valid year required"], 422);

  $pdo = db();
  try {
    $st = $pdo->prepare("INSERT INTO vehicle_model_years(model_id, year) VALUES(?,?)");
    $st->execute([$modelId, $year]);
    json_out(["ok"=>true, "year"=>$year]);
  } catch (Throwable $e) {
    json_out(["ok"=>true, "year"=>$year, "exists"=>true]); // idempotent UX
  }
}

if ($action === "model_body_add") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);
  $modelId = (int)($_POST["model_id"] ?? 0);
  $body = title_case(clean_str($_POST["body_type"] ?? "", 40));
  if ($modelId <= 0 || $body === "") json_out(["ok"=>false,"error"=>"model_id and body_type required"], 422);

  $pdo = db();
  try {
    $st = $pdo->prepare("INSERT INTO vehicle_model_bodies(model_id, body_type) VALUES(?,?)");
    $st->execute([$modelId, $body]);
    json_out(["ok"=>true, "body_type"=>$body]);
  } catch (Throwable $e) {
    json_out(["ok"=>true, "body_type"=>$body, "exists"=>true]); // idempotent UX
  }
}

if ($action === "feature_add") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);

  $tag = clean_str($_POST["tag"] ?? "", 80);
  $label = title_case(clean_str($_POST["label"] ?? "", 80));
  if ($tag === "" && $label === "") json_out(["ok"=>false,"error"=>"Provide tag or label"], 422);

  if ($tag === "") {
    $tag = strtolower(preg_replace('/\s+/', '_', trim($label)));
  }
  $tag = strtolower(preg_replace('/\s+/', '_', $tag));
  $tag = preg_replace('/[^a-z0-9_]+/', '', $tag);

  if ($label === "") $label = title_case(str_replace('_',' ', $tag));

  $featuresJson = setting_get("pipii_features", "[]");
  $arr = json_decode((string)$featuresJson, true);
  if (!is_array($arr)) $arr = [];

  foreach ($arr as $f) {
    if (($f["tag"] ?? "") === $tag) json_out(["ok"=>true, "tag"=>$tag, "label"=>$label, "exists"=>true]);
  }

  $arr[] = ["tag"=>$tag, "label"=>$label];
  setting_set("pipii_features", json_encode($arr, JSON_UNESCAPED_UNICODE));
  json_out(["ok"=>true, "tag"=>$tag, "label"=>$label]);
}

/* ---------- Dealer + Yards ---------- */
if ($action === "dealer_lookup") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);

  $phoneRaw = clean_str($_POST["phone"] ?? "", 32);
  $phone = normalize_ke_phone($phoneRaw);
  $name  = title_case(clean_str($_POST["name"] ?? "", 140));
  if ($phone === "") json_out(["ok"=>false,"error"=>"Enter a Kenyan phone (07 / 01 / +254 formats)"], 422);

  $pdo = db();
  $st = $pdo->prepare("SELECT id, full_name, phone_e164, role FROM users WHERE phone_e164=? LIMIT 1");
  $st->execute([$phone]);
  $u = $st->fetch();
  if ($u) {
    if (($u["role"] ?? "") !== "dealer") json_out(["ok"=>false,"error"=>"This phone belongs to a non-dealer role"], 409);
    json_out(["ok"=>true, "dealer"=>["id"=>$u["id"],"full_name"=>$u["full_name"],"phone_e164"=>$u["phone_e164"]], "created"=>false]);
  }

  if ($name === "") $name = "Dealer " . substr(preg_replace('/\D+/', '', $phone), -6);
  $passwordHash = password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT);

  $ins = $pdo->prepare("
    INSERT INTO users(role, created_by, full_name, phone_e164, email, password_hash, is_active, created_at)
    VALUES('dealer', NULL, ?, ?, NULL, ?, 1, ?)
  ");

  try {
    $ins->execute([$name, $phone, $passwordHash, now_utc()]);
  } catch (Throwable $e) {
    json_out(["ok"=>false,"error"=>"Failed to create dealer"], 409);
  }

  $dealerId = (int)$pdo->lastInsertId();
  event_log("dealer_created", ["dealer_id"=>$dealerId], ["phone"=>$phone]);

  json_out(["ok"=>true, "dealer"=>["id"=>$dealerId,"full_name"=>$name,"phone_e164"=>$phone], "created"=>true]);
}

if ($action === "yards") {
  $dealerId = (int)($_GET["dealer_id"] ?? 0);
  if ($dealerId <= 0) json_out(["ok"=>false,"error"=>"dealer_id required"], 422);

  $st = db()->prepare("
    SELECT y.id, y.yard_name, t.name AS town_name, y.town_id
    FROM car_yards y
    JOIN towns t ON t.id = y.town_id
    WHERE y.dealer_id=? AND y.is_active=1
    ORDER BY y.yard_name ASC
  ");
  $st->execute([$dealerId]);
  json_out(["ok"=>true, "yards"=>$st->fetchAll()]);
}

if ($action === "yard_add") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);

  $dealerId = (int)($_POST["dealer_id"] ?? 0);
  $yardName = title_case(clean_str($_POST["yard_name"] ?? "", 160));
  $townId   = (int)($_POST["town_id"] ?? 0);
  if ($dealerId<=0 || $yardName==="" || $townId<=0) json_out(["ok"=>false,"error"=>"dealer_id, yard_name, town_id required"], 422);

  // QuickAdd bootstrap:
  $salesAgentId = $dealerId;
  $createdBy = $dealerId;

  $pdo = db();
  try {
    $st = $pdo->prepare("
      INSERT INTO car_yards(dealer_id, sales_agent_id, created_by, yard_name, town_id, is_active, created_at)
      VALUES(?,?,?,?,?,1,?)
    ");
    $st->execute([$dealerId, $salesAgentId, $createdBy, $yardName, $townId, now_utc()]);
  } catch (Throwable $e) {
    // If already exists, return existing:
    $st2 = $pdo->prepare("SELECT id FROM car_yards WHERE dealer_id=? AND yard_name=? AND town_id=? LIMIT 1");
    $st2->execute([$dealerId, $yardName, $townId]);
    $row = $st2->fetch();
    if ($row) json_out(["ok"=>true, "id"=>(int)$row["id"], "yard_name"=>$yardName, "exists"=>true]);
    json_out(["ok"=>false,"error"=>"Failed to add yard"], 500);
  }

  $yardId = (int)$pdo->lastInsertId();
  event_log("yard_created", ["dealer_id"=>$dealerId], ["yard_id"=>$yardId, "yard_name"=>$yardName, "town_id"=>$townId]);
  json_out(["ok"=>true, "id"=>$yardId, "yard_name"=>$yardName]);
}

/* ---------- Create listing (schema truth) ---------- */
if ($action === "listing_create") {
  if (!is_post()) json_out(["ok"=>false,"error"=>"POST required"], 405);

  global $uploadListingsDir, $uploadUrlPrefix;

  $dealerId = (int)($_POST["dealer_id"] ?? 0);
  $yardId   = (int)($_POST["yard_id"] ?? 0);
  $townId   = (int)($_POST["town_id"] ?? 0);
  $vehicleModelId = (int)($_POST["vehicle_model_id"] ?? 0);

  $year = intv($_POST["year"] ?? null, 1900, 2100);
  $engineCc = intv($_POST["engine_cc"] ?? null, 1, 200000);
  $mileageKm = intv($_POST["mileage_km"] ?? null, 0, 300000000);
  $priceKes = intv($_POST["cash_price_kes"] ?? null, 1, 2000000000);

  $fuel = clean_str($_POST["fuel_type"] ?? "", 20);
  $trans = clean_str($_POST["transmission"] ?? "", 20);
  $bodyType = title_case(clean_str($_POST["body_type"] ?? "", 40));
  $color = title_case(clean_str($_POST["color"] ?? "", 60));

  $title = clean_str($_POST["title"] ?? "", 220);
  $trim  = title_case(clean_str($_POST["trim"] ?? "", 80));

  $desc = trim((string)($_POST["description"] ?? ""));
  if ($desc !== "" && mb_strlen($desc) > 65000) $desc = mb_substr($desc, 0, 65000);

  $condition = clean_str($_POST["condition_type"] ?? "used", 10);
  if (!in_array($condition, ["used","new"], true)) $condition = "used";

  $allowsCash = bool01($_POST["allows_cash"] ?? 1);
  $allowsHp   = bool01($_POST["allows_hp"] ?? 0);
  $allowsTrade= bool01($_POST["allows_trade_in"] ?? 0);
  $allowsExt  = bool01($_POST["allows_external_financing"] ?? 0);

  $featuresRaw = (string)($_POST["features"] ?? "[]");
  $featuresArr = json_decode($featuresRaw, true);
  if (!is_array($featuresArr)) $featuresArr = [];
  $featuresArr = array_values(array_unique(array_filter(array_map(
    fn($s)=>clean_str($s, 80),
    $featuresArr
  ), fn($s)=>$s!=="")));

  $expiryDays = intv($_POST["expiry_days"] ?? 30, 1, 365) ?? 30;
  $sponsorDays = intv($_POST["sponsor_days"] ?? 0, 0, 365) ?? 0;

  // HP terms
  $hpOn = bool01($_POST["hp_on"] ?? 0) === 1;
  $hpMinDeposit = intv($_POST["hp_min_deposit"] ?? null, 1, 2000000000);
  $hpMaxDeposit = intv($_POST["hp_max_deposit"] ?? null, 1, 2000000000);
  $hpMinMonths  = intv($_POST["hp_min_months"] ?? 3, 1, 120) ?? 3;
  $hpMaxMonths  = intv($_POST["hp_max_months"] ?? 60, 1, 120) ?? 60;
  $hpNotes      = clean_str($_POST["hp_notes"] ?? "", 255);

  // Required per schema
  if ($dealerId<=0) json_out(["ok"=>false,"error"=>"dealer_id required"], 422);
  if ($townId<=0) json_out(["ok"=>false,"error"=>"town_id required"], 422);
  if ($vehicleModelId<=0) json_out(["ok"=>false,"error"=>"vehicle_model_id required"], 422);
  if (!$year) json_out(["ok"=>false,"error"=>"year required"], 422);
  if (!$engineCc) json_out(["ok"=>false,"error"=>"engine_cc required"], 422);
  if (!$priceKes) json_out(["ok"=>false,"error"=>"cash_price_kes required"], 422);

  if ($hpOn && !$hpMinDeposit) json_out(["ok"=>false,"error"=>"HP enabled: min deposit required"], 422);
  if ($hpMinMonths > $hpMaxMonths) json_out(["ok"=>false,"error"=>"HP months invalid"], 422);
  if ($hpMaxDeposit !== null && $hpMaxDeposit < $hpMinDeposit) json_out(["ok"=>false,"error"=>"HP deposit range invalid"], 422);

  // enums
  $fuelOk = in_array($fuel, ["petrol","diesel","hybrid","electric","other",""], true);
  if (!$fuelOk) $fuel = "other";
  $transOk = in_array($trans, ["automatic","manual","other",""], true);
  if (!$transOk) $trans = "other";

  // Derive make/model from catalog (schema truth)
  $pdo = db();
  $st = $pdo->prepare("
    SELECT vm.id AS model_id, vm.name AS model_name, mk.name AS make_name
    FROM vehicle_models vm
    JOIN vehicle_makes mk ON mk.id = vm.make_id
    WHERE vm.id=? LIMIT 1
  ");
  $st->execute([$vehicleModelId]);
  $cat = $st->fetch();
  if (!$cat) json_out(["ok"=>false,"error"=>"Invalid vehicle_model_id"], 404);

  $makeName = (string)$cat["make_name"];
  $modelName = (string)$cat["model_name"];
  if ($title === "") $title = "{$makeName} {$modelName} {$year}";

  $expiresAt = add_days_utc($expiryDays);
  $isSponsored = $sponsorDays > 0 ? 1 : 0;
  $sponsoredUntil = $sponsorDays > 0 ? add_days_utc($sponsorDays) : null;

  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("
      INSERT INTO listings(
        dealer_id, yard_id, created_by, town_id, vehicle_model_id,
        title, make, model, trim,
        year, engine_cc, mileage_km,
        fuel_type, transmission, body_type, color,
        condition_type, cash_price_kes,
        allows_cash, allows_hp, allows_trade_in, allows_external_financing,
        is_sponsored, sponsored_until,
        approval_status, approval_reason, approved_by, approved_at,
        expires_at, published_at,
        description, features,
        created_at
      ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?,
        ?, ?, ?, ?,
        ?, ?,
        'pending', NULL, NULL, NULL,
        ?, NULL,
        ?, ?,
        ?
      )
    ");

    $ins->execute([
      $dealerId,
      $yardId > 0 ? $yardId : null,
      $dealerId, // created_by (quickadd)
      $townId,
      $vehicleModelId,
      $title,
      $makeName,
      $modelName,
      $trim !== "" ? $trim : null,
      $year,
      $engineCc,
      $mileageKm,
      $fuel !== "" ? $fuel : null,
      $trans !== "" ? $trans : null,
      $bodyType !== "" ? $bodyType : null,
      $color !== "" ? $color : null,
      $condition,
      $priceKes,
      $allowsCash,
      $hpOn ? 1 : 0,
      $allowsTrade,
      $allowsExt,
      $isSponsored,
      $sponsoredUntil,
      $expiresAt,
      $desc !== "" ? $desc : null,
      $featuresArr ? json_encode($featuresArr, JSON_UNESCAPED_UNICODE) : null,
      now_utc()
    ]);

    $listingId = (int)$pdo->lastInsertId();

    if ($hpOn) {
      $hpIns = $pdo->prepare("
        INSERT INTO listing_hp_terms(
          listing_id, min_deposit_kes, max_deposit_kes, default_deposit_kes,
          min_months, max_months, notes, created_at
        ) VALUES (?,?,?,?,?,?,?,?)
      ");
      $hpIns->execute([
        $listingId,
        $hpMinDeposit,
        $hpMaxDeposit ?: null,
        null,
        $hpMinMonths,
        $hpMaxMonths,
        $hpNotes !== "" ? $hpNotes : null,
        now_utc()
      ]);
    }

    // Images upload
    ensure_dir($uploadListingsDir);

    if (!empty($_FILES["photos"]) && is_array($_FILES["photos"]["name"])) {
      $count = count($_FILES["photos"]["name"]);

      $imgIns = $pdo->prepare("
        INSERT INTO listing_images(listing_id, image_url, sort_order, created_at)
        VALUES(?,?,?,?)
      ");

      for ($i=0; $i<$count; $i++){
        if (($_FILES["photos"]["error"][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $tmp = $_FILES["photos"]["tmp_name"][$i] ?? "";
        if ($tmp === "" || !is_uploaded_file($tmp)) continue;

        $orig = (string)($_FILES["photos"]["name"][$i] ?? "photo");
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ["jpg","jpeg","png","webp"], true)) $ext = "jpg";

        $safe = bin2hex(random_bytes(10));
        $fname = $listingId . "_" . $safe . "." . $ext;
        $dest = rtrim($uploadListingsDir, "/") . "/" . $fname;

        if (!move_uploaded_file($tmp, $dest)) continue;

        $url = rtrim($uploadUrlPrefix, "/") . "/" . $fname;
        $imgIns->execute([$listingId, $url, $i, now_utc()]);
      }
    }

    event_log("listing_created", ["listing_id"=>$listingId, "dealer_id"=>$dealerId], [
      "vehicle_model_id"=>$vehicleModelId,
      "town_id"=>$townId,
      "is_sponsored"=>$isSponsored,
      "expiry_days"=>$expiryDays
    ]);

    $pdo->commit();
    json_out(["ok"=>true, "listing_id"=>$listingId]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    json_out(["ok"=>false, "error"=>"Insert failed"], 500);
  }
}

/* Unknown API */
if ($isApi) json_out(["ok"=>false,"error"=>"Unknown action"], 404);

/* ============================================================
   7) HTML — WIZARD UI
   ============================================================ */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
  <title>Pipii — Listing Wizard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root{
      --bg:#06070c;
      --card: rgba(255,255,255,.035);
      --stroke: rgba(255,255,255,.09);
      --ink: rgba(255,255,255,.92);
      --mut: rgba(255,255,255,.60);
      --mut2: rgba(255,255,255,.42);
      --shadow: 0 24px 90px rgba(0,0,0,.45);
      --r: 22px;
    }
    html,body{height:100%;}
    body{
      margin:0;
      background:
        radial-gradient(1100px 700px at 18% -10%, rgba(255,255,255,.08), transparent 62%),
        radial-gradient(900px 520px at 88% -6%, rgba(255,255,255,.06), transparent 58%),
        var(--bg);
      color: var(--ink);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      overflow-x:hidden;
    }
    .shell{ max-width: 1080px; margin: 0 auto; padding: 18px 14px 120px; }
    .topbar{
      position: sticky; top:0; z-index: 60;
      background: rgba(6,7,12,.76);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid rgba(255,255,255,.06);
    }
    .brand{ display:flex; align-items:center; gap:12px; height:64px; }
    .logo{
      width:40px; height:40px; border-radius: 999px;
      background: rgba(255,255,255,.10);
      border: 1px solid rgba(255,255,255,.10);
      display:flex; align-items:center; justify-content:center;
      box-shadow: 0 16px 50px rgba(0,0,0,.35);
    }
    .card{
      border-radius: var(--r);
      border: 1px solid rgba(255,255,255,.085);
      background: var(--card);
      box-shadow: var(--shadow);
    }
    .grid2{ display:grid; grid-template-columns: 1.2fr .8fr; gap: 14px; }
    @media (max-width: 980px){ .grid2{ grid-template-columns: 1fr; } }

    .progressWrap{ display:flex; align-items:center; gap:10px; }
    .bar{
      height: 10px; flex:1;
      border-radius: 999px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.07);
      overflow:hidden;
    }
    .bar > i{
      display:block; height:100%;
      width:0%;
      background: rgba(255,255,255,.88);
      border-radius: 999px;
      transition: width .25s ease;
    }
    .stepChip{
      font-size: 12px;
      padding: 7px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.04);
      color: rgba(255,255,255,.78);
      font-weight: 650;
      user-select:none;
      white-space:nowrap;
    }

    .qTitle{ font-size: 18px; font-weight: 800; letter-spacing: .01em; }
    .qSub{ margin-top: 6px; font-size: 13px; color: var(--mut); line-height: 1.45; }

    .fade{
      opacity: 1;
      transform: translateY(0);
      transition: opacity .22s ease, transform .22s ease;
    }
    .fade.out{
      opacity: 0;
      transform: translateY(8px);
      pointer-events:none;
    }
    .panelPad{ padding: 18px; }
    @media (min-width: 980px){ .panelPad{ padding: 20px; } }

    .btn{
      border-radius: 16px;
      padding: 12px 16px;
      font-size: 13px;
      font-weight: 700;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      color: var(--ink);
      transition: transform .08s, background .14s, border-color .14s;
      user-select:none;
      min-height: 44px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap: 8px;
    }
    .btn:hover{ background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.18); }
    .btn:active{ transform: translateY(1px); }
    .btnSolid{ background:#fff; color:#000; border-color: rgba(255,255,255,.35); }
    .btnSolid:hover{ background: rgba(255,255,255,.92); }
    .btnGhost{ background: transparent; border-color: rgba(255,255,255,.10); }
    .btnDanger{ background: rgba(244,63,94,.14); border-color: rgba(244,63,94,.30); color: rgba(255,220,230,.95); }
    .btnAdd{
      padding: 10px 14px;
      border-radius: 14px;
      border: 1px dashed rgba(255,255,255,.18);
      background: rgba(255,255,255,.035);
      color: rgba(255,255,255,.78);
      font-weight: 700;
      font-size: 12px;
      min-height: 44px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap: 8px;
    }
    .btnAdd:hover{ border-color: rgba(255,255,255,.28); background: rgba(255,255,255,.05); }

    .selectedCard{
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.035);
      padding: 12px 14px;
      min-width: 220px;
      color: rgba(255,255,255,.9);
      box-shadow: 0 12px 28px rgba(0,0,0,.25);
      display:flex;
      flex-direction:column;
      gap: 4px;
    }
    .selectedCard .scLabel{
      font-size: 10px;
      letter-spacing: .18em;
      text-transform: uppercase;
      color: rgba(255,255,255,.6);
    }
    .selectedCard .scValue{
      font-size: 15px;
      font-weight: 800;
    }
    .selectedCard .scSub{
      font-size: 12px;
      color: rgba(255,255,255,.7);
    }
    .dealerAccent{
      display:inline-flex;
      align-items:center;
      gap: 6px;
      padding: 2px 10px;
      border-radius: 999px;
      background: rgba(255,255,255,.12);
      color: rgba(255,255,255,.95);
      font-weight: 800;
      letter-spacing: .01em;
    }
    .btnIconTail{
      display:inline-flex;
      margin-left: 8px;
    }
    .btnIconTail svg{
      width: 12px;
      height: 12px;
      stroke: currentColor;
      stroke-width: 2;
      fill: none;
    }
    .previewCard{
      border-radius: 24px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(12,14,20,.8);
      backdrop-filter: blur(16px);
      box-shadow: 0 25px 80px rgba(0,0,0,.35);
      overflow:hidden;
    }
    .previewSliderWrap{
      position:absolute;
      bottom: 16px;
      left: 20px;
      right: 20px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      z-index: 2;
    }
    .previewSliderDots{ display:flex; gap:6px; }
    .previewDot{
      width:8px; height:8px;
      border-radius: 999px;
      background: rgba(255,255,255,.22);
      transition: width .14s ease, background .14s ease;
    }
    .previewDot.active{
      width: 22px;
      background: rgba(255,255,255,.92);
    }
    .previewSliderMeta{
      font-size: 12px;
      color: rgba(255,255,255,.7);
    }
    .sliderCaret{
      width: 32px;
      height: 32px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.35);
      background: rgba(0,0,0,.45);
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      color: rgba(255,255,255,.92);
      transition: background .14s, border-color .14s, transform .14s;
    }
    .sliderCaret:disabled{
      opacity: .35;
      cursor:not-allowed;
    }
    .sliderCaret svg{
      width: 14px;
      height: 14px;
      stroke: currentColor;
      stroke-width: 2.2;
      fill: none;
    }
    .sliderCaret:hover{
      background: rgba(0,0,0,.65);
      border-color: rgba(255,255,255,.6);
    }
    .previewMedia{
      width:100%;
      height: 220px;
      background: linear-gradient(130deg, rgba(59,130,246,.35), rgba(15,23,42,.95));
      background-size: cover;
      background-position: center;
      position:relative;
      border-radius: 24px 24px 0 0;
      overflow:hidden;
    }
    .previewMediaHint{
      position:absolute;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size: 12px;
      color: rgba(255,255,255,.75);
      text-transform: uppercase;
      letter-spacing: .18em;
      background: rgba(0,0,0,.15);
      border-radius: 0;
      z-index: 1;
    }
    .previewMedia.has-photo .previewMediaHint{ display:none; }
    .previewBody{
      padding: 24px;
      display:flex;
      flex-direction:column;
      gap: 16px;
    }
    .previewHeader{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap: 12px;
    }
    .previewTitle{ font-size: 18px; font-weight: 800; color:#fff; }
    .previewMeta{ font-size: 12px; color: rgba(255,255,255,.6); margin-top: 4px; }
    .previewPrice{ font-size: 22px; font-weight: 800; color: #fff; }
    .previewDealer{ font-size: 12px; color: rgba(255,255,255,.7); }
    .placeholder{ color: rgba(255,255,255,.4)!important; }
    .previewSpecChips,
    .previewFeatureChips{
      display:flex;
      flex-wrap:wrap;
      gap: 8px;
    }
    .previewChip{
      display:inline-flex;
      align-items:center;
      gap: 4px;
      padding: 4px 8px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.04);
      font-size: 11px;
      font-weight: 700;
      color: rgba(255,255,255,.92);
    }
    .previewChip .chipIcon{
      display:flex;
      align-items:center;
    }
    .previewChip .chipIcon svg{
      width: 12px;
      height: 12px;
    }
    .previewChip .chipLabel{ font-weight: 600; opacity: .65; margin-right: 2px; font-size: 10px; text-transform: uppercase; letter-spacing: .1em; }
    .previewChip.colorful{
      background: var(--chip-color, rgba(255,255,255,.1));
      border-color: rgba(255,255,255,.18);
      color: var(--chip-ink,#fff);
    }
    .previewChip .chipIcon svg{ stroke: currentColor; }
    .previewSale{
      border-top: 1px solid rgba(255,255,255,.08);
      padding-top: 14px;
      display:flex;
      flex-direction:column;
      gap: 10px;
    }
    .previewSaleTitle{
      font-size: 11px;
      letter-spacing: .2em;
      text-transform: uppercase;
      color: rgba(255,255,255,.55);
    }
    .previewSaleBadges{
      display:flex;
      flex-wrap:wrap;
      gap: 8px;
    }
    .previewSaleBadge{
      font-size: 11px;
      font-weight: 700;
      padding: 6px 11px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(255,255,255,.05);
    }
    .previewPlaceholderBar{
      width: 70%;
      height: 14px;
      border-radius: 999px;
      background: rgba(255,255,255,.06);
      margin-bottom: 6px;
    }
    .previewHpBtn{
      align-self:flex-start;
      border-radius: 999px;
      padding: 10px 18px;
      font-size: 12px;
      font-weight: 800;
      border: none;
      background: linear-gradient(135deg, rgba(248,250,252,.95), rgba(200,210,255,.85));
      color: #111;
      cursor:pointer;
      transition: background .14s, border-color .14s;
    }
    .previewHpBtn:hover{ background: rgba(248,250,252,1); }
    .specSection{
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.06);
      background: rgba(255,255,255,.02);
      padding: 16px;
    }
    .colorSwatchGrid{
      display:grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap: 10px;
    }
    @media (min-width: 800px){
      .colorSwatchGrid{ grid-template-columns: repeat(3, minmax(0,1fr)); }
    }
    .colorSwatch{
      width:100%;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.02);
      display:flex;
      align-items:center;
      gap: 10px;
      padding: 10px 12px;
      cursor:pointer;
      transition: background .14s, border-color .14s, color .14s;
      color: rgba(255,255,255,.85);
    }
    .colorSwatch::before{
      content:'';
      width: 22px; height: 22px;
      border-radius: 999px;
      background: var(--swatch-color,#fff);
      border: 1px solid rgba(255,255,255,.25);
      box-shadow: 0 6px 18px rgba(0,0,0,.25);
      flex: 0 0 auto;
    }
    .colorSwatch[aria-selected="true"]{
      background: var(--swatch-color, rgba(255,255,255,.18));
      border-color: rgba(255,255,255,.4);
      color: var(--swatch-ink, #111);
      box-shadow: 0 18px 45px rgba(0,0,0,.25);
    }
    .colorSwatchMeta{ display:flex; flex-direction:column; align-items:flex-start; }
    .colorName{ font-size: 13px; font-weight: 700; }
    .colorHint{ font-size: 11px; opacity: .75; }
    .hpPlanSummary{
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.03);
      padding: 12px;
      font-size: 13px;
      color: rgba(255,255,255,.82);
      line-height: 1.4;
    }
    .hpPlanTable{
      width:100%;
      border-collapse: collapse;
      margin-top: 14px;
      font-size: 12px;
    }
    .hpPlanTable th,
    .hpPlanTable td{
      text-align:left;
      padding: 8px 6px;
      border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .hpPlanTable th{
      font-size: 11px;
      letter-spacing: .2em;
      text-transform: uppercase;
      color: rgba(255,255,255,.6);
    }
    .hpPlanNote{
      margin-top: 10px;
      font-size: 12px;
      color: rgba(255,255,255,.7);
      line-height: 1.4;
    }
    .calendarWidget{
      margin-top: 14px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(10,13,21,.65);
      padding: 12px;
    }
    .calendarHeader{
      display:flex;
      align-items:center;
      justify-content:space-between;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: .05em;
      text-transform: uppercase;
    }
    .calendarHeader button{
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.04);
      color: rgba(255,255,255,.85);
      border-radius: 10px;
      width:32px;
      height:32px;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      transition: background .14s, border-color .14s;
    }
    .calendarHeader button:hover{
      border-color: rgba(255,255,255,.25);
      background: rgba(255,255,255,.08);
    }
    .calendarDays{
      display:grid;
      grid-template-columns: repeat(7, minmax(0,1fr));
      gap: 6px;
      margin-top: 12px;
      font-size: 10px;
      text-transform: uppercase;
      color: rgba(255,255,255,.6);
      letter-spacing: .18em;
      text-align:center;
    }
    .calendarGrid{
      display:grid;
      grid-template-columns: repeat(7, minmax(0,1fr));
      gap: 6px;
      margin-top: 8px;
    }
    .calendarCell{
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.08);
      min-height: 38px;
      background: rgba(255,255,255,.03);
      color: rgba(255,255,255,.82);
      display:flex;
      align-items:center;
      justify-content:center;
      font-size: 13px;
      cursor:pointer;
      transition: background .14s, border-color .14s, transform .08s;
    }
    .calendarCell[disabled]{
      opacity: .2;
      cursor: default;
    }
    .calendarCell[aria-selected="true"]{
      background: rgba(59,130,246,.22);
      border-color: rgba(59,130,246,.55);
      color: #fff;
      box-shadow: 0 10px 26px rgba(15,118,243,.28);
    }
    .calendarCell:not([disabled]):hover{
      border-color: rgba(255,255,255,.22);
      background: rgba(255,255,255,.08);
    }

    .field{
      width:100%;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.045);
      color: var(--ink);
      padding: 13px 14px;
      font-size: 14px;
      outline:none;
      transition: box-shadow .14s, border-color .14s, background .14s;
      min-height: 44px;
    }
    .field:focus{
      border-color: rgba(255,255,255,.22);
      box-shadow: 0 0 0 4px rgba(255,255,255,.06);
      background: rgba(255,255,255,.065);
    }
    .label{ font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--mut2); }
    .hr{ height:1px; background: rgba(255,255,255,.07); }

    /* Pills */
    .pillGrid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; }
    @media (max-width: 860px){ .pillGrid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 460px){ .pillGrid{ grid-template-columns: 1fr; } }

    .pill{
      position: relative;
      display:flex;
      align-items:center;
      gap: 6px;
      padding: 7px 10px;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.028);
      --pill-selected-bg: rgba(255,255,255,.11);
      cursor:pointer;
      user-select:none;
      min-height: 32px;
      transition: transform .08s, border-color .14s, background .14s, filter .14s, box-shadow .18s;
      overflow:hidden;
    }
    .pill:hover{ border-color: rgba(255,255,255,.18); background: rgba(255,255,255,.05); }
    .pill:active{ transform: translateY(1px); }
    .pill[aria-selected="true"]{
      border-color: rgba(0,0,0,.16);
      background: var(--pill-selected-bg);
      box-shadow: 0 12px 26px rgba(0,0,0,.32);
    }
    .pill .ic{
      width: 22px; height: 22px;
      border-radius: 9px;
      display:flex; align-items:center; justify-content:center;
      border: 1px solid rgba(255,255,255,.14);
      background: var(--pill-accent, rgba(255,255,255,.08));
      color: rgba(7,12,22,.85);
      overflow:hidden;
      flex: 0 0 auto;
      box-shadow: 0 8px 18px rgba(0,0,0,.25);
    }
    .pill .ic svg{
      width: 11px;
      height: 11px;
      stroke: currentColor;
    }
    .pill .tx{ min-width:0; }
    .pill .tx .t{
      font-size: 11px;
      font-weight: 800;
      color: rgba(255,255,255,.9);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .pill .tx .s{
      font-size: 9px;
      color: rgba(255,255,255,.62);
      margin-top: 1px;
    }
    .pill[aria-selected="true"] .tx .t{
      color: var(--pill-selected-ink, #0f172a);
    }
    .pill[aria-selected="true"] .tx .s{
      color: var(--pill-selected-sub, rgba(15,23,42,.6));
    }
    .pill[aria-selected="true"] .ic{
      background: rgba(255,255,255,.18);
      color: var(--pill-selected-ink, #0f172a);
      border-color: rgba(0,0,0,.14);
      box-shadow: none;
    }

    /* “Muted but colored when active”: keep pastel visible, but dim when not selected */
    .pill[data-pastel]{ filter: saturate(.90) brightness(.92); }
    .pill[data-pastel][aria-selected="true"]{ filter: saturate(1) brightness(1.05); }

    /* Photo tiles */
    .photoGrid{ display:grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: 8px; }
    @media (max-width: 900px){ .photoGrid{ grid-template-columns: repeat(5,1fr);} }
    @media (max-width: 700px){ .photoGrid{ grid-template-columns: repeat(4,1fr);} }
    @media (max-width: 520px){ .photoGrid{ grid-template-columns: repeat(3,1fr);} }
    .ph{
      aspect-ratio: 1/1;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.04);
      overflow:hidden;
      position:relative;
    }
    .ph img{ width:100%; height:100%; object-fit:cover; display:block; }
    .ph .x{
      position:absolute; top:6px; right:6px;
      width:28px; height:28px;
      border-radius: 12px;
      background: rgba(0,0,0,.55);
      border:1px solid rgba(255,255,255,.16);
      display:flex; align-items:center; justify-content:center;
      cursor:pointer;
    }

    .toast{
      position: fixed;
      right: 16px;
      bottom: 16px;
      z-index: 2000;
      max-width: 380px;
      display:none;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(10,12,18,.92);
      backdrop-filter: blur(10px);
      padding: 12px 14px;
      box-shadow: 0 18px 70px rgba(0,0,0,.45);
    }
    .toastTitle{ font-weight: 800; font-size: 13px; }
    .toastSub{ font-size: 12px; color: rgba(255,255,255,.62); margin-top: 2px; }

    /* Mobile bottom nav */
    .mobileNav{
      position: fixed; left:0; right:0; bottom:0;
      z-index: 80;
      background: rgba(6,7,12,.86);
      border-top: 1px solid rgba(255,255,255,.08);
      backdrop-filter: blur(14px);
      padding: 10px 12px;
    }
    .mobileNavInner{ max-width: 1080px; margin: 0 auto; display:flex; gap: 10px; }
    @media (min-width: 980px){ .mobileNav{ display:none; } }

    /* Modal */
    .modal{
      position: fixed; inset: 0;
      z-index: 3000;
      display:none;
      background: rgba(0,0,0,.62);
      backdrop-filter: blur(6px);
      padding: 18px;
    }
    .modalCard{
      max-width: 560px;
      margin: 7vh auto 0;
      border-radius: 22px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(14,16,22,.92);
      box-shadow: 0 30px 120px rgba(0,0,0,.55);
      overflow:hidden;
    }
    .modalHead{ padding: 16px 16px 0; display:flex; align-items:flex-start; justify-content:space-between; gap: 12px; }
    .modalTitle{ font-size: 14px; font-weight: 900; }
    .modalBody{ padding: 14px 16px 16px; }
    .xbtn{
      width: 38px; height: 38px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      display:flex; align-items:center; justify-content:center;
      cursor:pointer;
    }
    .xbtn:hover{ background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.18); }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="max-w-6xl mx-auto px-4">
      <div class="brand">
        <div class="logo" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
            <path d="M3 13.5v3.2c0 .7.6 1.3 1.3 1.3H6" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round"/>
            <path d="M18 18h1.7c.7 0 1.3-.6 1.3-1.3v-3.2c0-1-.6-1.9-1.5-2.3l-1.8-.8-1.6-3.9C15.7 5.6 14.9 5 14 5H10c-.9 0-1.7.6-2 1.5L6.4 10.4l-1.8.8C3.6 11.6 3 12.5 3 13.5Z" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round"/>
            <path d="M7 18a2 2 0 1 0 4 0" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round"/>
            <path d="M13 18a2 2 0 1 0 4 0" stroke="rgba(255,255,255,.85)" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="leading-tight">
          <div class="text-[13px] font-extrabold text-white/90">Pipii Listing Wizard</div>
          <div class="text-[11px] text-white/45">Tap-first shortcut to populate the production schema.</div>
        </div>

        <div class="ml-auto hidden lg:flex items-center gap-2">
          <button class="btn btnGhost" type="button" id="btnReset">Reset<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M5 10a5 5 0 1 1 1.3 3.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
          <button class="btn" type="button" id="btnBackTop">Back<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
          <button class="btn btnSolid" type="button" id="btnNextTop">Next<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
        </div>
      </div>
    </div>
  </header>

  <main class="shell">
    <div class="grid2 mt-5">
      <!-- MAIN WIZARD -->
      <section class="card panelPad">
        <div class="progressWrap">
          <div class="bar"><i id="barFill"></i></div>
          <div class="stepChip" id="stepChip">Step 1/18</div>
        </div>

        <div class="mt-5 hr"></div>

        <div id="qWrap" class="fade mt-5">
          <div class="qTitle" id="qTitle">Loading…</div>
          <div class="qSub" id="qSub">Please wait.</div>

          <div class="mt-5" id="qBody">
            <!-- dynamic -->
          </div>

          <div class="mt-6 flex items-center justify-between gap-3 hidden lg:flex" id="deskNav">
            <button class="btn btnGhost" type="button" id="btnBack">Back<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
            <div class="flex items-center gap-2">
              <button class="btn btnGhost" type="button" id="btnSkip">Skip<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M7 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
              <button class="btn btnSolid" type="button" id="btnNext">Next<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
            </div>
          </div>
        </div>
      </section>

      <!-- SIDE PREVIEW -->
      <aside>
        <div class="previewCard" id="previewCard">
          <div class="previewMedia" id="previewMedia">
            <div class="previewMediaHint" id="previewMediaHint">Add photos to preview slider</div>
            <div class="previewSliderWrap">
            <button type="button" class="sliderCaret" id="sliderPrev" aria-label="Previous photo">
              <svg viewBox="0 0 20 20"><path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="previewSliderDots" id="previewSliderDots"></div>
            <div class="previewSliderMeta" id="previewSliderMeta">0 photos</div>
            <button type="button" class="sliderCaret" id="sliderNext" aria-label="Next photo">
              <svg viewBox="0 0 20 20"><path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
          </div>
          <div class="previewBody">
            <div class="previewHeader">
              <div>
                <div class="previewTitle" id="previewTitle">Add a vehicle</div>
                <div class="previewMeta" id="previewMeta">Year • Body • Town</div>
              </div>
              <div class="previewPrice" id="previewPrice">KES —</div>
            </div>
            <div class="previewDealer" id="previewDealer">Select dealer to start</div>
            <div class="previewSpecChips" id="previewSpecChips"></div>
            <div class="previewFeatureChips" id="previewFeatureChips"></div>
            <div class="previewSale" id="previewSale"></div>
          </div>
        </div>

        <div class="card panelPad mt-5">
          <div class="text-[13px] font-extrabold">Fast actions</div>
          <div class="qSub mt-2">Use only when needed.</div>
          <div class="mt-3 flex flex-wrap gap-2">
            <button class="btn btnGhost" type="button" id="btnReview">Review<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M3 10s3.5-5 7-5 7 5 7 5-3.5 5-7 5-7-5-7-5Z" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
            <button class="btn btnDanger" type="button" id="btnClearAll">Clear all<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
          </div>
        </div>
      </aside>
    </div>
  </main>

  <!-- Mobile nav -->
  <div class="mobileNav">
    <div class="mobileNavInner">
      <button class="btn btnGhost" type="button" id="mBack" style="flex:1;">Back<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
      <button class="btn btnSolid" type="button" id="mNext" style="flex:2;">Next<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
    </div>
  </div>

  <!-- Modal -->
  <div class="modal" id="modal">
    <div class="modalCard">
      <div class="modalHead">
        <div>
          <div class="modalTitle" id="modalTitle">Add</div>
          <div class="qSub" id="modalSub">Saved into database in Title Case.</div>
        </div>
        <div class="xbtn" id="modalClose" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
            <path d="M7 7l10 10M17 7L7 17" stroke="rgba(255,255,255,.92)" stroke-width="2.6" stroke-linecap="round"/>
          </svg>
        </div>
      </div>
      <div class="modalBody" id="modalBody">
        <!-- dynamic -->
      </div>
    </div>
  </div>

  <div class="toast" id="toast">
    <div class="toastTitle" id="toastTitle">Saved</div>
    <div class="toastSub" id="toastSub">Done</div>
  </div>

  <script>
    const $ = (id) => document.getElementById(id);
    const API_BASE = window.location.pathname;
    const PUBLISH_CANCELLED = 'PUBLISH_CANCELLED';
    const ICON_ARROW_RIGHT = '<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
    const ICON_ARROW_LEFT = '<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';

    /* ---------- Toast ---------- */
    function toast(title, sub){
      const t = $('toast');
      $('toastTitle').textContent = title;
      $('toastSub').textContent = sub || '';
      t.style.display = 'block';
      clearTimeout(window.__toastT);
      window.__toastT = setTimeout(()=> t.style.display='none', 2600);
    }

    /* ---------- API wrappers (JSON-safe) ---------- */
    async function apiGet(params){
      const u = new URL(API_BASE, window.location.origin);
      Object.entries(params || {}).forEach(([k,v])=> u.searchParams.set(k, v));
      const res = await fetch(u.toString(), { credentials:'same-origin' });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); }
      catch(e){ throw new Error('Invalid JSON from server. Check PHP logs.'); }
      if (!data.ok) throw new Error(data.error || 'Request failed');
      return data;
    }
    async function apiPost(action, formData){
      const u = new URL(API_BASE, window.location.origin);
      u.searchParams.set('a', action);
      const res = await fetch(u.toString(), { method:'POST', body: formData, credentials:'same-origin' });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); }
      catch(e){ throw new Error('Invalid JSON from server. Check PHP logs.'); }
      if (!data.ok) throw new Error(data.error || 'Request failed');
      return data;
    }

    /* ---------- Pastel + Icons ---------- */
    function hashCode(str){
      let h = 0;
      for (let i=0;i<str.length;i++){
        h = ((h<<5)-h) + str.charCodeAt(i);
        h |= 0;
      }
      return Math.abs(h);
    }
    function pastelFromKey(key){
      const h = hashCode(String(key));
      // deterministic but varied:
      const hue = h % 360;
      const sat = 62 + (h % 14);  // 62..75
      const lit = 55 + (h % 12);  // 55..66
      return `hsl(${hue} ${sat}% ${lit}%)`;
    }
    const namedColorHex = {
      'white':'#f7f7f7',
      'black':'#0b0b0b',
      'silver':'#c0c0c0',
      'grey':'#7a7a7a',
      'gray':'#7a7a7a',
      'blue':'#1d4ed8',
      'red':'#dc2626',
      'green':'#15803d',
      'beige':'#d6c7a1',
      'brown':'#8b5a2b',
      'gold':'#d4af37',
      'orange':'#f97316',
      'purple':'#7c3aed'
    };
    function colorValueFromName(name){
      if (!name) return pastelFromKey('color');
      const key = name.trim().toLowerCase();
      return namedColorHex[key] || pastelFromKey(key);
    }
    function isLightColor(color){
      if (!color) return false;
      if (color.startsWith('#')){
        let hex = color.replace('#','');
        if (hex.length === 3){
          hex = hex.split('').map(ch=>ch+ch).join('');
        }
        const r = parseInt(hex.slice(0,2), 16);
        const g = parseInt(hex.slice(2,4), 16);
        const b = parseInt(hex.slice(4,6), 16);
        const lum = (0.299*r + 0.587*g + 0.114*b) / 255;
        return lum > 0.6;
      }
      const match = color.match(/hsl\(\s*\d+\s+(\d+)%\s+(\d+)%\s*\)/i);
      if (match){
        const lightness = parseInt(match[2], 10);
        return lightness >= 60;
      }
      return false;
    }
    function niceLabel(str){
      if (!str) return '';
      return String(str).split(/[_\s]+/).map(part=>{
        if (!part) return '';
        return part.charAt(0).toUpperCase() + part.slice(1).toLowerCase();
      }).join(' ');
    }
    function autoTitle(){
      const manual = (state.title || '').trim();
      if (manual) return manual;
      const parts = [];
      if (state.year) parts.push(state.year);
      if (state.make && state.make.name) parts.push(state.make.name);
      if (state.model && state.model.name) parts.push(state.model.name);
      if (state.body) parts.push(state.body);
      return parts.length ? parts.join(' ') : 'Add a vehicle';
    }
    function setPreviewText(elId, value, placeholder){
      const el = $(elId);
      if (!el) return;
      if (!value || !String(value).trim()){
        el.classList.add('placeholder');
        el.textContent = placeholder;
      } else {
        el.classList.remove('placeholder');
        el.textContent = value;
      }
    }
    function duotoneIcon(kind){
      // Simple duotone-ish icons: two paths with different opacities.
      // kind: car, pin, tag, bolt, gear, fuel, trans, palette, money, camera, shield
      const common = 'fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"';
      const sizeAttr = 'width="14" height="14" viewBox="0 0 24 24"';
      switch(kind){
        case 'pin': return `
          <svg ${sizeAttr}>
            <path ${common} d="M12 21s7-4.6 7-11a7 7 0 1 0-14 0c0 6.4 7 11 7 11Z"/>
            <path ${common} opacity=".55" d="M12 10a2.3 2.3 0 1 0 0 .1Z"/>
          </svg>`;
        case 'tag': return `
          <svg ${sizeAttr}>
            <path ${common} d="M20 13l-7 7-11-11V2h7l11 11Z"/>
            <path ${common} opacity=".55" d="M7.5 7.5h.01"/>
          </svg>`;
        case 'money': return `
          <svg ${sizeAttr}>
            <path ${common} d="M3 7h18v10H3z"/>
            <path ${common} opacity=".55" d="M12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/>
          </svg>`;
        case 'fuel': return `
          <svg ${sizeAttr}>
            <path ${common} d="M6 3h8v18H6z"/>
            <path ${common} opacity=".55" d="M14 8h2l2 2v10a2 2 0 0 1-2 2h-2"/>
          </svg>`;
        case 'trans': return `
          <svg ${sizeAttr}>
            <path ${common} d="M7 7h10M12 7v10"/>
            <path ${common} opacity=".55" d="M9 17h6"/>
          </svg>`;
        case 'palette': return `
          <svg ${sizeAttr}>
            <path ${common} d="M12 3a9 9 0 1 0 0 18h2a2 2 0 0 0 2-2c0-1.2-.8-2-2-2h-1"/>
            <path ${common} opacity=".55" d="M7.5 10h.01M10 8h.01M14 8h.01M16.5 10h.01"/>
          </svg>`;
        case 'camera': return `
          <svg ${sizeAttr}>
            <path ${common} d="M4 7h4l2-2h4l2 2h4v12H4z"/>
            <path ${common} opacity=".55" d="M12 10a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
          </svg>`;
        case 'car':
        default: return `
          <svg ${sizeAttr}>
            <path ${common} d="M3 13.5v3.2c0 .7.6 1.3 1.3 1.3H6"/>
            <path ${common} d="M18 18h1.7c.7 0 1.3-.6 1.3-1.3v-3.2c0-1-.6-1.9-1.5-2.3l-1.8-.8-1.6-3.9C15.7 5.6 14.9 5 14 5H10c-.9 0-1.7.6-2 1.5L6.4 10.4l-1.8.8C3.6 11.6 3 12.5 3 13.5Z"/>
            <path ${common} opacity=".55" d="M7 18a2 2 0 1 0 4 0M13 18a2 2 0 1 0 4 0"/>
          </svg>`;
      }
    }
    function pillEl({id, title, sub, kind, key, selected=false}){
      const bg = pastelFromKey(key || title || id);
      const ink = isLightColor(bg) ? '#0f172a' : '#f8fafc';
      const sub = isLightColor(bg) ? 'rgba(15,23,42,.62)' : 'rgba(255,255,255,.75)';
      const el = document.createElement('div');
      el.className = 'pill';
      el.setAttribute('role','button');
      el.setAttribute('tabindex','0');
      el.setAttribute('aria-selected', selected ? 'true' : 'false');
      el.dataset.id = String(id ?? '');
      el.dataset.title = String(title ?? '');
      el.dataset.key = String(key || title || id || '');
      el.dataset.pastel = '1';
      el.style.setProperty('--pill-selected-bg', bg);
      el.style.setProperty('--pill-selected-ink', ink);
      el.style.setProperty('--pill-selected-sub', sub);
      el.style.setProperty('--pill-accent', bg);

      el.innerHTML = `
        <div class="ic">
          <div style="opacity:.92">${duotoneIcon(kind || 'car')}</div>
        </div>
        <div class="tx">
          <div class="t">${escapeHtml(title || '')}</div>
          ${sub ? `<div class="s">${escapeHtml(sub)}</div>` : `<div class="s">Tap to select</div>`}
        </div>
      `;
      return el;
    }

    function escapeHtml(str){
      return String(str).replace(/[&<>"']/g, (m)=>({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[m]));
    }

    /* ---------- Modal ---------- */
    function openModal(title, sub, bodyHtml){
      $('modalTitle').textContent = title || 'Add';
      $('modalSub').textContent = sub || '';
      $('modalBody').innerHTML = bodyHtml || '';
      $('modal').style.display = 'block';
    }
    function closeModal(){ $('modal').style.display = 'none'; }
    $('modalClose').addEventListener('click', closeModal);
    $('modal').addEventListener('click', (e)=>{ if (e.target === $('modal')) closeModal(); });

    function confirmDealerPublish(dealerName){
      return new Promise((resolve, reject)=>{
        const name = dealerName || 'this dealer';
        openModal("Confirm dealer", "Just a quick check before publishing.", `
          <div class="selectedCard" style="margin-bottom:12px;">
            <div class="scLabel">Dealer</div>
            <div class="scValue">${escapeHtml(name)}</div>
            <div class="scSub">Confirm this is the intended account.</div>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn btnGhost" type="button" id="confirmDealerNo">Go back<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
            <button class="btn btnSolid" type="button" id="confirmDealerYes">Publish for ${escapeHtml(name)}${ICON_ARROW_RIGHT}</button>
          </div>
        `);

        const yesBtn = $('confirmDealerYes');
        const noBtn = $('confirmDealerNo');
        const overlay = $('modal');
        const closeBtn = $('modalClose');

        const cleanup = ()=>{
          yesBtn && yesBtn.removeEventListener('click', onYes);
          noBtn && noBtn.removeEventListener('click', onCancel);
          overlay.removeEventListener('click', overlayHandler);
          closeBtn.removeEventListener('click', closeHandler);
        };

        const onYes = ()=>{
          cleanup();
          closeModal();
          resolve(true);
        };
        const onCancel = ()=>{
          cleanup();
          closeModal();
          reject(new Error(PUBLISH_CANCELLED));
        };
        const overlayHandler = (e)=>{
          if (e.target === overlay){
            cleanup();
            reject(new Error(PUBLISH_CANCELLED));
          }
        };
        const closeHandler = ()=>{
          cleanup();
          reject(new Error(PUBLISH_CANCELLED));
        };

        yesBtn && yesBtn.addEventListener('click', onYes);
        noBtn && noBtn.addEventListener('click', onCancel);
        overlay.addEventListener('click', overlayHandler);
        closeBtn.addEventListener('click', closeHandler);
      });
    }

    function openHpScheduleModal(){
      if (!state.sale.hp){
        toast("Hire purchase disabled", "Enable HP in payment options first.");
        return;
      }
      if (!state.price){
        toast("Price required", "Add a cash price before previewing HP schedule.");
        return;
      }
      const months = state.hp.months || 0;
      if (!months){
        toast("HP months missing", "Set HP months to preview the schedule.");
        return;
      }
      const deposit = state.hp.deposit ? Number(state.hp.deposit) : 0;
      const financed = Math.max((state.price || 0) - deposit, 0);
      const safeMonths = Math.max(months, 1);
      const monthly = safeMonths ? financed / safeMonths : financed;
      const today = new Date();
      const defaultDate = today.toISOString().split('T')[0];
      const dealerName = state.dealer ? state.dealer.full_name : '—';
      const yardName = state.yard ? state.yard.yard_name : '—';
      openModal("Hire purchase plan", "Select a start date to preview the amortization summary.", `
        <div>
          <div class="label">Start date</div>
          <input class="field mt-2" type="date" id="hpPlanStart" value="${defaultDate}">
          <div class="calendarWidget" id="hpCalendar"></div>
          <div class="hpPlanSummary mt-4" id="hpPlanSummary"></div>
          <div class="hpPlanSummary mt-3">Dealer: <strong>${escapeHtml(dealerName)}</strong><br>Yard: <strong>${escapeHtml(yardName || '—')}</strong></div>
          <table class="hpPlanTable">
            <thead>
              <tr><th>Stage</th><th>Due</th><th>Amount</th></tr>
            </thead>
            <tbody id="hpPlanTable"></tbody>
          </table>
          <div class="qSub mt-2">Assumes constant monthly payments for a simple preview.</div>
          <div class="hpPlanNote">Planning figure only. Price may vary slightly due to extras like trackers (est. KES 4k–20k) and insurance (per policy).</div>
        </div>
      `);
      const startInput = $('hpPlanStart');
      const summary = $('hpPlanSummary');
      const table = $('hpPlanTable');
      const calendarEl = $('hpCalendar');
      const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
      const dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
      let calendarCursor = startInput.value ? new Date(startInput.value) : new Date();
      if (Number.isNaN(calendarCursor.getTime())) calendarCursor = new Date();
      calendarCursor.setDate(1);

      const addMonths = (date, monthsToAdd)=>{
        const d = new Date(date.getTime());
        d.setMonth(d.getMonth() + monthsToAdd);
        return d;
      };

      const renderPlan = ()=>{
        let start = startInput.value ? new Date(startInput.value) : new Date();
        if (Number.isNaN(start.getTime())) start = new Date();
        const endDate = addMonths(start, safeMonths);
        summary.innerHTML = `
          Pay a deposit of <strong>${formatKesSafe(deposit, 'KES 0')}</strong>, finance <strong>${formatKesSafe(financed, 'KES 0')}</strong>
          over <strong>${safeMonths} month${safeMonths === 1 ? '' : 's'}</strong> at roughly <strong>${formatKesSafe(Math.round(monthly), 'KES 0')}</strong> per month.
          Estimated completion: <strong>${endDate.toLocaleDateString()}</strong>.
        `;
        const rows = [];
        rows.push(`
          <tr>
            <td>Deposit</td>
            <td>${start.toLocaleDateString()}</td>
            <td>${formatKesSafe(deposit, 'KES 0')}</td>
          </tr>
        `);
        const monthsToShow = Math.min(safeMonths, 6);
        for (let i=1; i<=monthsToShow; i++){
          const due = addMonths(start, i);
          rows.push(`
            <tr>
              <td>M${i}</td>
              <td>${due.toLocaleDateString()}</td>
              <td>${formatKesSafe(Math.round(monthly), 'KES 0')}</td>
            </tr>
          `);
        }
        if (safeMonths > monthsToShow){
          rows.push(`<tr><td colspan="3">… ${safeMonths - monthsToShow} more payment${safeMonths - monthsToShow === 1 ? '' : 's'}</td></tr>`);
        }
        rows.push(`
          <tr>
            <td>Completion</td>
            <td>${endDate.toLocaleDateString()}</td>
            <td>${formatKesSafe(Math.round(monthly), 'KES 0')}</td>
          </tr>
        `);
        table.innerHTML = rows.join('');
      };

      const renderCalendar = ()=>{
        if (!calendarEl) return;
        const view = new Date(calendarCursor.getFullYear(), calendarCursor.getMonth(), 1);
        const selectedRaw = startInput.value ? new Date(startInput.value) : null;
        calendarEl.innerHTML = `
          <div class="calendarHeader">
            <button type="button" id="calPrev">&lsaquo;</button>
            <div>${monthNames[view.getMonth()]} ${view.getFullYear()}</div>
            <button type="button" id="calNext">&rsaquo;</button>
          </div>
          <div class="calendarDays">${dayNames.map(d=>`<span>${d}</span>`).join('')}</div>
          <div class="calendarGrid"></div>
        `;
        const grid = calendarEl.querySelector('.calendarGrid');
        if (!grid) return;
        const firstDay = (view.getDay() + 6) % 7; // Monday-first
        for (let i=0; i<firstDay; i++){
          const pad = document.createElement('div');
          pad.className = 'calendarCell';
          pad.setAttribute('disabled','true');
          grid.appendChild(pad);
        }
        const daysInMonth = new Date(view.getFullYear(), view.getMonth()+1, 0).getDate();
        for (let d=1; d<=daysInMonth; d++){
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'calendarCell';
          btn.textContent = String(d);
          const cellDate = new Date(view.getFullYear(), view.getMonth(), d);
          const selected = selectedRaw && !Number.isNaN(selectedRaw.getTime()) &&
            selectedRaw.getFullYear() === cellDate.getFullYear() &&
            selectedRaw.getMonth() === cellDate.getMonth() &&
            selectedRaw.getDate() === cellDate.getDate();
          btn.setAttribute('aria-selected', selected ? 'true' : 'false');
          btn.addEventListener('click', ()=>{
            startInput.value = cellDate.toISOString().split('T')[0];
            renderPlan();
            calendarCursor = new Date(cellDate.getFullYear(), cellDate.getMonth(), 1);
            renderCalendar();
          });
          grid.appendChild(btn);
        }
        $('calPrev')?.addEventListener('click', ()=>{
          calendarCursor.setMonth(calendarCursor.getMonth() - 1);
          renderCalendar();
        });
        $('calNext')?.addEventListener('click', ()=>{
          calendarCursor.setMonth(calendarCursor.getMonth() + 1);
          renderCalendar();
        });
      };

      const handleStartChange = ()=>{
        renderPlan();
        if (startInput.value){
          const cur = new Date(startInput.value);
          if (!Number.isNaN(cur.getTime())){
            calendarCursor = new Date(cur.getFullYear(), cur.getMonth(), 1);
          }
        }
        renderCalendar();
      };

      startInput.addEventListener('change', handleStartChange);
      renderPlan();
      renderCalendar();
    }

    /* ---------- Wizard State ---------- */
    const state = {
      dealer: null, // {id, full_name, phone_e164}
      yard: null,   // {id, yard_name}
      towns: [],
      makes: [],
      models: [],
      years: [],
      bodies: [],
      features: [],

      make: null,   // {id,name}
      model: null,  // {id,name}
      year: null,   // number
      body: null,   // string
      town: null,   // {id,name}

      price: null,
      engine: null,
      mileage: null,

      fuel: null,
      trans: null,
      color: null,

      condition: "used",

      sale: {
        cash: true,
        hp: false,
        trade: false,
        external: false
      },

      hp: {
        deposit: null,
        months: 12,
        notes: ''
      },

      selectedFeatures: [],

      photos: [], // {file,url}

      title: '',
      trim: '',
      description: ''
    };
    let previewSlideIndex = 0;

    const steps = [
      "dealer",
      "yard",
      "make",
      "model",
      "year",
      "body",
      "town",
      "pricing",
      "engine",
      "fuel",
      "trans",
      "color",
      "extras",
      "condition",
      "sale",
      "features",
      "photos",
      "review"
    ];
    let stepIndex = 0;

    function setProgress(){
      const total = steps.length;
      const cur = stepIndex + 1;
      $('stepChip').textContent = `Step ${cur}/${total}`;
      const pct = Math.round((stepIndex / (total-1)) * 100);
      $('barFill').style.width = pct + '%';
    }

    function fadeTo(renderFn){
      const wrap = $('qWrap');
      wrap.classList.add('out');
      setTimeout(()=>{
        renderFn();
        wrap.classList.remove('out');
      }, 190);
    }

    /* ---------- Summary ---------- */
    function formatKesSafe(val, fallback='KES —'){
      if (val === null || val === undefined || val === '') return fallback;
      return 'KES ' + Number(val).toLocaleString();
    }
    function formatNumberUnit(val, unit=''){
      if (val === null || val === undefined || val === '') return null;
      return `${Number(val).toLocaleString()}${unit}`;
    }
    function updateSummary(){
      const card = $('previewCard');
      if (!card) return;
      const title = autoTitle();
      setPreviewText('previewTitle', title !== 'Add a vehicle' ? title : '', 'Add a vehicle');
      const metaParts = [];
      if (state.year) metaParts.push(state.year);
      if (state.body) metaParts.push(state.body);
      if (state.town) metaParts.push(state.town.name);
      setPreviewText('previewMeta', metaParts.join(' • '), 'Year • Body • Town');
      setPreviewText('previewPrice', state.price ? formatKesSafe(state.price) : '', 'Price pending');
      const dealerLine = state.dealer
        ? `${state.dealer.full_name}${state.yard ? ' • ' + state.yard.yard_name : ''}`
        : '';
      setPreviewText('previewDealer', dealerLine, 'Select dealer to start');
      updatePreviewMedia();
      renderSpecChips();
      renderFeatureChips();
      renderSaleBlock();
      const autoTitleNode = $('autoTitlePreview');
      if (autoTitleNode) autoTitleNode.textContent = autoTitle();
    }

    function updatePreviewMedia(){
      const media = $('previewMedia');
      const hint = $('previewMediaHint');
      const dots = $('previewSliderDots');
      const sliderMeta = $('previewSliderMeta');
      const prevBtn = $('sliderPrev');
      const nextBtn = $('sliderNext');
      if (!media || !dots || !sliderMeta) return;
      const count = state.photos.length;
      const sliderInk = window.getComputedStyle(sliderMeta).color;
      const caretInk = (sliderInk && sliderInk.trim()) ? sliderInk : '#f8fafc';
      [prevBtn, nextBtn].forEach(btn=>{
        if (!btn) return;
        btn.style.color = caretInk;
      });
      sliderMeta.textContent = `${count} photo${count === 1 ? '' : 's'}`;
      dots.innerHTML = '';
      if (count && previewSlideIndex >= count) previewSlideIndex = 0;
      const dotCount = count || 3;
      const maxDots = Math.min(dotCount, 6);
      for (let i=0; i<maxDots; i++){
        const dot = document.createElement('span');
        dot.className = 'previewDot';
        if (count){
          if (i === previewSlideIndex) dot.classList.add('active');
        } else if (i === 0){
          dot.classList.add('active');
        }
        dots.appendChild(dot);
      }
      if (prevBtn){
        prevBtn.disabled = count <= 1;
        prevBtn.onclick = ()=> changePreviewSlide(-1);
      }
      if (nextBtn){
        nextBtn.disabled = count <= 1;
        nextBtn.onclick = ()=> changePreviewSlide(1);
      }
      if (count){
        const photo = state.photos[previewSlideIndex] || state.photos[0];
        media.style.backgroundImage = `url(${photo.url})`;
        media.classList.add('has-photo');
        if (hint) hint.textContent = '';
      } else {
        media.style.backgroundImage = '';
        media.classList.remove('has-photo');
        if (hint) hint.textContent = 'Add photos to preview slider';
      }
    }

    function renderSpecChips(){
      const wrap = $('previewSpecChips');
      if (!wrap) return;
      wrap.innerHTML = '';
      const chips = [];
      if (state.engine) chips.push({label:'Engine', value:`${state.engine}cc`, icon:'gear'});
      const mileageVal = formatNumberUnit(state.mileage, 'km');
      if (mileageVal) chips.push({label:'Mileage', value:mileageVal, icon:'bolt'});
      if (state.fuel) chips.push({label:'Fuel', value:niceLabel(state.fuel), icon:'fuel'});
      if (state.trans) chips.push({label:'Trans', value:niceLabel(state.trans), icon:'trans'});
      if (state.color){
        chips.push({label:'Color', value:state.color, icon:'palette', colorValue: colorValueFromName(state.color)});
      }
      if (state.condition){
        chips.push({label:'Condition', value:niceLabel(state.condition), icon:'shield'});
      }
      if (!chips.length){
        [70, 45].forEach(w=>{
          const bar = document.createElement('div');
          bar.className = 'previewPlaceholderBar';
          bar.style.width = w + '%';
          wrap.appendChild(bar);
        });
        return;
      }
      chips.forEach(ch=>{
        const chip = document.createElement('div');
        chip.className = 'previewChip';
        const bg = ch.colorValue || pastelFromKey((ch.label || '') + (ch.value || ''));
        const ink = isLightColor(bg) ? '#0f172a' : '#f8fafc';
        chip.classList.add('colorful');
        chip.style.setProperty('--chip-color', bg);
        chip.style.setProperty('--chip-ink', ink);
        const icon = document.createElement('span');
        icon.className = 'chipIcon';
        icon.innerHTML = duotoneIcon(ch.icon || 'car');
        const label = document.createElement('span');
        label.className = 'chipLabel';
        label.textContent = ch.label;
        const val = document.createElement('span');
        val.textContent = ch.value;
        chip.appendChild(icon);
        chip.appendChild(label);
        chip.appendChild(val);
        wrap.appendChild(chip);
      });
    }

    function renderFeatureChips(){
      const wrap = $('previewFeatureChips');
      if (!wrap) return;
      wrap.innerHTML = '';
      const selected = state.selectedFeatures || [];
      if (!selected.length){
        [60, 35].forEach(w=>{
          const bar = document.createElement('div');
          bar.className = 'previewPlaceholderBar';
          bar.style.width = w + '%';
          wrap.appendChild(bar);
        });
        return;
      }
      const labelMap = {};
      (state.features || []).forEach(f=>{ labelMap[f.tag] = f.label || f.tag; });
      selected.slice(0,4).forEach(tag=>{
        const chip = document.createElement('div');
        chip.className = 'previewChip';
        const text = labelMap[tag] || tag;
        const bg = pastelFromKey(text);
        const ink = isLightColor(bg) ? '#0f172a' : '#f8fafc';
        chip.classList.add('colorful');
        chip.style.setProperty('--chip-color', bg);
        chip.style.setProperty('--chip-ink', ink);
        chip.textContent = text;
        wrap.appendChild(chip);
      });
      if (selected.length > 4){
        const more = document.createElement('div');
        more.className = 'previewChip';
        more.textContent = `+${selected.length - 4} more`;
        wrap.appendChild(more);
      }
    }

    function renderSaleBlock(){
      const wrap = $('previewSale');
      if (!wrap) return;
      wrap.innerHTML = '';
      const title = document.createElement('div');
      title.className = 'previewSaleTitle';
      title.textContent = 'Payment options';
      wrap.appendChild(title);

      const badges = document.createElement('div');
      badges.className = 'previewSaleBadges';
      const saleOpts = [
        {key:'cash', label:'Cash'},
        {key:'hp', label:'Hire purchase'},
        {key:'trade', label:'Trade-in'},
        {key:'external', label:'External finance'}
      ];
      let hasSale = false;
      saleOpts.forEach(opt=>{
        if (state.sale[opt.key]){
          hasSale = true;
          const badge = document.createElement('div');
          badge.className = 'previewSaleBadge';
          badge.textContent = opt.label;
          badges.appendChild(badge);
        }
      });
      if (hasSale){
        wrap.appendChild(badges);
      } else {
        [65, 40].forEach(w=>{
          const bar = document.createElement('div');
          bar.className = 'previewPlaceholderBar';
          bar.style.width = w + '%';
          wrap.appendChild(bar);
        });
      }

      if (state.sale.hp){
        const summary = document.createElement('div');
        summary.className = 'hpPlanSummary';
        summary.innerHTML = `<div>${hpSummaryText()}</div>${hpMonthlyAmount() ? `<div class="mt-1 text-[12px] opacity-80">Approx. ${formatKesSafe(hpMonthlyAmount(), 'KES 0')}/month after deposit.</div>`:''}`;
        wrap.appendChild(summary);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'previewHpBtn';
        btn.innerHTML = `View HP schedule${ICON_ARROW_RIGHT}`;
        btn.addEventListener('click', openHpScheduleModal);
        wrap.appendChild(btn);
      }
    }

    function hpMonthlyAmount(){
      if (!state.sale.hp) return null;
      if (!state.price || !state.hp.months) return null;
      const deposit = state.hp.deposit ? Number(state.hp.deposit) : 0;
      const financed = Math.max(Number(state.price) - deposit, 0);
      if (!financed || !state.hp.months) return null;
      return Math.round(financed / state.hp.months);
    }
    function hpSummaryText(){
      const deposit = state.hp.deposit ? formatKesSafe(state.hp.deposit) : 'Deposit TBD';
      const months = state.hp.months || 0;
      const tenor = months ? `${months} month${months === 1 ? '' : 's'}` : 'Tenor TBD';
      const monthly = hpMonthlyAmount();
      const monthlyText = monthly ? ` • ${formatKesSafe(monthly)}/mo` : '';
      return `${deposit} • ${tenor}${monthlyText}`;
    }

    /* ---------- Data loads ---------- */
    async function loadBase(){
      const [towns, makes, opt] = await Promise.all([
        apiGet({a:'towns'}),
        apiGet({a:'makes'}),
        apiGet({a:'options_get'})
      ]);
      state.towns = towns.towns || [];
      state.makes = makes.makes || [];
      state.features = opt.features || [];
    }

    async function loadModels(makeId){
      const data = await apiGet({a:'models', make_id: makeId});
      state.models = data.models || [];
    }
    async function loadYears(modelId){
      const data = await apiGet({a:'model_years', model_id: modelId});
      state.years = data.years || [];
    }
    async function loadBodies(modelId){
      const data = await apiGet({a:'model_bodies', model_id: modelId});
      state.bodies = data.bodies || [];
    }
    async function loadYards(dealerId){
      const data = await apiGet({a:'yards', dealer_id: dealerId});
      return data.yards || [];
    }

    /* ---------- Renderers ---------- */
    function setQuestion(title, sub){
      $('qTitle').textContent = title;
      $('qSub').textContent = sub || '';
    }
    function setBody(html){
      $('qBody').innerHTML = html;
    }
    function setBodyNode(node){
      const b = $('qBody');
      b.innerHTML = '';
      b.appendChild(node);
    }

    function renderDealer(){
      setQuestion("Who is the dealer?", "Enter phone once. We will load or create the dealer account.");
      const box = document.createElement('div');
      box.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <div class="label">Dealer phone (07 / 01 / +254)</div>
            <input class="field mt-2" id="dealerPhone" placeholder="0712 000 000 or +254 712..." autocomplete="off">
          </div>
          <div>
            <div class="label">Dealer Name</div>
            <input class="field mt-2" id="dealerName" placeholder="Optional" autocomplete="off">
          </div>
        </div>
        <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
          <div class="selectedCard" id="dealerCard" style="${state.dealer ? '' : 'display:none;'}"></div>
          <div class="flex flex-wrap items-center justify-end gap-3" style="flex:1; min-width:220px;">
            <div class="qSub">Status: <span class="text-white/85 font-extrabold" id="dealerStatus">${state.dealer ? escapeHtml(state.dealer.full_name) : 'Not selected'}</span></div>
          <button class="btn btnSolid" type="button" id="btnDealerLookup">Lookup / Create<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M3 10a7 7 0 1 1 2 5l-2 3" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
          </div>
        </div>
      `;
      setBodyNode(box);

      $('dealerPhone').value = state.dealer ? (state.dealer.phone_e164 || '') : '';

      const renderDealerCard = ()=>{
        const card = $('dealerCard');
        if (!card) return;
        if (!state.dealer){
          card.style.display = 'none';
          card.innerHTML = '';
          return;
        }
        card.style.display = 'flex';
        card.innerHTML = `
          <div class="scLabel">Dealer</div>
          <div class="scValue">${escapeHtml(state.dealer.full_name)}</div>
          <div class="scSub">${escapeHtml(state.dealer.phone_e164 || '')}</div>
        `;
      };
      renderDealerCard();

      $('btnDealerLookup').addEventListener('click', async ()=>{
        try{
          const fd = new FormData();
          fd.append('phone', $('dealerPhone').value.trim());
          fd.append('name', $('dealerName').value.trim());
          const data = await apiPost('dealer_lookup', fd);
          state.dealer = data.dealer;
          $('dealerStatus').textContent = state.dealer.full_name;
          $('dealerPhone').value = state.dealer.phone_e164 || '';
          renderDealerCard();
          toast(data.created ? "Dealer created" : "Dealer loaded", state.dealer.full_name);
          updateSummary();
          // auto advance
          next();
        }catch(e){
          toast("Dealer error", e.message);
        }
      });
    }

    function renderYard(){
      setQuestion("Which yard?", "Optional. If the dealer has multiple yards, choose one or add a new yard.");
      const wrap = document.createElement('div');

      const hint = document.createElement('div');
      hint.className = 'qSub';
      hint.innerHTML = state.dealer
        ? `Serving dealer <span class="dealerAccent">${escapeHtml(state.dealer.full_name)}</span>`
        : 'Dealer not selected. Go back and select dealer.';
      wrap.appendChild(hint);

      const controlRow = document.createElement('div');
      controlRow.className = 'mt-4 flex flex-wrap items-start justify-between gap-3';

      const card = document.createElement('div');
      card.className = 'selectedCard';
      card.id = 'yardCard';
      controlRow.appendChild(card);

      const controlButtons = document.createElement('div');
      controlButtons.className = 'flex flex-wrap items-center gap-2';
      const addBtn = document.createElement('button');
      addBtn.className = 'btnAdd';
      addBtn.type = 'button';
      addBtn.innerHTML = `Add yard${ICON_ARROW_RIGHT}`;
      controlButtons.appendChild(addBtn);
      controlRow.appendChild(controlButtons);
      wrap.appendChild(controlRow);

      const listWrap = document.createElement('div');
      listWrap.className = 'pillGrid';
      listWrap.id = 'yardPills';
      listWrap.style.marginTop = '14px';
      wrap.appendChild(listWrap);

      setBodyNode(wrap);

      const updateYardCard = ()=>{
        const cardEl = $('yardCard');
        if (!cardEl) return;
        if (!state.yard){
          cardEl.style.display = 'none';
          cardEl.innerHTML = '';
          return;
        }
        cardEl.style.display = 'flex';
        cardEl.innerHTML = `
          <div class="scLabel">Yard</div>
          <div class="scValue">${escapeHtml(state.yard.yard_name)}</div>
          <div class="scSub">${escapeHtml(state.yard.town_name || 'Dealer yard')}</div>
        `;
      };
      updateYardCard();

      if (!state.dealer){
        toast("Missing dealer", "Go back to pick dealer first.");
        return;
      }

      (async ()=>{
        try{
          const yards = await loadYards(state.dealer.id);
          const pillGrid = $('yardPills');
          pillGrid.innerHTML = '';

          // "No yard" option
          const none = pillEl({id:'', title:'No Yard', sub:'Skip yard attachment', kind:'car', key:'no-yard', selected: !state.yard});
          none.addEventListener('click', ()=>{
            selectSingleInGrid(pillGrid, none);
            state.yard = null;
            updateSummary();
            updateYardCard();
            next();
          });
          pillGrid.appendChild(none);

          yards.forEach(y=>{
            const isSel = state.yard && String(state.yard.id) === String(y.id);
            const p = pillEl({
              id: y.id,
              title: y.yard_name,
              sub: y.town_name,
              kind: 'pin',
              key: 'yard-'+y.id,
              selected: isSel
            });
            p.addEventListener('click', ()=>{
              selectSingleInGrid(pillGrid, p);
              state.yard = {id: y.id, yard_name: y.yard_name, town_id: y.town_id, town_name: y.town_name};
              updateSummary();
              updateYardCard();
              next();
            });
            pillGrid.appendChild(p);
          });

        }catch(e){
          toast("Yards error", e.message);
        }
      })();

      addBtn.addEventListener('click', ()=>{
        if (!state.dealer) return;

        const townOpts = state.towns.map(t=>`<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');
        openModal("Add yard", "Saved into car_yards (Title Case).", `
          <div>
            <div class="label">Yard name</div>
            <input class="field mt-2" id="mYardName" placeholder="e.g. Uptown Autos">
            <div class="label mt-4">Town</div>
            <select class="field mt-2" id="mYardTown">
              <option value="">Select town…</option>
              ${townOpts}
            </select>

            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveYard" type="button">Save yard${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelYard" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);

        $('mCancelYard').addEventListener('click', closeModal);
        $('mSaveYard').addEventListener('click', async ()=>{
          try{
            const name = $('mYardName').value.trim();
            const townId = $('mYardTown').value;
            if (!name) throw new Error('Yard name required');
            if (!townId) throw new Error('Town required');

            const fd = new FormData();
            fd.append('dealer_id', state.dealer.id);
            fd.append('yard_name', name);
            fd.append('town_id', townId);

            const data = await apiPost('yard_add', fd);
            closeModal();
            toast("Yard saved", data.yard_name || name);

            // refresh step
            fadeTo(()=>renderYard());

          }catch(e){
            toast("Add yard error", e.message);
          }
        });
      });
    }

    function renderMake(){
      setQuestion("What is your car make?", "Tap a make. Or add a new make if missing.");
      const wrap = document.createElement('div');

      const top = document.createElement('div');
      top.className = 'flex items-center justify-between gap-2 flex-wrap';
      top.innerHTML = `
        <button class="btnAdd" type="button" id="btnAddMake">Add make${ICON_ARROW_RIGHT}</button>
      `;
      wrap.appendChild(top);

      const grid = document.createElement('div');
      grid.className = 'mt-4 pillGrid';
      grid.id = 'makeGrid';
      wrap.appendChild(grid);

      setBodyNode(wrap);

      const g = $('makeGrid');
      g.innerHTML = '';
      state.makes.forEach(m=>{
        const p = pillEl({id: m.id, title: m.name, sub:'Make', kind:'car', key:'make-'+m.id, selected: state.make && String(state.make.id)===String(m.id)});
        p.addEventListener('click', async ()=>{
          selectSingleInGrid(g, p);
          state.make = {id: m.id, name: m.name};
          // reset downstream
          state.model = null; state.year = null; state.body = null;
          state.models = []; state.years = []; state.bodies = [];
          await loadModels(m.id);
          updateSummary();
          next();
        });
        g.appendChild(p);
      });
      ensureStepAdvanceFallback(g, ()=> next());

      $('btnAddMake').addEventListener('click', ()=>{
        openModal("Add make", "Saved into vehicle_makes (Title Case).", `
          <div>
            <div class="label">Make name</div>
            <input class="field mt-2" id="mMakeName" placeholder="e.g. Toyota">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveMake" type="button">Save make${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelMake" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);
        $('mCancelMake').addEventListener('click', closeModal);
        $('mSaveMake').addEventListener('click', async ()=>{
          try{
            const name = $('mMakeName').value.trim();
            if (!name) throw new Error('Make name required');

            const fd = new FormData();
            fd.append('name', name);
            const data = await apiPost('make_add', fd);
            closeModal();
            toast("Make saved", data.name);

            // reload makes and re-render step
            const makes = await apiGet({a:'makes'});
            state.makes = makes.makes || [];
            fadeTo(()=>renderMake());
          }catch(e){
            toast("Add make error", e.message);
          }
        });
      });
    }

    function renderModel(){
      if (!state.make){
        setQuestion("Pick make first", "Go back one step.");
        setBody(`<div class="qSub">Make is missing.</div>`);
        return;
      }
      setQuestion(`What model is the ${state.make.name}?`, "Tap a model. Or add a new model for this make.");
      const wrap = document.createElement('div');

      const top = document.createElement('div');
      top.className = 'flex items-center justify-between gap-2 flex-wrap';
      top.innerHTML = `
        <button class="btnAdd" type="button" id="btnAddModel">Add model${ICON_ARROW_RIGHT}</button>
      `;
      wrap.appendChild(top);

      const grid = document.createElement('div');
      grid.className = 'mt-4 pillGrid';
      grid.id = 'modelGrid';
      wrap.appendChild(grid);

      setBodyNode(wrap);

      const g = $('modelGrid');
      g.innerHTML = '';
      state.models.forEach(m=>{
        const p = pillEl({id: m.id, title: m.name, sub:'Model', kind:'car', key:'model-'+m.id, selected: state.model && String(state.model.id)===String(m.id)});
        p.addEventListener('click', async ()=>{
          selectSingleInGrid(g, p);
          state.model = {id: m.id, name: m.name};
          // reset downstream
          state.year = null; state.body = null;
          state.years = []; state.bodies = [];
          await Promise.all([loadYears(m.id), loadBodies(m.id)]);
          updateSummary();
          next();
        });
        g.appendChild(p);
      });
      ensureStepAdvanceFallback(g, ()=> next());

      $('btnAddModel').addEventListener('click', ()=>{
        openModal("Add model", `Saved into vehicle_models for ${state.make.name} (Title Case).`, `
          <div>
            <div class="label">Model name</div>
            <input class="field mt-2" id="mModelName" placeholder="e.g. Vitz">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveModel" type="button">Save model${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelModel" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);
        $('mCancelModel').addEventListener('click', closeModal);
        $('mSaveModel').addEventListener('click', async ()=>{
          try{
            const name = $('mModelName').value.trim();
            if (!name) throw new Error('Model name required');

            const fd = new FormData();
            fd.append('make_id', state.make.id);
            fd.append('name', name);
            const data = await apiPost('model_add', fd);
            closeModal();
            toast("Model saved", data.name);

            await loadModels(state.make.id);
            fadeTo(()=>renderModel());
          }catch(e){
            toast("Add model error", e.message);
          }
        });
      });
    }

    function renderYear(){
      if (!state.model){
        setQuestion("Pick model first", "Go back one step.");
        setBody(`<div class="qSub">Model is missing.</div>`);
        return;
      }
      setQuestion("What year is the car?", "Tap the year. Or add a year for this model.");
      const wrap = document.createElement('div');

      const top = document.createElement('div');
      top.className = 'flex items-center justify-between gap-2 flex-wrap';
      top.innerHTML = `<button class="btnAdd" type="button" id="btnAddYear">Add year${ICON_ARROW_RIGHT}</button>`;
      wrap.appendChild(top);

      const grid = document.createElement('div');
      grid.className = 'mt-4 pillGrid';
      grid.id = 'yearGrid';
      wrap.appendChild(grid);

      setBodyNode(wrap);

      const g = $('yearGrid');
      g.innerHTML = '';

      // If years list empty, encourage adding
      const years = (state.years || []).slice().sort((a,b)=>a-b);
      years.forEach(y=>{
        const p = pillEl({id: y, title: String(y), sub:'Year', kind:'tag', key:'year-'+y, selected: state.year===y});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(g, p);
          state.year = y;
          updateSummary();
          next();
        });
        g.appendChild(p);
      });
      ensureStepAdvanceFallback(g, ()=> next());

      $('btnAddYear').addEventListener('click', ()=>{
        openModal("Add year", "Saved into vehicle_model_years for this model.", `
          <div>
            <div class="label">Year</div>
            <input class="field mt-2" id="mYearVal" placeholder="e.g. 2014" inputmode="numeric">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveYear" type="button">Save year${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelYear" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);
        $('mCancelYear').addEventListener('click', closeModal);
        $('mSaveYear').addEventListener('click', async ()=>{
          try{
            const v = $('mYearVal').value.trim();
            const y = Number(v);
            if (!y || y < 1900 || y > 2100) throw new Error('Enter a valid year');

            const fd = new FormData();
            fd.append('model_id', state.model.id);
            fd.append('year', String(y));
            await apiPost('model_year_add', fd);

            closeModal();
            toast("Year saved", String(y));

            await loadYears(state.model.id);
            fadeTo(()=>renderYear());
          }catch(e){
            toast("Add year error", e.message);
          }
        });
      });
    }

    function renderBody(){
      if (!state.model){
        setQuestion("Pick model first", "Go back one step.");
        setBody(`<div class="qSub">Model is missing.</div>`);
        return;
      }
      setQuestion("What body type is it?", "Tap the body type. Or add a body type for this model.");
      const wrap = document.createElement('div');

      const top = document.createElement('div');
      top.className = 'flex items-center justify-between gap-2 flex-wrap';
      top.innerHTML = `<button class="btnAdd" type="button" id="btnAddBody">Add body type${ICON_ARROW_RIGHT}</button>`;
      wrap.appendChild(top);

      const grid = document.createElement('div');
      grid.className = 'mt-4 pillGrid';
      grid.id = 'bodyGrid';
      wrap.appendChild(grid);

      setBodyNode(wrap);

      const g = $('bodyGrid');
      g.innerHTML = '';

      (state.bodies || []).forEach(b=>{
        const p = pillEl({id: b, title: b, sub:'Body', kind:'car', key:'body-'+b, selected: state.body===b});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(g, p);
          state.body = b;
          updateSummary();
          next();
        });
        g.appendChild(p);
      });
      ensureStepAdvanceFallback(g, ()=> next());

      $('btnAddBody').addEventListener('click', ()=>{
        openModal("Add body type", "Saved into vehicle_model_bodies (Title Case).", `
          <div>
            <div class="label">Body type</div>
            <input class="field mt-2" id="mBodyVal" placeholder="e.g. SUV">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveBody" type="button">Save body${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelBody" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);
        $('mCancelBody').addEventListener('click', closeModal);
        $('mSaveBody').addEventListener('click', async ()=>{
          try{
            const v = $('mBodyVal').value.trim();
            if (!v) throw new Error('Body type required');

            const fd = new FormData();
            fd.append('model_id', state.model.id);
            fd.append('body_type', v);
            const data = await apiPost('model_body_add', fd);

            closeModal();
            toast("Body saved", data.body_type);

            await loadBodies(state.model.id);
            fadeTo(()=>renderBody());
          }catch(e){
            toast("Add body error", e.message);
          }
        });
      });
    }

    function renderTown(){
      setQuestion("Where is the vehicle located?", "Tap the town. Or add a town if missing.");
      const wrap = document.createElement('div');

      const top = document.createElement('div');
      top.className = 'flex items-center justify-between gap-2 flex-wrap';
      top.innerHTML = `<button class="btnAdd" type="button" id="btnAddTown">Add town${ICON_ARROW_RIGHT}</button>`;
      wrap.appendChild(top);

      const grid = document.createElement('div');
      grid.className = 'mt-4 pillGrid';
      grid.id = 'townGrid';
      wrap.appendChild(grid);

      setBodyNode(wrap);

      const g = $('townGrid');
      g.innerHTML = '';

      state.towns.forEach(t=>{
        const p = pillEl({id: t.id, title: t.name, sub:'Town', kind:'pin', key:'town-'+t.id, selected: state.town && String(state.town.id)===String(t.id)});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(g, p);
          state.town = {id: t.id, name: t.name};
          updateSummary();
          next();
        });
        g.appendChild(p);
      });
      ensureStepAdvanceFallback(g, ()=> next());

      $('btnAddTown').addEventListener('click', ()=>{
        openModal("Add town", "Saved into towns (Title Case).", `
          <div>
            <div class="label">Town name</div>
            <input class="field mt-2" id="mTownName" placeholder="e.g. Nairobi West">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveTown" type="button">Save town${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelTown" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);
        $('mCancelTown').addEventListener('click', closeModal);
        $('mSaveTown').addEventListener('click', async ()=>{
          try{
            const name = $('mTownName').value.trim();
            if (!name) throw new Error('Town name required');

            const fd = new FormData();
            fd.append('name', name);
            const data = await apiPost('town_add', fd);
            closeModal();
            toast("Town saved", data.name);

            const towns = await apiGet({a:'towns'});
            state.towns = towns.towns || [];
            fadeTo(()=>renderTown());
          }catch(e){
            toast("Add town error", e.message);
          }
        });
      });
    }

    function renderPricing(){
      setQuestion("What is the cash price?", "Enter the amount once. Listings default to 30-day expiry with sponsorship off.");
      setBody(`
        <div>
          <div class="label">Cash price (KES)</div>
          <input class="field mt-2" id="priceKes" inputmode="numeric" placeholder="e.g. 780000" value="${state.price ?? ''}">
        </div>
        <div class="infoNote mt-4">
          <div class="infoTitle">Auto settings</div>
          <div class="infoText">Expiry is fixed at 30 days and Sponsorship stays off for quick publishing. Adjust later in CMS if needed.</div>
        </div>
      `);

      $('priceKes').addEventListener('input', ()=>{
        const v = $('priceKes').value.replace(/[^\d]/g,'');
        $('priceKes').value = v;
        state.price = v ? Number(v) : null;
        updateSummary();
      });
    }

    function renderEngineStep(){
      setQuestion("Engine & mileage", "Enter at least the engine capacity.");
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div class="specSection">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div class="label">Engine (cc)</div>
              <input class="field mt-2" id="engineCc" inputmode="numeric" placeholder="e.g. 1500" value="${state.engine ?? ''}">
            </div>
            <div>
              <div class="label">Mileage (optional)</div>
              <input class="field mt-2" id="mileageKm" inputmode="numeric" placeholder="e.g. 112000" value="${state.mileage ?? ''}">
            </div>
          </div>
        </div>
      `;
      setBodyNode(wrap);
      $('engineCc').addEventListener('input', ()=>{
        const v = $('engineCc').value.replace(/[^\d]/g,'');
        $('engineCc').value = v;
        state.engine = v ? Number(v) : null;
        updateSummary();
      });
      $('mileageKm').addEventListener('input', ()=>{
        const v = $('mileageKm').value.replace(/[^\d]/g,'');
        $('mileageKm').value = v;
        state.mileage = v ? Number(v) : null;
        updateSummary();
      });
    }

    function renderFuelStep(){
      setQuestion("Fuel type", "Tap one option. Add more in settings later.");
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div class="specSection">
          <div class="label">Fuel type</div>
          <div class="mt-2 pillGrid" id="fuelGrid"></div>
        </div>
      `;
      setBodyNode(wrap);
      const fuelOpts = [
        {v:'petrol', t:'Petrol', k:'fuel', kind:'fuel'},
        {v:'diesel', t:'Diesel', k:'fuel', kind:'fuel'},
        {v:'hybrid', t:'Hybrid', k:'fuel', kind:'fuel'},
        {v:'electric', t:'Electric', k:'fuel', kind:'fuel'},
        {v:'other', t:'Other', k:'fuel', kind:'fuel'},
      ];
      const fg = $('fuelGrid');
      fuelOpts.forEach(o=>{
        const p = pillEl({id:o.v, title:o.t, sub:'Tap to select', kind:o.kind, key:'fuel-'+o.v, selected: state.fuel===o.v});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(fg, p);
          state.fuel = o.v;
          updateSummary();
          next();
        });
        fg.appendChild(p);
      });
      ensureStepAdvanceFallback(fg, ()=> next());
    }

    function renderTransmissionStep(){
      setQuestion("Transmission", "Choose what the gearbox is.");
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div class="specSection">
          <div class="label">Transmission</div>
          <div class="mt-2 pillGrid" id="transGrid"></div>
        </div>
      `;
      setBodyNode(wrap);
      const transOpts = [
        {v:'automatic', t:'Automatic', kind:'trans'},
        {v:'manual', t:'Manual', kind:'trans'},
        {v:'other', t:'Other', kind:'trans'},
      ];
      const tg = $('transGrid');
      transOpts.forEach(o=>{
        const p = pillEl({id:o.v, title:o.t, sub:'Tap to select', kind:o.kind, key:'trans-'+o.v, selected: state.trans===o.v});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(tg, p);
          state.trans = o.v;
          updateSummary();
          next();
        });
        tg.appendChild(p);
      });
      ensureStepAdvanceFallback(tg, ()=> next());
    }

    function renderColorStep(){
      setQuestion("Color", "Tap a color swatch or add your own.");
      const colors = [
        "White","Black","Silver","Grey","Blue","Red","Green","Beige","Brown","Gold","Orange","Purple"
      ];
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div class="specSection">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <div>
              <div class="label">Body color</div>
              <div class="qSub mt-1">Applies to listing card and preview.</div>
            </div>
            <button class="btnAdd" type="button" id="btnAddColor">Add custom color${ICON_ARROW_RIGHT}</button>
          </div>
          <div class="mt-2 colorSwatchGrid" id="colorGrid"></div>
        </div>
      `;
      setBodyNode(wrap);
      const cg = $('colorGrid');
      function renderColors(arr){
        cg.innerHTML = '';
        arr.forEach(c=>{
          const sw = document.createElement('button');
          sw.type = 'button';
          sw.className = 'colorSwatch';
          sw.setAttribute('aria-selected', state.color===c ? 'true':'false');
          const cssColor = colorValueFromName(c);
          const ink = isLightColor(cssColor) ? '#0f172a' : '#f8fafc';
          sw.style.setProperty('--swatch-color', cssColor);
          sw.style.setProperty('--swatch-ink', ink);
          sw.innerHTML = `
            <div class="colorSwatchMeta">
              <div class="colorName">${escapeHtml(c)}</div>
              <div class="colorHint">${state.color===c ? 'Selected' : 'Tap to select'}</div>
            </div>
          `;
          sw.addEventListener('click', ()=>{
            state.color = c;
            renderColors(arr);
            updateSummary();
            next();
          });
          cg.appendChild(sw);
        });
      }
      renderColors(colors);
      $('btnAddColor').addEventListener('click', ()=>{
        openModal("Add color", "Stored only on this listing.", `
          <div>
            <div class="label">Color</div>
            <input class="field mt-2" id="mColorVal" placeholder="e.g. Pearl White">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveColor" type="button">Use color${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelColor" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);
        $('mCancelColor').addEventListener('click', closeModal);
        $('mSaveColor').addEventListener('click', ()=>{
          const v = $('mColorVal').value.trim();
          if (!v){ toast("Color required", "Enter a color name."); return; }
          const tc = v.split(/\s+/).map(w=>w.charAt(0).toUpperCase()+w.slice(1).toLowerCase()).join(' ');
          closeModal();
          if (!colors.includes(tc)) colors.unshift(tc);
          state.color = tc;
          renderColors(colors);
          updateSummary();
          toast("Color selected", tc);
        });
      });
      ensureStepAdvanceFallback(cg, ()=> next());
    }

    function renderExtrasStep(){
      setQuestion("Extras", "Add optional text to enrich the listing.");
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div class="specSection">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div class="label">Title (optional)</div>
              <input class="field mt-2" id="title" placeholder="${escapeHtml(autoTitle())}" value="${escapeHtml(state.title || autoTitle())}">
            </div>
            <div>
              <div class="label">Trim (optional)</div>
              <input class="field mt-2" id="trim" placeholder="e.g. G, RS, XLE" value="${escapeHtml(state.trim || '')}">
            </div>
            <div class="md:col-span-2">
              <div class="label">Description (optional)</div>
              <textarea class="field mt-2" id="desc" rows="3" placeholder="Optional notes...">${escapeHtml(state.description || '')}</textarea>
            </div>
          </div>
          <div class="infoNote mt-4">
            <div class="infoTitle">Auto title</div>
            <div class="infoText" id="autoTitlePreview">${escapeHtml(autoTitle())}</div>
          </div>
        </div>
      `;
      setBodyNode(wrap);
      $('title').addEventListener('input', ()=>{
        state.title = $('title').value;
        const preview = $('autoTitlePreview');
        if (preview) preview.textContent = autoTitle();
        updateSummary();
      });
      $('trim').addEventListener('input', ()=>{
        state.trim = $('trim').value;
        updateSummary();
      });
      $('desc').addEventListener('input', ()=>{
        state.description = $('desc').value;
        updateSummary();
      });
    }

    function renderConditionStep(){
      setQuestion("Condition", "Used vs new for schema clarity.");
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div class="specSection">
          <div class="label">Condition</div>
          <div class="mt-2 pillGrid" id="condGrid"></div>
        </div>
      `;
      setBodyNode(wrap);
      const condOpts = [
        {v:'used', t:'Used', sub:'Most listings', kind:'car'},
        {v:'new', t:'New', sub:'Showroom', kind:'shield'},
      ];
      const cg = $('condGrid');
      condOpts.forEach(o=>{
        const p = pillEl({id:o.v, title:o.t, sub:o.sub, kind:'car', key:'cond-'+o.v, selected: state.condition===o.v});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(cg, p);
          state.condition = o.v;
          updateSummary();
          next();
        });
        cg.appendChild(p);
      });
    }

    function renderSale(){
      setQuestion("How can the buyer pay?", "Tap to enable sale methods. If HP is enabled, set simple ranges.");
      const wrap = document.createElement('div');

      wrap.innerHTML = `
        <div class="label">Sale methods</div>
        <div class="mt-2 pillGrid" id="saleGrid"></div>

        <div class="mt-5" id="hpBox" style="display:none;">
          <div class="label">Hire purchase terms</div>
          <div class="qSub mt-2">Store a single deposit and tenor. Preview repayment on the live card.</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
            <div>
              <div class="label">Deposit (KES)</div>
              <input class="field mt-2" id="hpDeposit" inputmode="numeric" placeholder="e.g. 200000" value="${state.hp.deposit ?? ''}">
            </div>
            <div>
              <div class="label">Months</div>
              <input class="field mt-2" id="hpMonths" inputmode="numeric" placeholder="12" value="${state.hp.months ?? 12}">
            </div>
            <div class="md:col-span-2">
              <div class="label">HP notes (optional)</div>
              <input class="field mt-2" id="hpNotes" placeholder="Optional" value="${escapeHtml(state.hp.notes || '')}">
            </div>
          </div>
        </div>
      `;
      setBodyNode(wrap);

      const saleOpts = [
        {k:'cash', t:'Cash', sub:'Default', kind:'money'},
        {k:'hp', t:'Hire Purchase', sub:'Installments', kind:'tag'},
        {k:'trade', t:'Trade-In', sub:'Swap/Top-up', kind:'car'},
        {k:'external', t:'External Finance', sub:'Off-platform', kind:'fuel'}
      ];
      const sg = $('saleGrid');
      saleOpts.forEach(o=>{
        const sel = !!state.sale[o.k];
        const p = pillEl({id:o.k, title:o.t, sub:o.sub, kind:o.kind, key:'sale-'+o.k, selected: sel});
        p.addEventListener('click', ()=>{
          const cur = p.getAttribute('aria-selected') === 'true';
          p.setAttribute('aria-selected', cur ? 'false' : 'true');
          state.sale[o.k] = !cur;

          // ensure at least cash stays enabled (business default)
          if (!state.sale.cash && !state.sale.hp && !state.sale.trade && !state.sale.external){
            state.sale.cash = true;
            // force reselect cash
            [...sg.children].forEach(ch=>{
              if (ch.dataset.id === 'cash') ch.setAttribute('aria-selected','true');
            });
          }

          $('hpBox').style.display = state.sale.hp ? '' : 'none';
          updateSummary();
        });
        sg.appendChild(p);
      });
      $('hpBox').style.display = state.sale.hp ? '' : 'none';

      const clampNum = (id)=>{
        const el = $(id);
        el.addEventListener('input', ()=>{
          const v = el.value.replace(/[^\d]/g,'');
          el.value = v;
        });
      };
      clampNum('hpDeposit'); clampNum('hpMonths');
      $('hpDeposit').addEventListener('input', ()=>{
        state.hp.deposit = $('hpDeposit').value ? Number($('hpDeposit').value) : null;
        updateSummary();
      });
      $('hpMonths').addEventListener('input', ()=>{
        state.hp.months = $('hpMonths').value ? Number($('hpMonths').value) : 12;
        updateSummary();
      });
      $('hpNotes').addEventListener('input', ()=>{
        state.hp.notes = $('hpNotes').value;
        updateSummary();
      });

    }

    function renderFeatures(){
      setQuestion("Any features?", "Tap to select multiple. Add a new feature option if missing.");
      const wrap = document.createElement('div');

      wrap.innerHTML = `
        <div class="specSection">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <div>
              <div class="label">Features</div>
              <div class="qSub mt-1">Multi-select quick highlights.</div>
            </div>
            <button class="btnAdd" type="button" id="btnAddFeature">Add feature${ICON_ARROW_RIGHT}</button>
          </div>
          <div class="mt-3 pillGrid" id="featGrid"></div>
        </div>
      `;
      setBodyNode(wrap);

      const g = $('featGrid');
      g.innerHTML = '';
      const selected = new Set(state.selectedFeatures);

      state.features.forEach(f=>{
        const sel = selected.has(f.tag);
        const p = pillEl({id:f.tag, title:f.label || f.tag, sub:'', kind:'tag', key:'feat-'+f.tag, selected: sel});
        const hint = p.querySelector('.s');
        if (hint) hint.remove();
        p.addEventListener('click', ()=>{
          const cur = p.getAttribute('aria-selected') === 'true';
          p.setAttribute('aria-selected', cur ? 'false' : 'true');
          if (cur) selected.delete(f.tag); else selected.add(f.tag);
          state.selectedFeatures = Array.from(selected);
          updateSummary();
        });
        g.appendChild(p);
      });
      ensureStepAdvanceFallback(g, ()=> next());

      $('btnAddFeature').addEventListener('click', ()=>{
        openModal("Add feature", "Saved into settings[pipii_features] (Title Case label).", `
          <div>
            <div class="label">Feature label</div>
            <input class="field mt-2" id="mFeatLabel" placeholder="e.g. Reverse Camera">
            <div class="label mt-4">Tag (optional)</div>
            <input class="field mt-2" id="mFeatTag" placeholder="e.g. reverse_camera">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveFeat" type="button">Save feature${ICON_ARROW_RIGHT}</button>
              <button class="btn btnGhost" id="mCancelFeat" type="button">Cancel${ICON_ARROW_LEFT}</button>
            </div>
          </div>
        `);
        $('mCancelFeat').addEventListener('click', closeModal);
        $('mSaveFeat').addEventListener('click', async ()=>{
          try{
            const label = $('mFeatLabel').value.trim();
            const tag = $('mFeatTag').value.trim();
            if (!label && !tag) throw new Error('Provide label or tag');

            const fd = new FormData();
            fd.append('label', label);
            fd.append('tag', tag);
            const data = await apiPost('feature_add', fd);

            closeModal();
            toast("Feature saved", data.label || data.tag);

            const opt = await apiGet({a:'options_get'});
            state.features = opt.features || [];
            fadeTo(()=>renderFeatures());
          }catch(e){
            toast("Add feature error", e.message);
          }
        });
      });
    }

    function renderPhotos(){
      setQuestion("Add photos", "Drop images or tap to choose. Photos are saved into listing_images on publish.");
      const wrap = document.createElement('div');

      wrap.innerHTML = `
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="qSub">Tip: Add at least 3 for a strong listing.</div>
          <div class="flex gap-2">
            <button class="btnAdd" type="button" id="btnPickPhotos">Add photos${ICON_ARROW_RIGHT}</button>
            <button class="btn btnGhost" type="button" id="btnClearPhotos">Clear${ICON_ARROW_LEFT}</button>
          </div>
        </div>

        <input type="file" accept="image/*" multiple id="photoInput" style="display:none"/>

        <div class="mt-4" id="dropArea"
             style="border-radius:18px;border:1px dashed rgba(255,255,255,.18);background:rgba(255,255,255,.03);padding:14px;">
          <div class="qSub">Drop images here.</div>
          <div class="mt-3 photoGrid" id="photoGrid"></div>
        </div>
      `;
      setBodyNode(wrap);

      function renderGrid(){
        const g = $('photoGrid');
        g.innerHTML = '';
        state.photos.forEach((p, idx)=>{
          const d = document.createElement('div');
          d.className = 'ph';
          d.innerHTML = `
            <img src="${p.url}" alt="photo">
            <div class="x" title="Remove">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                <path d="M7 7l10 10M17 7L7 17" stroke="rgba(255,255,255,.92)" stroke-width="2.6" stroke-linecap="round"/>
              </svg>
            </div>
          `;
          d.querySelector('.x').addEventListener('click', ()=>{
            URL.revokeObjectURL(p.url);
            state.photos.splice(idx, 1);
            renderGrid();
            updateSummary();
          });
          g.appendChild(d);
        });
        if (!state.photos.length) previewSlideIndex = 0;
        updateSummary();
      }
      function addFiles(fileList){
        const files = Array.from(fileList || []).filter(f=>f && f.type && f.type.startsWith('image/'));
        files.forEach((f, i)=>{
          const obj = { file: f, url: URL.createObjectURL(f) };
          state.photos.push(obj);
          if (state.photos.length === files.length && i === 0) previewSlideIndex = 0;
        });
        renderGrid();
      }

      $('btnPickPhotos').addEventListener('click', ()=> $('photoInput').click());
      $('photoInput').addEventListener('change', ()=> addFiles($('photoInput').files));
      $('btnClearPhotos').addEventListener('click', ()=>{
        state.photos.forEach(p=> URL.revokeObjectURL(p.url));
        state.photos = [];
        $('photoInput').value = '';
        renderGrid();
        toast("Cleared", "Photos removed.");
      });

      const drop = $('dropArea');
      ['dragenter','dragover'].forEach(evt=>{
        drop.addEventListener(evt, (e)=>{
          e.preventDefault(); e.stopPropagation();
          drop.style.borderColor = 'rgba(255,255,255,.35)';
        });
      });
      ['dragleave','drop'].forEach(evt=>{
        drop.addEventListener(evt, (e)=>{
          e.preventDefault(); e.stopPropagation();
          drop.style.borderColor = 'rgba(255,255,255,.18)';
        });
      });
      drop.addEventListener('drop', (e)=>{
        const dt = e.dataTransfer;
        if (!dt) return;
        addFiles(dt.files);
      });

      renderGrid();
    }

    function renderReview(){
      setQuestion("Review and list now", "A final quick card. When you tap List Now, the database is populated.");
      const car = (state.make && state.model) ? `${state.make.name} ${state.model.name}` : '—';
      const yb = `${state.year || '—'} • ${state.body || '—'}`;
      const town = state.town ? state.town.name : '—';
      const price = state.price ? `KES ${Number(state.price).toLocaleString()}` : '—';
      const em = `${state.engine ? state.engine+'cc' : '—'} • ${state.mileage ? Number(state.mileage).toLocaleString()+'km' : '—'}`;
      const ft = `${state.fuel || '—'} • ${state.trans || '—'}`;
      const color = state.color || '—';

      const sale = [];
      if (state.sale.cash) sale.push('Cash');
      if (state.sale.hp) sale.push('Hire Purchase');
      if (state.sale.trade) sale.push('Trade-In');
      if (state.sale.external) sale.push('External Finance');

      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <div class="card" style="padding:16px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);box-shadow:none;">
          <div class="text-[14px] font-extrabold">Listing snapshot</div>
          <div class="qSub mt-2">Confirm the essentials. You can go back to fix any step.</div>

          <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-[13px]">
            <div><span class="text-white/55">Dealer:</span> <span class="font-semibold">${escapeHtml(state.dealer ? state.dealer.full_name : '—')}</span></div>
            <div><span class="text-white/55">Yard:</span> <span class="font-semibold">${escapeHtml(state.yard ? state.yard.yard_name : '—')}</span></div>

            <div><span class="text-white/55">Car:</span> <span class="font-semibold">${escapeHtml(car)}</span></div>
            <div><span class="text-white/55">Year/Body:</span> <span class="font-semibold">${escapeHtml(yb)}</span></div>

            <div><span class="text-white/55">Town:</span> <span class="font-semibold">${escapeHtml(town)}</span></div>
            <div><span class="text-white/55">Price:</span> <span class="font-semibold">${escapeHtml(price)}</span></div>

            <div><span class="text-white/55">Engine/Mileage:</span> <span class="font-semibold">${escapeHtml(em)}</span></div>
            <div><span class="text-white/55">Fuel/Trans:</span> <span class="font-semibold">${escapeHtml(ft)}</span></div>

            <div><span class="text-white/55">Color:</span> <span class="font-semibold">${escapeHtml(color)}</span></div>
            <div><span class="text-white/55">Condition:</span> <span class="font-semibold">${escapeHtml(state.condition || 'used')}</span></div>

            <div class="md:col-span-2"><span class="text-white/55">Sale:</span> <span class="font-semibold">${escapeHtml(sale.join(', ') || '—')}</span></div>
            <div class="md:col-span-2"><span class="text-white/55">Features:</span> <span class="font-semibold">${state.selectedFeatures.length ? state.selectedFeatures.length + ' selected' : '—'}</span></div>
            <div class="md:col-span-2"><span class="text-white/55">Photos:</span> <span class="font-semibold">${state.photos.length}</span></div>
          </div>

          <div class="mt-4 hr"></div>

          <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
            <button class="btn btnGhost" type="button" id="btnReviewBack">Go back<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M12 5l-5 5 5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
            <button class="btn btnSolid" type="button" id="btnListNow">List now<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
          </div>
        </div>
      `;
      setBodyNode(wrap);

      $('btnReviewBack').addEventListener('click', ()=> back());

      $('btnListNow').addEventListener('click', async ()=>{
        try{
          // Minimal validation for schema truth:
          if (!state.dealer) throw new Error("Dealer is required");
          if (!state.town) throw new Error("Town is required");
          if (!state.model) throw new Error("Model is required");
          if (!state.year) throw new Error("Year is required");
          if (!state.engine) throw new Error("Engine (cc) is required");
          if (!state.price) throw new Error("Price is required");

          await confirmDealerPublish(state.dealer.full_name || '');

          const fd = new FormData();
          fd.append('dealer_id', state.dealer.id);
          fd.append('yard_id', state.yard ? state.yard.id : '');
          fd.append('town_id', state.town.id);
          fd.append('vehicle_model_id', state.model.id);

          fd.append('year', String(state.year));
          fd.append('engine_cc', String(state.engine));
          fd.append('mileage_km', state.mileage ? String(state.mileage) : '');
          fd.append('cash_price_kes', String(state.price));

          fd.append('fuel_type', state.fuel || '');
          fd.append('transmission', state.trans || '');
          fd.append('body_type', state.body || '');
          fd.append('color', state.color || '');

          fd.append('condition_type', state.condition || 'used');

          fd.append('title', state.title || autoTitle());
          fd.append('trim', state.trim || '');
          fd.append('description', state.description || '');

          fd.append('allows_cash', state.sale.cash ? '1' : '0');
          fd.append('allows_hp', state.sale.hp ? '1' : '0');
          fd.append('allows_trade_in', state.sale.trade ? '1' : '0');
          fd.append('allows_external_financing', state.sale.external ? '1' : '0');

          fd.append('features', JSON.stringify(state.selectedFeatures || []));

          fd.append('hp_on', state.sale.hp ? '1' : '0');
          const hpDepositVal = state.hp.deposit ? String(state.hp.deposit) : '';
          const hpMonthsVal = state.hp.months ? String(state.hp.months) : '';
          fd.append('hp_min_deposit', hpDepositVal);
          fd.append('hp_max_deposit', hpDepositVal);
          fd.append('hp_min_months', hpMonthsVal || '3');
          fd.append('hp_max_months', hpMonthsVal || '3');
          fd.append('hp_notes', state.hp.notes || '');

          state.photos.forEach(p=> fd.append('photos[]', p.file, p.file.name));

          const data = await apiPost('listing_create', fd);
          toast("Listing saved", "ID #" + data.listing_id);

          // Reset for next entry
          hardReset(false);
          stepIndex = 0;
          setProgress();
          fadeTo(()=>renderCurrent());
        }catch(e){
          if (e && e.message === PUBLISH_CANCELLED){
            toast("Publish cancelled", "Listing was not saved.");
            return;
          }
          toast("Cannot list", e?.message || 'Unknown error');
        }
      });
    }

    /* ---------- Helpers ---------- */
    function selectSingleInGrid(grid, selectedEl){
      [...grid.children].forEach(ch=> ch.setAttribute('aria-selected','false'));
      selectedEl.setAttribute('aria-selected','true');
    }
    function ensureStepAdvanceFallback(grid, advanceFn){
      if (!grid) return;
      setTimeout(()=>{
        if (grid.children.length > 0) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btnSolid';
        btn.innerHTML = `Next step<span class="btnIconTail" aria-hidden="true"><svg viewBox="0 0 20 20"><path d="M8 5l5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>`;
        btn.addEventListener('click', advanceFn);
        grid.parentElement?.appendChild(btn);
      }, 0);
    }
    function changePreviewSlide(delta){
      const total = state.photos.length;
      if (!total) return;
      previewSlideIndex = (previewSlideIndex + delta + total) % total;
      updatePreviewMedia();
    }

    function renderCurrent(){
      setProgress();
      updateSummary();

      const step = steps[stepIndex];

      if (step === "dealer") return renderDealer();
      if (step === "yard") return renderYard();
      if (step === "make") return renderMake();
      if (step === "model") return renderModel();
      if (step === "year") return renderYear();
      if (step === "body") return renderBody();
      if (step === "town") return renderTown();
      if (step === "pricing") return renderPricing();
      if (step === "engine") return renderEngineStep();
      if (step === "fuel") return renderFuelStep();
      if (step === "trans") return renderTransmissionStep();
      if (step === "color") return renderColorStep();
      if (step === "extras") return renderExtrasStep();
      if (step === "condition") return renderConditionStep();
      if (step === "sale") return renderSale();
      if (step === "features") return renderFeatures();
      if (step === "photos") return renderPhotos();
      if (step === "review") return renderReview();
    }

    function canNext(){
      const step = steps[stepIndex];
      if (step === "dealer") return !!state.dealer;
      if (step === "yard") return true; // optional
      if (step === "make") return !!state.make;
      if (step === "model") return !!state.model;
      if (step === "year") return !!state.year;
      if (step === "body") return true; // body can be optional in schema, but better if provided
      if (step === "town") return !!state.town;
      if (step === "pricing") return !!state.price;
      if (step === "engine") return !!state.engine;
      if (step === "fuel") return true;
      if (step === "trans") return true;
      if (step === "color") return true;
      if (step === "extras") return true;
      if (step === "condition") return true;
      if (step === "sale") {
        if (state.sale.hp && (!state.hp.deposit || !state.hp.months)) return false;
        return true;
      }
      if (step === "features") return true;
      if (step === "photos") return true;
      if (step === "review") return true;
      return true;
    }

    function next(){
      if (!canNext()){
        toast("Incomplete step", "Please complete this step before continuing.");
        return;
      }
      if (stepIndex < steps.length - 1){
        stepIndex++;
        fadeTo(()=>renderCurrent());
      }
    }

    function back(){
      if (stepIndex > 0){
        stepIndex--;
        fadeTo(()=>renderCurrent());
      }
    }

    function hardReset(showToast=true){
      // revoke photo URLs
      (state.photos || []).forEach(p=>{ try{ URL.revokeObjectURL(p.url); }catch(e){} });

      state.dealer = null;
      state.yard = null;

      state.make = null;
      state.model = null;
      state.year = null;
      state.body = null;
      state.town = null;

      state.price = null;
      state.engine = null;
      state.mileage = null;

      state.fuel = null;
      state.trans = null;
      state.color = null;
      state.condition = "used";

      state.sale = {cash:true, hp:false, trade:false, external:false};
      state.hp = {deposit:null, months:12, notes:''};

      state.selectedFeatures = [];
      state.photos = [];
      state.title = '';
      state.trim = '';
      state.description = '';
      previewSlideIndex = 0;

      if (showToast) toast("Reset", "All selections cleared.");
      updateSummary();
    }

    /* ---------- Nav buttons ---------- */
    $('btnNext').addEventListener('click', next);
    $('btnNextTop').addEventListener('click', next);
    $('mNext').addEventListener('click', next);

    $('btnBack').addEventListener('click', back);
    $('btnBackTop').addEventListener('click', back);
    $('mBack').addEventListener('click', back);

    $('btnSkip').addEventListener('click', ()=>{
      // Skip only for optional steps (yard/body/features/photos)
      const step = steps[stepIndex];
      const optional = new Set(["yard","body","features","photos"]);
      if (!optional.has(step)){
        toast("Cannot skip", "This step is required.");
        return;
      }
      next();
    });

    $('btnReset').addEventListener('click', ()=>{
      hardReset(true);
      stepIndex = 0;
      fadeTo(()=>renderCurrent());
    });
    $('btnClearAll').addEventListener('click', ()=>{
      hardReset(true);
      stepIndex = 0;
      fadeTo(()=>renderCurrent());
    });

    $('btnReview').addEventListener('click', ()=>{
      stepIndex = steps.indexOf("review");
      fadeTo(()=>renderCurrent());
    });

    /* ---------- Init ---------- */
    (async function init(){
      try{
        await loadBase();
        toast("Ready", "Tap through the wizard to add a listing.");
        setProgress();
        renderCurrent();
      }catch(e){
        toast("Init error", e.message);
        setQuestion("Cannot load catalog", "Check DB connection and tables.");
        setBody(`<div class="qSub">Error: ${escapeHtml(e.message)}</div>`);
      }
    })();
  </script>
</body>
</html>
    .infoNote{
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(255,255,255,.03);
      padding: 14px;
    }
    .infoNote .infoTitle{
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .16em;
      color: rgba(255,255,255,.65);
    }
    .infoNote .infoText{
      margin-top: 6px;
      font-size: 13px;
      color: rgba(255,255,255,.78);
      line-height: 1.45;
    }
