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

CREATE UNIQUE INDEX IF NOT EXISTS unique_user_route 
           ON routes(user_id, name);