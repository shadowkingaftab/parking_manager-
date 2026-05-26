<?php
// =======================================================
// ÉLAN PARK - PARKING SYSTEM PHP BACKEND API
// DBMS 4th Sem Project
// =======================================================

// Enable error reporting for debugging during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS Headers to allow frontend communication from any local port
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ========== 1. DATABASE CONFIGURATION ==========
$host = "localhost";
$db_name = "parking_system";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database Connection Failed: " . $e->getMessage()]);
    exit;
}

// ========== 2. ROUTING ENGINE ==========
// Inspect PATH_INFO (e.g. api.php/login -> PATH_INFO is /login)
$path = isset($_SERVER['PATH_INFO']) ? rtrim($_SERVER['PATH_INFO'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];

// Helper to decode JSON input safely
$input = json_decode(file_get_contents("php://input"), true) ?? [];

// Helper to calculate duration in minutes between entry time and now
function calculateDurationMinutes($entryTimeStr) {
    $entry = new DateTime($entryTimeStr);
    $exit = new DateTime(); // Current system time
    $diff = $exit->getTimestamp() - $entry->getTimestamp();
    return max(1, intval(ceil($diff / 60))); // Minimum 1 minute
}

// Router dispatch
switch (true) {
    
    // ---------------------------------------------------
    // [POST] /register
    // Creates a new user with standard BCRYPT secure hash
    // ---------------------------------------------------
    case ($path === '/register' && $method === 'POST'):
        $name = isset($input['name']) ? trim($input['name']) : '';
        $contact = isset($input['contact']) ? trim($input['contact']) : '';
        $password = isset($input['password']) ? $input['password'] : '';
        
        if (empty($name) || empty($password)) {
            http_response_code(400);
            echo json_encode(["error" => "Name and password are required"]);
            break;
        }
        
        try {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (name, contact_info, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$name, $contact, $hashed]);
            
            http_response_code(201);
            echo json_encode(["message" => "User registered successfully"]);
        } catch (PDOException $e) {
            http_response_code(500);
            if ($e->getCode() == 23000) { // Integrity constraint violation (duplicate key)
                echo json_encode(["error" => "Username already exists"]);
            } else {
                echo json_encode(["error" => $e->getMessage()]);
            }
        }
        break;

    // ---------------------------------------------------
    // [POST] /login
    // Validates credentials and returns user details
    // ---------------------------------------------------
    case ($path === '/login' && $method === 'POST'):
        $name = isset($input['name']) ? trim($input['name']) : '';
        $password = isset($input['password']) ? $input['password'] : '';
        
        if (empty($name) || empty($password)) {
            http_response_code(400);
            echo json_encode(["error" => "Name and password are required"]);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT user_id, name, password_hash, role FROM users WHERE name = ?");
            $stmt->execute([$name]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                echo json_encode([
                    "message" => "Login successful",
                    "user_id" => $user['user_id'],
                    "role" => $user['role']
                ]);
            } else {
                http_response_code(401);
                echo json_encode(["error" => "Invalid credentials"]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [POST] /vehicles -> Register a new vehicle
    // ---------------------------------------------------
    case ($path === '/vehicles' && $method === 'POST'):
        $user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
        $plate = isset($input['license_plate']) ? trim($input['license_plate']) : '';
        $make = isset($input['make']) ? trim($input['make']) : '';
        $model = isset($input['model']) ? trim($input['model']) : '';
        $color = isset($input['color']) ? trim($input['color']) : '';
        
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(["error" => "Not logged in"]);
            break;
        }
        if (empty($plate) || empty($make) || empty($model)) {
            http_response_code(400);
            echo json_encode(["error" => "License plate, make, and model are required"]);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO vehicles (license_plate, make, model, color, user_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$plate, $make, $model, $color, $user_id]);
            
            http_response_code(201);
            echo json_encode(["message" => "Vehicle registered successfully"]);
        } catch (PDOException $e) {
            http_response_code(500);
            if ($e->getCode() == 23000) {
                echo json_encode(["error" => "License plate is already registered"]);
            } else {
                echo json_encode(["error" => $e->getMessage()]);
            }
        }
        break;

    // ---------------------------------------------------
    // [GET] /vehicles -> Get fleet of the logged in user
    // ---------------------------------------------------
    case ($path === '/vehicles' && $method === 'GET'):
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(["error" => "Not logged in"]);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT vehicle_id AS VehicleID, license_plate AS LicensePlate, make AS Make, model AS Model, color AS Color FROM vehicles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [GET] /lots -> Get all parking lots
    // ---------------------------------------------------
    case ($path === '/lots' && $method === 'GET'):
        try {
            $stmt = $pdo->query("SELECT lot_id AS LotID, name AS Name, location AS Location, total_capacity AS TotalCapacity FROM parking_lots");
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [GET] /lots/<lot_id>/spaces -> Available spaces only
    // ---------------------------------------------------
    case (preg_match('/^\/lots\/(\d+)\/spaces$/', $path, $matches) && $method === 'GET'):
        $lot_id = intval($matches[1]);
        try {
            $stmt = $pdo->prepare("SELECT space_id AS SpaceID, space_number AS SpaceNumber FROM parking_spaces WHERE lot_id = ? AND status = 'Available'");
            $stmt->execute([$lot_id]);
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [GET] /lots/<lot_id>/allspaces -> All spaces (with status)
    // ---------------------------------------------------
    case (preg_match('/^\/lots\/(\d+)\/allspaces$/', $path, $matches) && $method === 'GET'):
        $lot_id = intval($matches[1]);
        try {
            $stmt = $pdo->prepare("SELECT space_id AS SpaceID, space_number AS SpaceNumber, status AS Status FROM parking_spaces WHERE lot_id = ?");
            $stmt->execute([$lot_id]);
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [POST] /sessions/start -> Start a new parking session
    // Note: The DBMS Trigger after_session_insert automatically 
    // changes the space status to 'Occupied' inside the DB!
    // ---------------------------------------------------
    case ($path === '/sessions/start' && $method === 'POST'):
        $user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
        $vehicle_id = isset($input['vehicle_id']) ? intval($input['vehicle_id']) : 0;
        $space_id = isset($input['space_id']) ? intval($input['space_id']) : 0;
        
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(["error" => "Not logged in"]);
            break;
        }
        if (!$vehicle_id || !$space_id) {
            http_response_code(400);
            echo json_encode(["error" => "Vehicle and space are required"]);
            break;
        }
        
        try {
            // Verify vehicle ownership
            $stmt = $pdo->prepare("SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? AND user_id = ?");
            $stmt->execute([$vehicle_id, $user_id]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(["error" => "Vehicle not found in your fleet"]);
                break;
            }
            
            // Verify space is available
            $stmt = $pdo->prepare("SELECT status FROM parking_spaces WHERE space_id = ?");
            $stmt->execute([$space_id]);
            $space = $stmt->fetch();
            if (!$space || $space['status'] !== 'Available') {
                http_response_code(400);
                echo json_encode(["error" => "Selected parking space is not available"]);
                break;
            }
            
            // Record session. The Trigger 'after_session_insert' will automatically update the space to 'Occupied'!
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO parking_sessions (vehicle_id, space_id, entry_time) VALUES (?, ?, ?)");
            $stmt->execute([$vehicle_id, $space_id, $now]);
            
            echo json_encode(["message" => "Parking session started successfully", "entry_time" => $now]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [POST] /sessions/end -> Conclude a parking session
    // Note: The DBMS Trigger after_session_update automatically
    // resets the space status back to 'Available' inside the DB!
    // ---------------------------------------------------
    case ($path === '/sessions/end' && $method === 'POST'):
        $user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
        $session_id = isset($input['session_id']) ? intval($input['session_id']) : 0;
        
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(["error" => "Not logged in"]);
            break;
        }
        if (!$session_id) {
            http_response_code(400);
            echo json_encode(["error" => "Session ID is required"]);
            break;
        }
        
        try {
            // Find active session belonging to user's vehicle
            $stmt = $pdo->prepare("
                SELECT s.session_id, s.entry_time, s.space_id 
                FROM parking_sessions s 
                JOIN vehicles v ON s.vehicle_id = v.vehicle_id 
                WHERE s.session_id = ? AND s.exit_time IS NULL AND v.user_id = ?
            ");
            $stmt->execute([$session_id, $user_id]);
            $sess = $stmt->fetch();
            
            if (!$sess) {
                http_response_code(400);
                echo json_encode(["error" => "No active session found with this ID for your account"]);
                break;
            }
            
            // Calculate details
            $now = date('Y-m-d H:i:s');
            $duration = calculateDurationMinutes($sess['entry_time']);
            
            // Flat rate: $2.00 per hour (or fractional minutes)
            $cost = round(($duration / 60) * 2, 2);
            if ($cost < 0.50) $cost = 0.50; // Minimum charge of $0.50
            
            // Update session exit details.
            // Trigger 'after_session_update' will automatically set space status to 'Available' and write audit log!
            $stmt = $pdo->prepare("UPDATE parking_sessions SET exit_time = ?, duration = ?, total_cost = ? WHERE session_id = ?");
            $stmt->execute([$now, $duration, $cost, $session_id]);
            
            echo json_encode([
                "message" => "Parking session completed",
                "duration_min" => $duration,
                "cost_usd" => $cost
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [GET] /sessions/history -> Active and past sessions of current user
    // ---------------------------------------------------
    case ($path === '/sessions/history' && $method === 'GET'):
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(["error" => "Not logged in"]);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT s.session_id AS SessionID, v.license_plate AS LicensePlate, p.space_number AS SpaceNumber, l.name as LotName,
                       s.entry_time AS EntryTime, s.exit_time AS ExitTime, s.duration AS Duration, s.total_cost AS Cost
                FROM parking_sessions s
                JOIN vehicles v ON s.vehicle_id = v.vehicle_id
                JOIN parking_spaces p ON s.space_id = p.space_id
                JOIN parking_lots l ON p.lot_id = l.lot_id
                WHERE v.user_id = ?
                ORDER BY s.entry_time DESC
            ");
            $stmt->execute([$user_id]);
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [GET] /admin/sessions -> Admin: View sessions of all members
    // ---------------------------------------------------
    case ($path === '/admin/sessions' && $method === 'GET'):
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if (!$user_id) {
            http_response_code(401);
            echo json_encode(["error" => "Not logged in"]);
            break;
        }
        
        try {
            // Verify admin privilege
            $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            if (!$user || $user['role'] !== 'Administrator') {
                http_response_code(403);
                echo json_encode(["error" => "Administrator credentials required"]);
                break;
            }
            
            // Fetch records
            $stmt = $pdo->query("
                SELECT s.session_id AS SessionID, v.license_plate AS LicensePlate, p.space_number AS SpaceNumber, l.name as LotName,
                       s.entry_time AS EntryTime, s.exit_time AS ExitTime, s.duration AS Duration, u.name as UserName
                FROM parking_sessions s
                JOIN vehicles v ON s.vehicle_id = v.vehicle_id
                JOIN parking_spaces p ON s.space_id = p.space_id
                JOIN parking_lots l ON p.lot_id = l.lot_id
                JOIN users u ON v.user_id = u.user_id
                ORDER BY s.entry_time DESC
            ");
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // [GET] /occupancy -> Read from Database View (parking_occupancy_view)
    // ---------------------------------------------------
    case ($path === '/occupancy' && $method === 'GET'):
        try {
            $stmt = $pdo->query("SELECT * FROM parking_occupancy_view");
            echo json_encode($stmt->fetchAll());
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["error" => $e->getMessage()]);
        }
        break;

    // ---------------------------------------------------
    // Default Route health check/viva information
    // ---------------------------------------------------
    default:
        echo json_encode([
            "status" => "active",
            "message" => "ÉLAN PARK API Backend is fully functional",
            "dbms_features" => [
                "tables" => ["users", "vehicles", "parking_lots", "parking_spaces", "parking_sessions", "audit_logs"],
                "triggers" => ["after_session_insert", "after_session_update"],
                "views" => ["parking_occupancy_view"]
            ]
        ]);
        break;
}
?>
