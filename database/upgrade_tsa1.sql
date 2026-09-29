-- Use this ONLY when TSA1 already has users and tasks tables.
-- Back up your database first. Adjust column names to match your TSA1 schema.
ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL;
ALTER TABLE tasks ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
-- Existing users need passwords set by a PHP password_hash() seeder before enforcing NOT NULL.
-- If TSA1 tasks lack user_id, add it and associate each existing task with a user.
-- ALTER TABLE tasks ADD COLUMN user_id INT UNSIGNED NULL;
-- UPDATE tasks SET user_id = 1 WHERE user_id IS NULL;
-- ALTER TABLE tasks MODIFY user_id INT UNSIGNED NOT NULL;
