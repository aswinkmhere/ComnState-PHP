-- SQLite schema
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password TEXT
);

--planned
CREATE TABLE admin (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password TEXT
);

CREATE TABLE routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    name TEXT,
    type TEXT, -- single or double
    status TEXT, -- functional or defunc
    coordinates TEXT, -- JSON of lat/long pairs
    issues TEXT, -- JSON of issue points lat/long
    FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id INTEGER,
    user TEXT,
    lat REAL,
    lng REAL,
    description TEXT,
    time_reported TEXT,
    FOREIGN KEY(route_id) REFERENCES routes(id)
);


------For nodes

-- Master table for nodes
CREATE TABLE nodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    latitude REAL DEFAULT NULL,
    longitude REAL DEFAULT NULL
);

-- Master table for equipment nomenclature
CREATE TABLE eqpt_master (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nomenclature TEXT NOT NULL UNIQUE
);

-- Equipment belonging to a node
CREATE TABLE node_eqpt (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    node_id INTEGER NOT NULL,
    eqpt_id INTEGER NOT NULL,
    serviceable BOOLEAN NOT NULL DEFAULT 1, -- 1 = serviceable, 0 = not
    remark TEXT NULL,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (eqpt_id) REFERENCES eqpt_master(id) ON DELETE CASCADE,
    UNIQUE(node_id, eqpt_id) -- prevents duplicate assignment of same eqpt type to same node
);