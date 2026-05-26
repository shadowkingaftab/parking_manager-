-- =======================================================
-- ÉLAN PARK - PARKING MANAGEMENT DATABASE SCHEMA
-- DBMS 4th Sem Project
-- =======================================================

-- Create Database if not exists and use it
CREATE DATABASE IF NOT EXISTS `parking_system`;
USE `parking_system`;

-- Disable foreign key checks temporarily to drop tables in order safely
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `parking_sessions`;
DROP TABLE IF EXISTS `parking_spaces`;
DROP TABLE IF EXISTS `parking_lots`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `users`;
DROP VIEW IF EXISTS `parking_occupancy_view`;
SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================
-- 1. USERS TABLE
-- Stores credentials and user profile info
-- ==========================================
CREATE TABLE `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `contact_info` VARCHAR(150) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(50) DEFAULT 'Registered User'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- 2. VEHICLES TABLE
-- Stores vehicle information owned by users
-- ==========================================
CREATE TABLE `vehicles` (
    `vehicle_id` INT AUTO_INCREMENT PRIMARY KEY,
    `license_plate` VARCHAR(20) NOT NULL UNIQUE,
    `make` VARCHAR(50) NOT NULL,
    `model` VARCHAR(50) NOT NULL,
    `color` VARCHAR(30) DEFAULT 'Unknown',
    `user_id` INT NOT NULL,
    CONSTRAINT `fk_vehicle_user` 
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- 3. PARKING LOTS TABLE
-- Stores details of individual parking structures/lots
-- ==========================================
CREATE TABLE `parking_lots` (
    `lot_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `location` VARCHAR(150) NOT NULL,
    `total_capacity` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- 4. PARKING SPACES TABLE
-- Stores parking slots inside parking lots
-- ==========================================
CREATE TABLE `parking_spaces` (
    `space_id` INT AUTO_INCREMENT PRIMARY KEY,
    `lot_id` INT NOT NULL,
    `space_number` VARCHAR(10) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'Available', -- 'Available', 'Occupied', 'Maintenance'
    CONSTRAINT `fk_space_lot` 
        FOREIGN KEY (`lot_id`) REFERENCES `parking_lots` (`lot_id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- 5. PARKING SESSIONS TABLE
-- Tracks when a vehicle enters and leaves a space
-- ==========================================
CREATE TABLE `parking_sessions` (
    `session_id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_id` INT NOT NULL,
    `space_id` INT NOT NULL,
    `entry_time` DATETIME NOT NULL,
    `exit_time` DATETIME DEFAULT NULL,
    `duration` INT DEFAULT NULL, -- Duration in minutes
    `total_cost` DECIMAL(10, 2) DEFAULT NULL, -- Cost in dollars/rupees
    CONSTRAINT `fk_session_vehicle` 
        FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_session_space` 
        FOREIGN KEY (`space_id`) REFERENCES `parking_spaces` (`space_id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- 6. AUDIT LOGS TABLE (Triggers populate this)
-- Automatically tracks database actions for triggers
-- ==========================================
CREATE TABLE `audit_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` INT DEFAULT NULL,
    `action` VARCHAR(50) NOT NULL,
    `action_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `description` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =======================================================
-- DATABASE TRIGGERS
-- Automatic behaviors written inside the database itself
-- =======================================================

DELIMITER //

-- TRIGGER 1: Triggered immediately when a parking session begins
-- 1. Updates the parking space status to 'Occupied'
-- 2. Logs a starting entry in the audit log
CREATE TRIGGER `after_session_insert`
AFTER INSERT ON `parking_sessions`
FOR EACH ROW
BEGIN
    -- Automatically set space status to Occupied
    UPDATE `parking_spaces` 
    SET `status` = 'Occupied' 
    WHERE `space_id` = NEW.`space_id`;
    
    -- Insert a log record of this event
    INSERT INTO `audit_logs` (`session_id`, `action`, `description`)
    VALUES (NEW.`session_id`, 'SESSION_START', 
            CONCAT('Vehicle ID ', NEW.`vehicle_id`, ' entered space ID ', NEW.`space_id`, ' at ', NEW.`entry_time`));
END//

-- TRIGGER 2: Triggered when a parking session ends (i.e. exit_time gets updated)
-- 1. Reverts the parking space status to 'Available'
-- 2. Logs a concluding entry in the audit log
CREATE TRIGGER `after_session_update`
AFTER UPDATE ON `parking_sessions`
FOR EACH ROW
BEGIN
    -- Check if exit_time was just updated
    IF NEW.`exit_time` IS NOT NULL AND OLD.`exit_time` IS NULL THEN
        -- Automatically reset space status to Available
        UPDATE `parking_spaces` 
        SET `status` = 'Available' 
        WHERE `space_id` = NEW.`space_id`;
        
        -- Insert a log record of this event with total session duration and cost
        INSERT INTO `audit_logs` (`session_id`, `action`, `description`)
        VALUES (NEW.`session_id`, 'SESSION_END', 
                CONCAT('Vehicle ID ', NEW.`vehicle_id`, ' exited space ID ', NEW.`space_id`, 
                       '. Duration: ', NEW.`duration`, ' minutes. Cost: $', NEW.`total_cost`));
    END IF;
END//

DELIMITER ;


-- =======================================================
-- DATABASE VIEW
-- Simplifies fetching of real-time lot occupancy summaries
-- =======================================================
CREATE VIEW `parking_occupancy_view` AS
SELECT 
    l.`lot_id` AS `lot_id`,
    l.`name` AS `lot_name`,
    l.`location` AS `lot_location`,
    l.`total_capacity` AS `total_capacity`,
    COUNT(s.`space_id`) AS `spaces_configured`,
    SUM(CASE WHEN s.`status` = 'Available' THEN 1 ELSE 0 END) AS `available_spaces`,
    SUM(CASE WHEN s.`status` = 'Occupied' THEN 1 ELSE 0 END) AS `occupied_spaces`,
    SUM(CASE WHEN s.`status` = 'Maintenance' THEN 1 ELSE 0 END) AS `maintenance_spaces`
FROM `parking_lots` l
LEFT JOIN `parking_spaces` s ON l.`lot_id` = s.`lot_id`
GROUP BY l.`lot_id`, l.`name`, l.`location`, l.`total_capacity`;


-- =======================================================
-- SEED INITIAL DATA
-- Populates default records in database
-- =======================================================

-- 1. Insert Default Administrative & User Accounts
-- Default Admin password is: admin123 (hashed using standard PHP password_hash, which matches BCRYPT)
-- Default User password is: user123
INSERT INTO `users` (`user_id`, `name`, `contact_info`, `password_hash`, `role`) VALUES
(1, 'admin', 'admin@elanpark.com', '$2y$10$w8T0Mh304K/yvMiw6m9N4ecP1W08rDqf92i90m4wGZg7e49xZzZWy', 'Administrator'),
(2, 'johndoe', 'john@gmail.com', '$2y$10$dUpZlhE0KCSa.yLbeoA4tOuU5pZ2Tz4G85w9s9cE1e.x.U1yVzZWy', 'Registered User');

-- 2. Insert Default Parking Lots (Pavilions)
INSERT INTO `parking_lots` (`lot_id`, `name`, `location`, `total_capacity`) VALUES
(1, 'Grand Pavilion', 'North Wing - Level 1', 10),
(2, 'Royal Pavilion', 'South Wing - Level 1', 6),
(3, 'Prestige Deck', 'Rooftop - Level 3', 8);

-- 3. Insert Parking Spaces for Grand Pavilion (Lot 1)
INSERT INTO `parking_spaces` (`lot_id`, `space_number`, `status`) VALUES
(1, 'A1', 'Available'),
(1, 'A2', 'Available'),
(1, 'A3', 'Available'),
(1, 'A4', 'Available'),
(1, 'A5', 'Available'),
(1, 'A6', 'Available'),
(1, 'A7', 'Available'),
(1, 'A8', 'Available'),
(1, 'A9', 'Available'),
(1, 'A10', 'Available');

-- 4. Insert Parking Spaces for Royal Pavilion (Lot 2)
INSERT INTO `parking_spaces` (`lot_id`, `space_number`, `status`) VALUES
(2, 'B1', 'Available'),
(2, 'B2', 'Available'),
(2, 'B3', 'Available'),
(2, 'B4', 'Available'),
(2, 'B5', 'Available'),
(2, 'B6', 'Available');

-- 5. Insert Parking Spaces for Prestige Deck (Lot 3)
INSERT INTO `parking_spaces` (`lot_id`, `space_number`, `status`) VALUES
(3, 'C1', 'Available'),
(3, 'C2', 'Available'),
(3, 'C3', 'Available'),
(3, 'C4', 'Available'),
(3, 'C5', 'Available'),
(3, 'C6', 'Available'),
(3, 'C7', 'Available'),
(3, 'C8', 'Available');
