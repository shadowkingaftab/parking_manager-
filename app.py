from flask import Flask, request, jsonify
from flask_cors import CORS
import sqlite3
from datetime import datetime
import bcrypt

app = Flask(__name__)
CORS(app, origins=["http://localhost:8000", "http://127.0.0.1:8000", "http://localhost:5500", "http://127.0.0.1:5500"])

# ========== DATABASE CONFIGURATION ==========
db_file = 'parking_system.db'

def get_db_connection():
    return sqlite3.connect(db_file)

def calculate_duration(entry_str, exit_dt):
    entry = datetime.fromisoformat(entry_str)
    delta = exit_dt - entry
    return int(delta.total_seconds() / 60)

# ========== INITIALIZE DATABASE WITH TRIGGERS ==========
def init_database():
    """Create tables if they don't exist"""
    conn = get_db_connection()
    cursor = conn.cursor()
    
    # Create User table
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS User (
            UserID INTEGER PRIMARY KEY AUTOINCREMENT,
            Name TEXT NOT NULL,
            ContactInfo TEXT,
            PasswordHash TEXT NOT NULL,
            Role TEXT DEFAULT 'Registered User'
        )
    """)
    
    # Create Vehicle table
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS Vehicle (
            VehicleID INTEGER PRIMARY KEY AUTOINCREMENT,
            LicensePlate TEXT UNIQUE NOT NULL,
            Make TEXT,
            Model TEXT,
            Color TEXT,
            UserID INTEGER NOT NULL,
            FOREIGN KEY (UserID) REFERENCES User(UserID) ON DELETE CASCADE
        )
    """)
    
    # Create ParkingLot table
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS ParkingLot (
            LotID INTEGER PRIMARY KEY AUTOINCREMENT,
            Name TEXT NOT NULL,
            Location TEXT,
            TotalCapacity INTEGER NOT NULL
        )
    """)
    
    # Create ParkingSpace table
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS ParkingSpace (
            SpaceID INTEGER PRIMARY KEY AUTOINCREMENT,
            LotID INTEGER NOT NULL,
            SpaceNumber TEXT NOT NULL,
            Status TEXT DEFAULT 'Available',
            FOREIGN KEY (LotID) REFERENCES ParkingLot(LotID) ON DELETE CASCADE
        )
    """)
    
    # Create ParkingSession table
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS ParkingSession (
            SessionID INTEGER PRIMARY KEY AUTOINCREMENT,
            VehicleID INTEGER NOT NULL,
            SpaceID INTEGER NOT NULL,
            EntryTime TEXT NOT NULL,
            ExitTime TEXT,
            Duration INTEGER,
            TotalCost REAL,
            FOREIGN KEY (VehicleID) REFERENCES Vehicle(VehicleID),
            FOREIGN KEY (SpaceID) REFERENCES ParkingSpace(SpaceID)
        )
    """)
    
    # Insert sample data if tables are empty
    cursor.execute("SELECT COUNT(*) FROM ParkingLot")
    lot_count = cursor.fetchone()[0]
    if lot_count == 0:
        cursor.execute("INSERT INTO ParkingLot (Name, Location, TotalCapacity) VALUES ('Grand Pavilion', 'North Wing', 10), ('Royal Pavilion', 'South Wing', 6)")
        cursor.execute("INSERT INTO ParkingSpace (LotID, SpaceNumber, Status) VALUES (1, 'A1', 'Available'), (1, 'A2', 'Available'), (1, 'A3', 'Available'), (1, 'A4', 'Available'), (1, 'A5', 'Available'), (2, 'B1', 'Available'), (2, 'B2', 'Available'), (2, 'B3', 'Available')")
        cursor.execute("INSERT INTO User (Name, ContactInfo, PasswordHash, Role) VALUES ('admin', 'admin@elan.com', 'admin_hash', 'Administrator')")
    
    conn.commit()
    cursor.close()
    conn.close()
    print("✅ Database initialized successfully!")

# Initialize database
try:
    init_database()
except Exception as e:
    print(f"⚠️ Database initialization warning: {e}")
    print("Continuing anyway...")

# ========== USER AUTHENTICATION ==========
@app.route('/api/register', methods=['POST'])
def register():
    data = request.json
    name = data.get('name')
    contact = data.get('contact', '')
    password = data.get('password')
    if not name or not password:
        return jsonify({'error': 'Name and password required'}), 400
    hashed = bcrypt.hashpw(password.encode('utf-8'), bcrypt.gensalt())
    conn = get_db_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("INSERT INTO User (Name, ContactInfo, PasswordHash) VALUES (?, ?, ?)",
                       (name, contact, hashed.decode('utf-8')))
        conn.commit()
        return jsonify({'message': 'User registered successfully'}), 201
    except Error as e:
        return jsonify({'error': str(e)}), 500
    finally:
        cursor.close()
        conn.close()

@app.route('/api/login', methods=['POST'])
def login():
    data = request.json
    name = data.get('name')
    password = data.get('password')
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT UserID, Name, PasswordHash, Role FROM User WHERE Name = ?", (name,))
    user = cursor.fetchone()
    cursor.close()
    conn.close()
    if user and bcrypt.checkpw(password.encode('utf-8'), user[2].encode('utf-8')):
        return jsonify({
            'message': 'Login successful',
            'user_id': user[0],
            'role': user[3]
        })
    return jsonify({'error': 'Invalid credentials'}), 401

# ========== VEHICLE MANAGEMENT ==========
@app.route('/api/vehicles', methods=['POST'])
def add_vehicle():
    data = request.json
    user_id = data.get('user_id')
    license_plate = data.get('license_plate')
    make = data.get('make')
    model = data.get('model')
    color = data.get('color')
    
    if not user_id:
        return jsonify({'error': 'Not logged in'}), 401
    if not license_plate:
        return jsonify({'error': 'License plate required'}), 400
        
    conn = get_db_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("INSERT INTO Vehicle (LicensePlate, Make, Model, Color, UserID) VALUES (?, ?, ?, ?, ?)",
                       (license_plate, make, model, color, user_id))
        conn.commit()
        return jsonify({'message': 'Vehicle added'}), 201
    except Exception as e:
        return jsonify({'error': str(e)}), 500
    finally:
        cursor.close()
        conn.close()

@app.route('/api/vehicles', methods=['GET'])
def get_vehicles():
    user_id = request.args.get('user_id')
    if not user_id:
        return jsonify({'error': 'Not logged in'}), 401
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT VehicleID, LicensePlate, Make, Model, Color FROM Vehicle WHERE UserID = ?", (user_id,))
    vehicles = cursor.fetchall()
    cursor.close()
    conn.close()
    return jsonify([{'VehicleID': v[0], 'LicensePlate': v[1], 'Make': v[2], 'Model': v[3], 'Color': v[4]} for v in vehicles])

# ========== PARKING LOTS AND SPACES ==========
@app.route('/api/lots', methods=['GET'])
def get_lots():
    conn = get_db_connection()
    cursor = conn.cursor(dictionary=True)
    cursor.execute("SELECT LotID, Name, Location FROM ParkingLot")
    lots = cursor.fetchall()
    cursor.close()
    conn.close()
    return jsonify(lots)

@app.route('/api/lots/<int:lot_id>/spaces', methods=['GET'])
def get_available_spaces(lot_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT SpaceID, SpaceNumber FROM ParkingSpace WHERE LotID = ? AND Status = 'Available'", (lot_id,))
    spaces = cursor.fetchall()
    cursor.close()
    conn.close()
    return jsonify([{'SpaceID': s[0], 'SpaceNumber': s[1]} for s in spaces])

@app.route('/api/lots/<int:lot_id>/allspaces', methods=['GET'])
def get_all_spaces(lot_id):
    conn = get_db_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT SpaceID, SpaceNumber, Status FROM ParkingSpace WHERE LotID = ?", (lot_id,))
    spaces = cursor.fetchall()
    cursor.close()
    conn.close()
    return jsonify([{'SpaceID': s[0], 'SpaceNumber': s[1], 'Status': s[2]} for s in spaces])

# ========== PARKING SESSIONS ==========
@app.route('/api/sessions/start', methods=['POST'])
def start_session():
    data = request.json
    user_id = data.get('user_id')
    vehicle_id = data.get('vehicle_id')
    space_id = data.get('space_id')
    
    if not user_id:
        return jsonify({'error': 'Not logged in'}), 401
    if not vehicle_id or not space_id:
        return jsonify({'error': 'vehicle_id and space_id required'}), 400
        
    conn = get_db_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("SELECT VehicleID FROM Vehicle WHERE VehicleID = ? AND UserID = ?", (vehicle_id, user_id))
        if not cursor.fetchone():
            return jsonify({'error': 'Vehicle not found for this user'}), 404
        
        now = datetime.now()
        cursor.execute("INSERT INTO ParkingSession (VehicleID, SpaceID, EntryTime) VALUES (?, ?, ?)",
                       (vehicle_id, space_id, now))
        conn.commit()
        return jsonify({'message': 'Parking started', 'entry_time': now.isoformat()}), 201
    except Exception as e:
        conn.rollback()
        return jsonify({'error': str(e)}), 500
    finally:
        cursor.close()
        conn.close()

@app.route('/api/sessions/end', methods=['POST'])
def end_session():
    data = request.json
    user_id = data.get('user_id')
    session_id = data.get('session_id')
    
    if not user_id:
        return jsonify({'error': 'Not logged in'}), 401
        
    conn = get_db_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            SELECT s.SessionID, s.EntryTime, s.VehicleID
            FROM ParkingSession s
            JOIN Vehicle v ON s.VehicleID = v.VehicleID
            WHERE s.SessionID = ? AND s.ExitTime IS NULL AND v.UserID = ?
        """, (session_id, user_id))
        sess = cursor.fetchone()
        if not sess:
            return jsonify({'error': 'No active session found'}), 400
        
        now = datetime.now()
        duration = calculate_duration(sess[1], now)
        cost = round((duration / 60) * 2, 2)
        cursor.execute("UPDATE ParkingSession SET ExitTime = ?, Duration = ?, TotalCost = ? WHERE SessionID = ?",
                       (now, duration, cost, session_id))
        
        conn.commit()
        
        return jsonify({
            'message': 'Session ended', 
            'duration_min': duration, 
            'cost_usd': cost
        })
    except Exception as e:
        conn.rollback()
        return jsonify({'error': str(e)}), 500
    finally:
        cursor.close()
        conn.close()

@app.route('/api/sessions/history', methods=['GET'])
def get_history():
    user_id = request.args.get('user_id')
    if not user_id:
        return jsonify({'error': 'Not logged in'}), 401
    conn = get_db_connection()
    cursor = conn.cursor()
    query = """
        SELECT s.SessionID, v.LicensePlate, p.SpaceNumber, l.Name as LotName,
               s.EntryTime, s.ExitTime, s.Duration
        FROM ParkingSession s
        JOIN Vehicle v ON s.VehicleID = v.VehicleID
        JOIN ParkingSpace p ON s.SpaceID = p.SpaceID
        JOIN ParkingLot l ON p.LotID = l.LotID
        WHERE v.UserID = ?
        ORDER BY s.EntryTime DESC
    """
    cursor.execute(query, (user_id,))
    sessions = cursor.fetchall()
    cursor.close()
    conn.close()
    return jsonify([{
        'SessionID': s[0], 'LicensePlate': s[1], 'SpaceNumber': s[2], 'LotName': s[3],
        'EntryTime': s[4], 'ExitTime': s[5], 'Duration': s[6]
    } for s in sessions])

# ========== ADMIN: VIEW ALL SESSIONS ==========
@app.route('/api/admin/sessions', methods=['GET'])
def admin_get_all_sessions():
    user_id = request.args.get('user_id')
    if not user_id:
        return jsonify({'error': 'Not logged in'}), 401
    conn = get_db_connection()
    cursor = conn.cursor(dictionary=True)
    cursor.execute("SELECT Role FROM User WHERE UserID = %s", (user_id,))
    user = cursor.fetchone()
    if not user or user['Role'] != 'Administrator':
        cursor.close()
        conn.close()
        return jsonify({'error': 'Admin access required'}), 403
    query = """
        SELECT s.SessionID, v.LicensePlate, p.SpaceNumber, l.Name as LotName,
               s.EntryTime, s.ExitTime, s.Duration, u.Name as UserName
        FROM ParkingSession s
        JOIN Vehicle v ON s.VehicleID = v.VehicleID
        JOIN ParkingSpace p ON s.SpaceID = p.SpaceID
        JOIN ParkingLot l ON p.LotID = l.LotID
        JOIN User u ON v.UserID = u.UserID
        ORDER BY s.EntryTime DESC
    """
    cursor.execute(query)
    sessions = cursor.fetchall()
    cursor.close()
    conn.close()
    return jsonify(sessions)

# ========== RUN THE APP ==========
if __name__ == '__main__':
    print("🚀 Starting ÉLAN PARK Backend...")
    print("📍 Server will run at: http://127.0.0.1:5000")
    print("=" * 50)
    app.run(debug=True, port=5000)