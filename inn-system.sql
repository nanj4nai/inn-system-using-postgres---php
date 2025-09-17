-- =====================================
-- INN SYSTEM DATABASE SCHEMA (PostgreSQL)
-- =====================================

-- BRANCHES (each inn/branch)
CREATE TABLE branches (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- USERS / STAFF
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('clerk','housekeeping','manager','admin')),
    branch_id INT REFERENCES branches(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ROOMS
CREATE TABLE rooms (
    id SERIAL PRIMARY KEY,
    room_number VARCHAR(20) UNIQUE NOT NULL,
    room_type VARCHAR(50) NOT NULL, -- Single, Double, Suite, etc.
    status VARCHAR(20) NOT NULL DEFAULT 'available'
        CHECK (status IN ('available','occupied','cleaning','maintenance')),
    branch_id INT REFERENCES branches(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    min_hours INT DEFAULT 1,
    base_price NUMERIC(10,2) DEFAULT 0,
    extra_hour_price NUMERIC(10,2) DEFAULT 0,
    capacity INT DEFAULT 1,
);

-- GUESTS
CREATE TABLE guests (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    branch_id INT REFERENCES branches(id)
);

-- BOOKINGS (the heart of the system)
CREATE TABLE bookings (
    id SERIAL PRIMARY KEY,
    room_id INT NOT NULL REFERENCES rooms(id),
    guest_id INT REFERENCES guests(id),
    user_id INT NOT NULL REFERENCES users(id), -- who processed the booking
    check_in TIMESTAMP NOT NULL,
    expected_hours INT NOT NULL,
    check_out TIMESTAMP, -- filled at checkout
    status VARCHAR(20) NOT NULL DEFAULT 'ongoing'
        CHECK (status IN ('ongoing','completed','cancelled')),
    branch_id INT REFERENCES branches(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- INVENTORY CATEGORIES
CREATE TABLE inventory_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL, -- e.g., Housekeeping, Kitchen, Office
    description TEXT
);

-- INVENTORY ITEMS
CREATE TABLE inventory_items (
    id SERIAL PRIMARY KEY,
    category_id INT REFERENCES inventory_categories(id),
    name VARCHAR(100) NOT NULL,
    description TEXT,
    quantity INT NOT NULL DEFAULT 0,
    unit VARCHAR(50) DEFAULT 'pcs', -- pieces, bottles, sets
    branch_id INT REFERENCES branches(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- INVENTORY TRANSACTIONS (logs for stock in/out)
CREATE TABLE inventory_transactions (
    id SERIAL PRIMARY KEY,
    item_id INT REFERENCES inventory_items(id),
    user_id INT REFERENCES users(id),
    action VARCHAR(20) NOT NULL CHECK (action IN ('add','remove','adjust')),
    quantity INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    branch_id INT REFERENCES branches(id)
);


-- SERVICES / ADD-ONS
CREATE TABLE services (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price NUMERIC(10,2) NOT NULL,
    branch_id INT REFERENCES branches(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- BOOKING SERVICES (services used per booking)
CREATE TABLE booking_services (
    id SERIAL PRIMARY KEY,
    booking_id INT NOT NULL REFERENCES bookings(id),
    service_id INT NOT NULL REFERENCES services(id),
    quantity INT NOT NULL DEFAULT 1,
    total_price NUMERIC(10,2) NOT NULL
);

-- BILLING
CREATE TABLE bills (
    id SERIAL PRIMARY KEY,
    booking_id INT UNIQUE NOT NULL REFERENCES bookings(id),
    room_charge NUMERIC(10,2) NOT NULL,
    services_charge NUMERIC(10,2) DEFAULT 0,
    discount NUMERIC(10,2) DEFAULT 0,
    total_amount NUMERIC(10,2) NOT NULL,
    paid_amount NUMERIC(10,2) DEFAULT 0,
    payment_method VARCHAR(50) DEFAULT 'cash',
    branch_id INT REFERENCES branches(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- AUDIT LOG (useful for sync + monitoring)
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    table_name VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    action VARCHAR(20) NOT NULL CHECK (action IN ('insert','update','delete')),
    user_id INT REFERENCES users(id),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    branch_id INT REFERENCES branches(id),
    details TEXT

);

CREATE TABLE payments (
  id SERIAL PRIMARY KEY,
  bill_id INT NOT NULL REFERENCES bills(id),
  amount NUMERIC(10,2) NOT NULL CHECK (amount > 0),
  payment_method VARCHAR(50) NOT NULL,
  user_id INT REFERENCES users(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  branch_id INT REFERENCES branches(id),
  notes TEXT
);
