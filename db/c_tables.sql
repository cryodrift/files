CREATE TABLE IF NOT EXISTS files
(
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    uid         TEXT UNIQUE not NULL,
    name        TEXT,
    path        TEXT,
    fext        TEXT,
    size        TEXT,
    exif        TEXT,
    filedate    TEXT,
    aratio      TEXT,
    width       TEXT,
    height      TEXT,
    orientation TEXT,
    deleted     TEXT,
    changed     NUMERIC,
    created     NUMERIC DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS trigger_control
(
    trigger_update   INTEGER unique,
    trigger_versions INTEGER unique
);

INSERT or replace INTO trigger_control (trigger_update, trigger_versions)
values (1, 1);


