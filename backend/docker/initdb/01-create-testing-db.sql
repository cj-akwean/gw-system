-- GW-System — create the test database on first Postgres volume init.
-- Runs only when the pgdata volume is first created (docker-entrypoint-initdb.d).
CREATE DATABASE gw_system_testing;
