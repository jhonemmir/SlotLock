-- Habilita la extensión necesaria para EXCLUDE USING gist con tipos no-geométricos (uuid, int).
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
