-- Separate database for the test suite.
--
-- The tests MUST run on MySQL, not SQLite: the central rule of this project is
-- that a billing's updated value is computable in SQL, and the consistency test
-- compares the SQL face of InterestCalculator against the PHP face. On SQLite
-- that test would be validating an engine that is not the production one —
-- POW(), DATEDIFF() and DECIMAL precision all behave differently.
--
-- Kept apart from the development database because RefreshDatabase drops and
-- recreates the schema on every run.
CREATE DATABASE IF NOT EXISTS billing_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON billing_test.* TO 'billing'@'%';
FLUSH PRIVILEGES;
