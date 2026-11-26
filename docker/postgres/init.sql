-- Las extensiones (btree_gist) las crea la migración inicial para que el
-- esquema funcione igual en CI y en PostgreSQL gestionado.
-- Este script solo corre cuando el volumen se inicializa por primera vez.
CREATE DATABASE slotlock_test;
