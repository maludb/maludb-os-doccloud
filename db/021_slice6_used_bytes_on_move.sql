-- 021 (slice 6, the trash, locks, quotas): db/007's documents_after_move was `AFTER UPDATE OF library_id` — but a move writes folder_id and the BEFORE trigger
-- (dc_documents_before) sets library_id from the new folder, and PostgreSQL fires a column-list trigger only for the columns in the statement's own SET list. So a document
-- moved across libraries left its bytes counted in the library it had left and none in the one it joined (the quota bar and quota_near would lie). The trigger now fires on
-- folder_id too; its function (dc_documents_after_move) is unchanged and does nothing when the library did not change. Found by tests/phase3/slice6/quotas.php.
BEGIN;

DROP TRIGGER IF EXISTS documents_after_move ON documents;
CREATE TRIGGER documents_after_move AFTER UPDATE OF folder_id, library_id ON documents FOR EACH ROW EXECUTE FUNCTION dc_documents_after_move();

COMMIT;
