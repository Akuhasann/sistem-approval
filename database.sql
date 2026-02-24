CREATE DATABASE db_approval;
USE db_approval;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','user','dr') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (username, password, role) VALUES
('admin', 'admin123', 'admin'),
('user1', 'user123', 'user'),
('dokter1', 'dr123', 'dr');

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    extension VARCHAR(20) NOT NULL,
    server_file VARCHAR(255) NOT NULL,
    status ENUM('Pending','Approved') DEFAULT 'Pending',
    date_approved VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);