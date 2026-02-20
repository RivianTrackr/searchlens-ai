-- ============================================================
-- RivianTrackr: Transfer Logs & Feedback from Legacy Tables
-- ============================================================
-- Transfers data from rv_searchlens_logs and rv_searchlens_feedback
-- into rv_riviantrackr_logs and rv_riviantrackr_feedback.
--
-- Run this in phpMyAdmin (SQL tab) on your WordPress database.
--
-- IMPORTANT: This script assumes the new riviantrackr_* tables
-- already exist (created by the plugin on activation).
-- If they don't exist yet, activate the plugin first.
-- ============================================================

-- -----------------------
-- 1. Transfer Logs
-- -----------------------

INSERT INTO rv_riviantrackr_logs
    (search_query, results_count, ai_success, ai_error, cache_hit, response_time_ms, created_at)
SELECT
    search_query,
    results_count,
    ai_success,
    ai_error,
    cache_hit,
    response_time_ms,
    created_at
FROM rv_searchlens_logs
WHERE created_at NOT IN (
    SELECT created_at FROM rv_riviantrackr_logs
)
ORDER BY created_at ASC;

-- -----------------------
-- 2. Transfer Feedback
-- -----------------------

INSERT IGNORE INTO rv_riviantrackr_feedback
    (search_query, helpful, ip_hash, created_at)
SELECT
    search_query,
    helpful,
    ip_hash,
    created_at
FROM rv_searchlens_feedback;

-- INSERT IGNORE safely skips rows that would violate the
-- unique_vote constraint (search_query + ip_hash), so
-- duplicate votes won't be inserted twice.

-- -----------------------
-- 3. Verify the transfer
-- -----------------------

SELECT 'rv_searchlens_logs (source)' AS `table`,  COUNT(*) AS row_count FROM rv_searchlens_logs
UNION ALL
SELECT 'rv_riviantrackr_logs (dest)',              COUNT(*)              FROM rv_riviantrackr_logs
UNION ALL
SELECT 'rv_searchlens_feedback (source)',          COUNT(*)              FROM rv_searchlens_feedback
UNION ALL
SELECT 'rv_riviantrackr_feedback (dest)',          COUNT(*)              FROM rv_riviantrackr_feedback;

-- -----------------------
-- 4. (Optional) Drop old tables after confirming counts match
-- -----------------------
-- Uncomment these lines ONLY after you've verified the row counts above.
--
-- DROP TABLE IF EXISTS rv_searchlens_logs;
-- DROP TABLE IF EXISTS rv_searchlens_feedback;

-- -----------------------
-- 5. (Optional) Update the plugin's DB version so it
--    doesn't try to run its own rename migration.
-- -----------------------
-- UPDATE rv_options SET option_value = '1.4'
--     WHERE option_name = 'riviantrackr_db_version';
