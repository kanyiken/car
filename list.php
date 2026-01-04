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

  $phone = clean_str($_POST["phone"] ?? "", 20);
  $name  = title_case(clean_str($_POST["name"] ?? "", 140));
  if ($phone === "") json_out(["ok"=>false,"error"=>"Phone required"], 422);

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
      padding: 12px 14px;
      font-size: 13px;
      font-weight: 700;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      color: var(--ink);
      transition: transform .08s, background .14s, border-color .14s;
      user-select:none;
      min-height: 44px;
    }
    .btn:hover{ background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.18); }
    .btn:active{ transform: translateY(1px); }
    .btnSolid{ background:#fff; color:#000; border-color: rgba(255,255,255,.35); }
    .btnSolid:hover{ background: rgba(255,255,255,.92); }
    .btnGhost{ background: transparent; border-color: rgba(255,255,255,.10); }
    .btnDanger{ background: rgba(244,63,94,.14); border-color: rgba(244,63,94,.30); color: rgba(255,220,230,.95); }
    .btnAdd{
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px dashed rgba(255,255,255,.18);
      background: rgba(255,255,255,.035);
      color: rgba(255,255,255,.78);
      font-weight: 700;
      font-size: 12px;
      min-height: 44px;
    }
    .btnAdd:hover{ border-color: rgba(255,255,255,.28); background: rgba(255,255,255,.05); }

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
      gap: 10px;
      padding: 12px 12px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.035);
      cursor:pointer;
      user-select:none;
      min-height: 54px;
      transition: transform .08s, border-color .14s, background .14s, filter .14s;
      overflow:hidden;
    }
    .pill:hover{ border-color: rgba(255,255,255,.18); background: rgba(255,255,255,.055); }
    .pill:active{ transform: translateY(1px); }
    .pill[aria-selected="true"]{
      border-color: rgba(255,255,255,.38);
      background: rgba(255,255,255,.11);
    }
    .pill .ic{
      width: 34px; height: 34px;
      border-radius: 14px;
      display:flex; align-items:center; justify-content:center;
      border: 1px solid rgba(255,255,255,.14);
      overflow:hidden;
      flex: 0 0 auto;
      box-shadow: 0 18px 55px rgba(0,0,0,.26);
    }
    .pill .tx{ min-width:0; }
    .pill .tx .t{ font-size: 13px; font-weight: 800; color: rgba(255,255,255,.92); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .pill .tx .s{ font-size: 11px; color: rgba(255,255,255,.62); margin-top: 1px; }

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
          <button class="btn btnGhost" type="button" id="btnReset">Reset</button>
          <button class="btn" type="button" id="btnBackTop">Back</button>
          <button class="btn btnSolid" type="button" id="btnNextTop">Next</button>
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
          <div class="stepChip" id="stepChip">Step 1/12</div>
        </div>

        <div class="mt-5 hr"></div>

        <div id="qWrap" class="fade mt-5">
          <div class="qTitle" id="qTitle">Loading…</div>
          <div class="qSub" id="qSub">Please wait.</div>

          <div class="mt-5" id="qBody">
            <!-- dynamic -->
          </div>

          <div class="mt-6 flex items-center justify-between gap-3 hidden lg:flex" id="deskNav">
            <button class="btn btnGhost" type="button" id="btnBack">Back</button>
            <div class="flex items-center gap-2">
              <button class="btn btnGhost" type="button" id="btnSkip">Skip</button>
              <button class="btn btnSolid" type="button" id="btnNext">Next</button>
            </div>
          </div>
        </div>
      </section>

      <!-- SIDE SUMMARY -->
      <aside class="card panelPad">
        <div class="text-[13px] font-extrabold">Live summary</div>
        <div class="qSub mt-2">What you have selected so far.</div>

        <div class="mt-4 space-y-3 text-[13px]">
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Dealer</div>
            <div class="text-white/90 font-semibold text-right" id="sumDealer">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Yard</div>
            <div class="text-white/90 font-semibold text-right" id="sumYard">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Make / Model</div>
            <div class="text-white/90 font-semibold text-right" id="sumCar">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Year / Body</div>
            <div class="text-white/90 font-semibold text-right" id="sumYearBody">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Town</div>
            <div class="text-white/90 font-semibold text-right" id="sumTown">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Price</div>
            <div class="text-white/90 font-semibold text-right" id="sumPrice">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Engine / Mileage</div>
            <div class="text-white/90 font-semibold text-right" id="sumEngineMileage">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Fuel / Trans</div>
            <div class="text-white/90 font-semibold text-right" id="sumFuelTrans">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Color</div>
            <div class="text-white/90 font-semibold text-right" id="sumColor">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Sale Methods</div>
            <div class="text-white/90 font-semibold text-right" id="sumSale">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Features</div>
            <div class="text-white/90 font-semibold text-right" id="sumFeatures">—</div>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="text-white/60">Photos</div>
            <div class="text-white/90 font-semibold text-right" id="sumPhotos">0</div>
          </div>
        </div>

        <div class="mt-5 hr"></div>

        <div class="mt-5">
          <div class="text-[13px] font-extrabold">Fast actions</div>
          <div class="qSub mt-2">Use only when needed.</div>
          <div class="mt-3 flex flex-wrap gap-2">
            <button class="btn btnGhost" type="button" id="btnReview">Review</button>
            <button class="btn btnDanger" type="button" id="btnClearAll">Clear all</button>
          </div>
        </div>
      </aside>
    </div>
  </main>

  <!-- Mobile nav -->
  <div class="mobileNav">
    <div class="mobileNavInner">
      <button class="btn btnGhost" type="button" id="mBack" style="flex:1;">Back</button>
      <button class="btn btnSolid" type="button" id="mNext" style="flex:2;">Next</button>
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
    function duotoneIcon(kind){
      // Simple duotone-ish icons: two paths with different opacities.
      // kind: car, pin, tag, bolt, gear, fuel, trans, palette, money, camera, shield
      const common = 'fill="none" stroke="rgba(0,0,0,.92)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"';
      switch(kind){
        case 'pin': return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M12 21s7-4.6 7-11a7 7 0 1 0-14 0c0 6.4 7 11 7 11Z"/>
            <path ${common} opacity=".55" d="M12 10a2.3 2.3 0 1 0 0 .1Z"/>
          </svg>`;
        case 'tag': return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M20 13l-7 7-11-11V2h7l11 11Z"/>
            <path ${common} opacity=".55" d="M7.5 7.5h.01"/>
          </svg>`;
        case 'money': return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M3 7h18v10H3z"/>
            <path ${common} opacity=".55" d="M12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/>
          </svg>`;
        case 'fuel': return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M6 3h8v18H6z"/>
            <path ${common} opacity=".55" d="M14 8h2l2 2v10a2 2 0 0 1-2 2h-2"/>
          </svg>`;
        case 'trans': return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M7 7h10M12 7v10"/>
            <path ${common} opacity=".55" d="M9 17h6"/>
          </svg>`;
        case 'palette': return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M12 3a9 9 0 1 0 0 18h2a2 2 0 0 0 2-2c0-1.2-.8-2-2-2h-1"/>
            <path ${common} opacity=".55" d="M7.5 10h.01M10 8h.01M14 8h.01M16.5 10h.01"/>
          </svg>`;
        case 'camera': return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M4 7h4l2-2h4l2 2h4v12H4z"/>
            <path ${common} opacity=".55" d="M12 10a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
          </svg>`;
        case 'car':
        default: return `
          <svg width="18" height="18" viewBox="0 0 24 24">
            <path ${common} d="M3 13.5v3.2c0 .7.6 1.3 1.3 1.3H6"/>
            <path ${common} d="M18 18h1.7c.7 0 1.3-.6 1.3-1.3v-3.2c0-1-.6-1.9-1.5-2.3l-1.8-.8-1.6-3.9C15.7 5.6 14.9 5 14 5H10c-.9 0-1.7.6-2 1.5L6.4 10.4l-1.8.8C3.6 11.6 3 12.5 3 13.5Z"/>
            <path ${common} opacity=".55" d="M7 18a2 2 0 1 0 4 0M13 18a2 2 0 1 0 4 0"/>
          </svg>`;
      }
    }
    function pillEl({id, title, sub, kind, key, selected=false}){
      const bg = pastelFromKey(key || title || id);
      const el = document.createElement('div');
      el.className = 'pill';
      el.setAttribute('role','button');
      el.setAttribute('tabindex','0');
      el.setAttribute('aria-selected', selected ? 'true' : 'false');
      el.dataset.id = String(id ?? '');
      el.dataset.title = String(title ?? '');
      el.dataset.key = String(key || title || id || '');
      el.dataset.pastel = '1';

      el.innerHTML = `
        <div class="ic" style="background:${bg};">
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
        minDeposit: null,
        maxDeposit: null,
        minMonths: 3,
        maxMonths: 60,
        notes: ''
      },

      selectedFeatures: [],

      photos: [], // {file,url}
      expiryDays: 30,
      sponsorDays: 0,

      title: '',
      trim: '',
      description: ''
    };

    const steps = [
      "dealer",
      "yard",
      "make",
      "model",
      "year",
      "body",
      "town",
      "pricing",
      "specs",
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
    function updateSummary(){
      $('sumDealer').textContent = state.dealer ? state.dealer.full_name : '—';
      $('sumYard').textContent = state.yard ? state.yard.yard_name : '—';
      $('sumCar').textContent = (state.make && state.model) ? `${state.make.name} ${state.model.name}` : '—';
      $('sumYearBody').textContent = (state.year || state.body) ? `${state.year || '—'} / ${state.body || '—'}` : '—';
      $('sumTown').textContent = state.town ? state.town.name : '—';
      $('sumPrice').textContent = state.price ? `KES ${Number(state.price).toLocaleString()}` : '—';
      const eng = state.engine ? `${state.engine}cc` : '—';
      const mil = (state.mileage !== null && state.mileage !== undefined && state.mileage !== '') ? `${Number(state.mileage).toLocaleString()}km` : '—';
      $('sumEngineMileage').textContent = `${eng} / ${mil}`;
      $('sumFuelTrans').textContent = `${state.fuel || '—'} / ${state.trans || '—'}`;
      $('sumColor').textContent = state.color || '—';

      const sale = [];
      if (state.sale.cash) sale.push('Cash');
      if (state.sale.hp) sale.push('HP');
      if (state.sale.trade) sale.push('Trade');
      if (state.sale.external) sale.push('External');
      $('sumSale').textContent = sale.length ? sale.join(', ') : '—';

      $('sumFeatures').textContent = state.selectedFeatures.length ? `${state.selectedFeatures.length} selected` : '—';
      $('sumPhotos').textContent = String(state.photos.length);
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
            <div class="label">Dealer phone (E.164)</div>
            <input class="field mt-2" id="dealerPhone" placeholder="+2547xxxxxxxx" autocomplete="off">
          </div>
          <div>
            <div class="label">Dealer name (optional, only if creating)</div>
            <input class="field mt-2" id="dealerName" placeholder="Optional" autocomplete="off">
          </div>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
          <div class="qSub">Status: <span class="text-white/85 font-extrabold" id="dealerStatus">${state.dealer ? escapeHtml(state.dealer.full_name) : 'Not selected'}</span></div>
          <button class="btn btnSolid" type="button" id="btnDealerLookup">Lookup / Create</button>
        </div>
      `;
      setBodyNode(box);

      $('btnDealerLookup').addEventListener('click', async ()=>{
        try{
          const fd = new FormData();
          fd.append('phone', $('dealerPhone').value.trim());
          fd.append('name', $('dealerName').value.trim());
          const data = await apiPost('dealer_lookup', fd);
          state.dealer = data.dealer;
          $('dealerStatus').textContent = state.dealer.full_name;
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
      hint.textContent = state.dealer ? `Dealer: ${state.dealer.full_name}` : 'Dealer not selected. Go back and select dealer.';
      wrap.appendChild(hint);

      const grid = document.createElement('div');
      grid.className = 'mt-4';

      const row = document.createElement('div');
      row.className = 'flex flex-wrap gap-10px';
      row.style.gap = '10px';

      const listWrap = document.createElement('div');
      listWrap.className = 'pillGrid';
      listWrap.id = 'yardPills';

      const addBtn = document.createElement('button');
      addBtn.className = 'btnAdd';
      addBtn.type = 'button';
      addBtn.textContent = '+ Add yard';

      row.appendChild(addBtn);
      wrap.appendChild(row);
      wrap.appendChild(document.createElement('div')).className = 'mt-4';
      wrap.appendChild(listWrap);

      setBodyNode(wrap);

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
              state.yard = {id: y.id, yard_name: y.yard_name, town_id: y.town_id};
              updateSummary();
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
              <button class="btn btnSolid" id="mSaveYard" type="button">Save yard</button>
              <button class="btn btnGhost" id="mCancelYard" type="button">Cancel</button>
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
        <button class="btnAdd" type="button" id="btnAddMake">+ Add make</button>
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

      $('btnAddMake').addEventListener('click', ()=>{
        openModal("Add make", "Saved into vehicle_makes (Title Case).", `
          <div>
            <div class="label">Make name</div>
            <input class="field mt-2" id="mMakeName" placeholder="e.g. Toyota">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveMake" type="button">Save make</button>
              <button class="btn btnGhost" id="mCancelMake" type="button">Cancel</button>
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
        <button class="btnAdd" type="button" id="btnAddModel">+ Add model</button>
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

      $('btnAddModel').addEventListener('click', ()=>{
        openModal("Add model", `Saved into vehicle_models for ${state.make.name} (Title Case).`, `
          <div>
            <div class="label">Model name</div>
            <input class="field mt-2" id="mModelName" placeholder="e.g. Vitz">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveModel" type="button">Save model</button>
              <button class="btn btnGhost" id="mCancelModel" type="button">Cancel</button>
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
      top.innerHTML = `<button class="btnAdd" type="button" id="btnAddYear">+ Add year</button>`;
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

      $('btnAddYear').addEventListener('click', ()=>{
        openModal("Add year", "Saved into vehicle_model_years for this model.", `
          <div>
            <div class="label">Year</div>
            <input class="field mt-2" id="mYearVal" placeholder="e.g. 2014" inputmode="numeric">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveYear" type="button">Save year</button>
              <button class="btn btnGhost" id="mCancelYear" type="button">Cancel</button>
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
      top.innerHTML = `<button class="btnAdd" type="button" id="btnAddBody">+ Add body type</button>`;
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

      $('btnAddBody').addEventListener('click', ()=>{
        openModal("Add body type", "Saved into vehicle_model_bodies (Title Case).", `
          <div>
            <div class="label">Body type</div>
            <input class="field mt-2" id="mBodyVal" placeholder="e.g. SUV">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveBody" type="button">Save body</button>
              <button class="btn btnGhost" id="mCancelBody" type="button">Cancel</button>
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
      top.innerHTML = `<button class="btnAdd" type="button" id="btnAddTown">+ Add town</button>`;
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

      $('btnAddTown').addEventListener('click', ()=>{
        openModal("Add town", "Saved into towns (Title Case).", `
          <div>
            <div class="label">Town name</div>
            <input class="field mt-2" id="mTownName" placeholder="e.g. Nairobi West">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveTown" type="button">Save town</button>
              <button class="btn btnGhost" id="mCancelTown" type="button">Cancel</button>
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
      setQuestion("What is the cash price?", "Enter a number once. Everything else stays tap-first.");
      setBody(`
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <div class="label">Cash price (KES)</div>
            <input class="field mt-2" id="priceKes" inputmode="numeric" placeholder="e.g. 780000" value="${state.price ?? ''}">
          </div>
          <div>
            <div class="label">Optional sponsor days</div>
            <div class="qSub mt-2">Sponsorship boosts ranking while active.</div>
            <div class="mt-2" id="sponsorPills"></div>
          </div>
        </div>
        <div class="mt-4">
          <div class="label">Expiry days</div>
          <div class="qSub mt-2">Listing is visible only if approved AND expires_at &gt; NOW().</div>
          <div class="mt-2" id="expiryPills"></div>
        </div>
      `);

      // Sponsor pills
      const sWrap = $('sponsorPills');
      sWrap.className = 'pillGrid';
      const sponsorOpts = [0,7,14,30];
      sponsorOpts.forEach(n=>{
        const p = pillEl({id:n, title: n===0 ? 'Sponsor Off' : `Sponsor ${n} days`, sub:'Sponsorship', kind:'money', key:'sponsor-'+n, selected: state.sponsorDays===n});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(sWrap, p);
          state.sponsorDays = n;
          updateSummary();
        });
        sWrap.appendChild(p);
      });

      // Expiry pills
      const eWrap = $('expiryPills');
      eWrap.className = 'pillGrid';
      const expiryOpts = [30,60,90];
      expiryOpts.forEach(n=>{
        const p = pillEl({id:n, title: `${n} days`, sub:'Expiry', kind:'tag', key:'expiry-'+n, selected: state.expiryDays===n});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(eWrap, p);
          state.expiryDays = n;
          updateSummary();
        });
        eWrap.appendChild(p);
      });

      $('priceKes').addEventListener('input', ()=>{
        const v = $('priceKes').value.replace(/[^\d]/g,'');
        $('priceKes').value = v;
        state.price = v ? Number(v) : null;
        updateSummary();
      });
    }

    function renderSpecs(){
      setQuestion("Basic specs", "Enter engine and mileage. Then pick fuel, transmission, and color with taps.");
      const fuelOpts = [
        {v:'petrol', t:'Petrol', k:'fuel', kind:'fuel'},
        {v:'diesel', t:'Diesel', k:'fuel', kind:'fuel'},
        {v:'hybrid', t:'Hybrid', k:'fuel', kind:'fuel'},
        {v:'electric', t:'Electric', k:'fuel', kind:'fuel'},
        {v:'other', t:'Other', k:'fuel', kind:'fuel'},
      ];
      const transOpts = [
        {v:'automatic', t:'Automatic', kind:'trans'},
        {v:'manual', t:'Manual', kind:'trans'},
        {v:'other', t:'Other', kind:'trans'},
      ];

      const colors = [
        "White","Black","Silver","Grey","Blue","Red","Green","Beige","Brown","Gold","Orange","Purple"
      ];

      const wrap = document.createElement('div');

      wrap.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <div class="label">Engine (cc)</div>
            <input class="field mt-2" id="engineCc" inputmode="numeric" placeholder="e.g. 1500" value="${state.engine ?? ''}">
          </div>
          <div>
            <div class="label">Mileage (km, optional)</div>
            <input class="field mt-2" id="mileageKm" inputmode="numeric" placeholder="e.g. 112000" value="${state.mileage ?? ''}">
          </div>
        </div>

        <div class="mt-5">
          <div class="label">Fuel type</div>
          <div class="mt-2 pillGrid" id="fuelGrid"></div>
        </div>

        <div class="mt-5">
          <div class="label">Transmission</div>
          <div class="mt-2 pillGrid" id="transGrid"></div>
        </div>

        <div class="mt-5">
          <div class="flex items-center justify-between gap-2 flex-wrap">
            <div>
              <div class="label">Color</div>
              <div class="qSub mt-1">Tap a color or add your own.</div>
            </div>
            <button class="btnAdd" type="button" id="btnAddColor">+ Add custom color</button>
          </div>
          <div class="mt-2 pillGrid" id="colorGrid"></div>
        </div>

        <div class="mt-5 hr"></div>

        <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <div class="label">Title (optional)</div>
            <input class="field mt-2" id="title" placeholder="Auto-generated if blank" value="${escapeHtml(state.title || '')}">
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
      `;
      setBodyNode(wrap);

      // Engine/mileage
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

      // Fuel pills
      const fg = $('fuelGrid');
      fuelOpts.forEach(o=>{
        const p = pillEl({id:o.v, title:o.t, sub:'Fuel', kind:o.kind, key:'fuel-'+o.v, selected: state.fuel===o.v});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(fg, p);
          state.fuel = o.v;
          updateSummary();
        });
        fg.appendChild(p);
      });

      // Transmission pills
      const tg = $('transGrid');
      transOpts.forEach(o=>{
        const p = pillEl({id:o.v, title:o.t, sub:'Transmission', kind:o.kind, key:'trans-'+o.v, selected: state.trans===o.v});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(tg, p);
          state.trans = o.v;
          updateSummary();
        });
        tg.appendChild(p);
      });

      // Color pills
      const cg = $('colorGrid');
      function renderColors(arr){
        cg.innerHTML = '';
        arr.forEach(c=>{
          const p = pillEl({id:c, title:c, sub:'Color', kind:'palette', key:'color-'+c, selected: state.color===c});
          p.addEventListener('click', ()=>{
            selectSingleInGrid(cg, p);
            state.color = c;
            updateSummary();
          });
          cg.appendChild(p);
        });
      }
      renderColors(colors);

      $('btnAddColor').addEventListener('click', ()=>{
        openModal("Add color", "This is stored only in the listing (Title Case).", `
          <div>
            <div class="label">Color</div>
            <input class="field mt-2" id="mColorVal" placeholder="e.g. Pearl White">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveColor" type="button">Use color</button>
              <button class="btn btnGhost" id="mCancelColor" type="button">Cancel</button>
            </div>
          </div>
        `);
        $('mCancelColor').addEventListener('click', closeModal);
        $('mSaveColor').addEventListener('click', ()=>{
          const v = $('mColorVal').value.trim();
          if (!v){ toast("Color required", "Enter a color name."); return; }
          // mimic title case client-side for display; server will enforce title-case for color too
          const tc = v.split(/\s+/).map(w=>w.charAt(0).toUpperCase()+w.slice(1).toLowerCase()).join(' ');
          closeModal();
          if (!colors.includes(tc)) colors.unshift(tc);
          state.color = tc;
          renderColors(colors);
          updateSummary();
          toast("Color selected", tc);
        });
      });

      // Optional text fields
      $('title').addEventListener('input', ()=>{ state.title = $('title').value; });
      $('trim').addEventListener('input', ()=>{ state.trim = $('trim').value; });
      $('desc').addEventListener('input', ()=>{ state.description = $('desc').value; });
    }

    function renderSale(){
      setQuestion("How can the buyer pay?", "Tap to enable sale methods. If HP is enabled, set simple ranges.");
      const wrap = document.createElement('div');

      wrap.innerHTML = `
        <div class="label">Sale methods</div>
        <div class="mt-2 pillGrid" id="saleGrid"></div>

        <div class="mt-5" id="hpBox" style="display:none;">
          <div class="label">Hire purchase terms</div>
          <div class="qSub mt-2">Store only ranges (schema: listing_hp_terms). No amortization schedule.</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
            <div>
              <div class="label">Min deposit (KES)</div>
              <input class="field mt-2" id="hpMinDep" inputmode="numeric" placeholder="e.g. 200000" value="${state.hp.minDeposit ?? ''}">
            </div>
            <div>
              <div class="label">Max deposit (optional)</div>
              <input class="field mt-2" id="hpMaxDep" inputmode="numeric" placeholder="Optional" value="${state.hp.maxDeposit ?? ''}">
            </div>
            <div>
              <div class="label">Min months</div>
              <input class="field mt-2" id="hpMinMo" inputmode="numeric" placeholder="3" value="${state.hp.minMonths ?? 3}">
            </div>
            <div>
              <div class="label">Max months</div>
              <input class="field mt-2" id="hpMaxMo" inputmode="numeric" placeholder="60" value="${state.hp.maxMonths ?? 60}">
            </div>
            <div class="md:col-span-2">
              <div class="label">HP notes (optional)</div>
              <input class="field mt-2" id="hpNotes" placeholder="Optional" value="${escapeHtml(state.hp.notes || '')}">
            </div>
          </div>
        </div>

        <div class="mt-5">
          <div class="label">Condition</div>
          <div class="mt-2 pillGrid" id="condGrid"></div>
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
      clampNum('hpMinDep'); clampNum('hpMaxDep'); clampNum('hpMinMo'); clampNum('hpMaxMo');
      $('hpMinDep').addEventListener('input', ()=> state.hp.minDeposit = $('hpMinDep').value ? Number($('hpMinDep').value) : null);
      $('hpMaxDep').addEventListener('input', ()=> state.hp.maxDeposit = $('hpMaxDep').value ? Number($('hpMaxDep').value) : null);
      $('hpMinMo').addEventListener('input', ()=> state.hp.minMonths = $('hpMinMo').value ? Number($('hpMinMo').value) : 3);
      $('hpMaxMo').addEventListener('input', ()=> state.hp.maxMonths = $('hpMaxMo').value ? Number($('hpMaxMo').value) : 60);
      $('hpNotes').addEventListener('input', ()=> state.hp.notes = $('hpNotes').value);

      // condition
      const cg = $('condGrid');
      const condOpts = [
        {v:'used', t:'Used', sub:'Most listings', kind:'car'},
        {v:'new', t:'New', sub:'Showroom', kind:'shield'},
      ];
      condOpts.forEach(o=>{
        const p = pillEl({id:o.v, title:o.t, sub:o.sub, kind:'car', key:'cond-'+o.v, selected: state.condition===o.v});
        p.addEventListener('click', ()=>{
          selectSingleInGrid(cg, p);
          state.condition = o.v;
          updateSummary();
        });
        cg.appendChild(p);
      });
    }

    function renderFeatures(){
      setQuestion("Any features?", "Tap to select multiple. Add a new feature option if missing.");
      const wrap = document.createElement('div');

      wrap.innerHTML = `
        <div class="flex items-center justify-between gap-2 flex-wrap">
          <div>
            <div class="label">Features</div>
            <div class="qSub mt-1">Multi-select pills. Stored as JSON array in listings.features.</div>
          </div>
          <button class="btnAdd" type="button" id="btnAddFeature">+ Add feature</button>
        </div>
        <div class="mt-4 pillGrid" id="featGrid"></div>
      `;
      setBodyNode(wrap);

      const g = $('featGrid');
      g.innerHTML = '';
      const selected = new Set(state.selectedFeatures);

      state.features.forEach(f=>{
        const sel = selected.has(f.tag);
        const p = pillEl({id:f.tag, title:f.label || f.tag, sub:'Feature', kind:'tag', key:'feat-'+f.tag, selected: sel});
        p.addEventListener('click', ()=>{
          const cur = p.getAttribute('aria-selected') === 'true';
          p.setAttribute('aria-selected', cur ? 'false' : 'true');
          if (cur) selected.delete(f.tag); else selected.add(f.tag);
          state.selectedFeatures = Array.from(selected);
          updateSummary();
        });
        g.appendChild(p);
      });

      $('btnAddFeature').addEventListener('click', ()=>{
        openModal("Add feature", "Saved into settings[pipii_features] (Title Case label).", `
          <div>
            <div class="label">Feature label</div>
            <input class="field mt-2" id="mFeatLabel" placeholder="e.g. Reverse Camera">
            <div class="label mt-4">Tag (optional)</div>
            <input class="field mt-2" id="mFeatTag" placeholder="e.g. reverse_camera">
            <div class="mt-4 flex gap-2">
              <button class="btn btnSolid" id="mSaveFeat" type="button">Save feature</button>
              <button class="btn btnGhost" id="mCancelFeat" type="button">Cancel</button>
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
            <button class="btnAdd" type="button" id="btnPickPhotos">+ Add photos</button>
            <button class="btn btnGhost" type="button" id="btnClearPhotos">Clear</button>
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
        updateSummary();
      }
      function addFiles(fileList){
        const files = Array.from(fileList || []).filter(f=>f && f.type && f.type.startsWith('image/'));
        files.forEach(f=> state.photos.push({ file: f, url: URL.createObjectURL(f) }));
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
            <button class="btn btnGhost" type="button" id="btnReviewBack">Go back</button>
            <button class="btn btnSolid" type="button" id="btnListNow">List now</button>
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

          fd.append('title', state.title || '');
          fd.append('trim', state.trim || '');
          fd.append('description', state.description || '');

          fd.append('allows_cash', state.sale.cash ? '1' : '0');
          fd.append('allows_hp', state.sale.hp ? '1' : '0');
          fd.append('allows_trade_in', state.sale.trade ? '1' : '0');
          fd.append('allows_external_financing', state.sale.external ? '1' : '0');

          fd.append('features', JSON.stringify(state.selectedFeatures || []));

          fd.append('hp_on', state.sale.hp ? '1' : '0');
          fd.append('hp_min_deposit', state.hp.minDeposit ? String(state.hp.minDeposit) : '');
          fd.append('hp_max_deposit', state.hp.maxDeposit ? String(state.hp.maxDeposit) : '');
          fd.append('hp_min_months', String(state.hp.minMonths || 3));
          fd.append('hp_max_months', String(state.hp.maxMonths || 60));
          fd.append('hp_notes', state.hp.notes || '');

          fd.append('expiry_days', String(state.expiryDays || 30));
          fd.append('sponsor_days', String(state.sponsorDays || 0));

          state.photos.forEach(p=> fd.append('photos[]', p.file, p.file.name));

          const data = await apiPost('listing_create', fd);
          toast("Listing saved", "ID #" + data.listing_id);

          // Reset for next entry
          hardReset(false);
          stepIndex = 0;
          setProgress();
          fadeTo(()=>renderCurrent());
        }catch(e){
          toast("Cannot list", e.message);
        }
      });
    }

    /* ---------- Helpers ---------- */
    function selectSingleInGrid(grid, selectedEl){
      [...grid.children].forEach(ch=> ch.setAttribute('aria-selected','false'));
      selectedEl.setAttribute('aria-selected','true');
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
      if (step === "specs") return renderSpecs();
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
      if (step === "specs") return !!state.engine; // minimum for schema
      if (step === "sale") {
        if (state.sale.hp && !state.hp.minDeposit) return false;
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
      state.hp = {minDeposit:null, maxDeposit:null, minMonths:3, maxMonths:60, notes:''};

      state.selectedFeatures = [];
      state.photos = [];
      state.expiryDays = 30;
      state.sponsorDays = 0;

      state.title = '';
      state.trim = '';
      state.description = '';

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