CREATE TABLE notes
(
    note_id SERIAL PRIMARY KEY,
    create_date INTEGER,
    owner CHARACTER VARYING,
    note_subject CHARACTER VARYING,
    note_body CHARACTER VARYING,
    checks INTEGER DEFAULT 0,
    category CHARACTER VARYING,
    remind INTEGER DEFAULT 0,
    icon CHARACTER VARYING,
    font CHARACTER VARYING,
    color CHARACTER VARYING,
    reminded INTEGER DEFAULT 0,
    position_left REAL,
    position_top REAL,
    position_order INTEGER
);
CREATE INDEX notes_owner ON notes(owner);
CREATE INDEX notes_remind ON notes(remind);
CREATE INDEX notes_reminded ON notes(reminded);
CREATE INDEX notes_category ON notes(category);