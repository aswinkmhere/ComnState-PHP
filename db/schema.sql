-- SQLite schema
CREATE TABLE users (
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
